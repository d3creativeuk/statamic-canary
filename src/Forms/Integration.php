<?php

namespace D3Creative\Canary\Forms;

use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Addon;
use Throwable;

/**
 * A form listener. It can tell Canary about itself through plain methods, so
 * the site's code never depends on Canary:
 *
 *   canaryForms(): array                                 form handle => fields it reads (required)
 *   canaryName(): string                                 e.g. 'MailerLite'
 *   canaryCheck(string $form): void                      a read-only API call that throws on failure
 *   canaryChecks(string $form): array                    name => callable, for a listener with several services
 *   canaryEmails(string $form, Submission $s): array     the emails it builds, as $form->email() returns them
 *   canaryContact(string $form, string $email): ?string  the contact's ID in the account, or null
 *   canaryDelete(string $form, string $id): void         deletes that contact
 *
 * The last two are needed for --subscribe. Without canaryForms(), Canary reads
 * the listener's code instead (ListenerSource), which can't give an API check.
 * A listener with canaryEmails() and no name or check only builds emails, so
 * it isn't listed as an integration.
 */
class Integration
{
    public const EVENTS = [FormSubmitted::class, SubmissionCreated::class];

    public function __construct(
        public readonly string $class,
        public readonly ?string $event = null,
        public readonly string $method = 'handle',
    ) {}

    private ?array $forms = null;

    private ?ListenerSource $source = null;

    /**
     * Listeners registered for a form event, plus any in app/Listeners that
     * aren't, so an unregistered one can be reported instead of missed.
     */
    public static function all(): Collection
    {
        return self::listeners()
            ->filter(fn (self $integration) => $integration->knownForms())
            ->values();
    }

    /**
     * Every class listening for a form event, and every one in app/Listeners,
     * whether or not Canary can tell which forms it handles. Closures are left out.
     */
    public static function listeners(): Collection
    {
        $registered = collect(self::EVENTS)
            ->flatMap(fn ($event) => collect(Event::getRawListeners()[$event] ?? [])
                ->map(fn ($listener) => self::parse($listener, $event))
                ->filter())
            ->keyBy('class');

        // Only ones that mention a form event: app/Listeners also holds listeners for entries and the like.
        $unregistered = collect(self::appListeners())
            ->reject(fn ($class) => $registered->has($class))
            ->map(fn ($class) => new self($class))
            ->filter(fn (self $integration) => $integration->configured() || $integration->source()->listensForForms());

        return $registered->values()->merge($unregistered)->values();
    }

    /**
     * How many closures listen for a form event. Canary can't read them.
     */
    public static function closures(): int
    {
        return collect(self::EVENTS)
            ->flatMap(fn ($event) => Event::getRawListeners()[$event] ?? [])
            ->filter(fn ($listener) => $listener instanceof Closure)
            ->count();
    }

    /**
     * The Canary methods the listener has, such as ['canaryForms', 'canaryCheck'].
     */
    public function methods(): array
    {
        return array_values(array_filter(
            ['canaryForms', 'canaryName', 'canaryCheck', 'canaryChecks', 'canaryEmails', 'canaryContact', 'canaryDelete'],
            fn ($method) => method_exists($this->class, $method),
        ));
    }

    /**
     * The forms it handles, or [] when Canary can't tell or the listener can't be read.
     */
    public function knownFormHandles(): array
    {
        return array_keys($this->knownForms());
    }

    /**
     * Form listeners whose forms Canary can't find, from their methods or their
     * code. A closure is listed as null.
     */
    public static function unknown(): Collection
    {
        return collect(self::EVENTS)
            ->flatMap(fn ($event) => collect(Event::getRawListeners()[$event] ?? [])
                ->map(fn ($listener) => self::parse($listener, $event)))
            // A listener with canaryForms() is never unknown, so returning [] opts it out.
            ->reject(fn (?self $integration) => $integration?->configured() || $integration?->knownForms())
            ->reject(fn (?self $integration) => $integration && $integration->source()->isValidator())
            ->map(fn (?self $integration) => $integration?->class)
            ->unique()
            ->values();
    }

    /**
     * Whether a class is installed by Composer, so the site can't add methods to it.
     */
    public static function fromPackage(string $class): bool
    {
        return self::packageOf($class) !== null;
    }

    /**
     * The Composer package a class is installed from, such as d3creative/statamic-acorn.
     */
    private static function packageOf(string $class): ?string
    {
        // Keyed by each vendor directory. Herd preloads its own loader, so ClassLoader's own path can't be used.
        // findFile() keeps the vendor path of a symlinked path repository, which Reflection resolves away.
        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            $file = $loader->findFile($class);
            $vendor = self::withoutDotSegments($vendor).'/';

            if ($file && str_starts_with($file = self::withoutDotSegments($file), $vendor)) {
                return implode('/', array_slice(explode('/', substr($file, strlen($vendor))), 0, 2));
            }
        }

        return null;
    }

    public function package(): ?string
    {
        return self::packageOf($this->class);
    }

    /**
     * The Statamic addon the listener comes from, if it's in one.
     */
    public function addon(): ?string
    {
        return ($package = $this->package()) ? Addon::get($package)?->name() : null;
    }

    /**
     * Composer maps paths as vendor/composer/../.., so collapse those without resolving symlinks.
     */
    private static function withoutDotSegments(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            match ($part) {
                '..' => array_pop($parts),
                '.' => null,
                default => $parts[] = $part,
            };
        }

        return implode('/', $parts);
    }

    public function name(): string
    {
        if (method_exists($this->class, 'canaryName')) {
            return $this->listener()->canaryName();
        }

        if ($package = $this->package()) {
            return $this->addon() ?? $package;
        }

        return $this->configured() ? class_basename($this->class) : ($this->source()->service() ?? class_basename($this->class));
    }

    /**
     * Whether the listener builds the form's emails and tells Canary about them.
     */
    public function buildsEmails(): bool
    {
        return method_exists($this->class, 'canaryEmails');
    }

    /**
     * Whether it builds emails Canary can't see, because it has no canaryEmails().
     */
    public function buildsHiddenEmails(): bool
    {
        return ! $this->buildsEmails() && $this->source()->buildsEmails();
    }

    /**
     * Builds emails and does nothing Canary can check, so it isn't an integration.
     */
    public function onlyBuildsEmails(): bool
    {
        return $this->buildsEmails() && ! $this->hasApiCheck() && ! method_exists($this->class, 'canaryName');
    }

    /**
     * The emails it builds for an unsaved submission, as $form->email() returns them.
     */
    public function emails(string $form, Submission $submission): array
    {
        $emails = $this->listener()->canaryEmails($form, $submission) ?? [];

        return isset($emails['to']) ? [$emails] : array_values($emails);
    }

    public function source(): ListenerSource
    {
        return $this->source ??= ListenerSource::fromClass($this->class);
    }

    /**
     * Form handle => fields the listener reads.
     */
    public function forms(): array
    {
        return $this->forms ??= $this->configured()
            ? collect($this->listener()->canaryForms())
                ->mapWithKeys(fn ($fields, $form) => is_int($form) ? [$fields => []] : [$form => Arr::wrap($fields)])
                ->all()
            : $this->source()->forms();
    }

    public function handles(string $form): bool
    {
        return array_key_exists($form, $this->knownForms());
    }

    /**
     * Whether the listener has the Canary methods, rather than Canary reading its code.
     */
    public function configured(): bool
    {
        return method_exists($this->class, 'canaryForms');
    }

    public function hasApiCheck(): bool
    {
        return method_exists($this->class, 'canaryCheck') || $this->hasChecks();
    }

    /**
     * Whether it checks several services itself, which takes precedence over canaryCheck().
     */
    public function hasChecks(): bool
    {
        return method_exists($this->class, 'canaryChecks');
    }

    /**
     * Service name => a read-only check that throws on failure, or returns a warning.
     */
    public function checks(string $form): array
    {
        return array_filter($this->listener()->canaryChecks($form) ?? [], 'is_callable');
    }

    /**
     * Why --subscribe would be unsafe. It runs the whole listener, so a listener
     * that sends to several services would add the test contact to each, and
     * canaryDelete() only removes it from one.
     */
    public function subscribeRefusal(string $form): ?string
    {
        if ($this->hasChecks() && count($names = array_keys($this->checks($form))) > 1) {
            return 'it has checks for '.Arr::join($names, ', ', ' and ');
        }

        if (count($services = $this->source()->services()) > 1) {
            return 'its code names '.Arr::join($services, ', ', ' and ');
        }

        return null;
    }

    /**
     * Whether forms it isn't known to handle may still reach it, through an else
     * branch or a default arm. Only for listeners Canary reads the code of.
     */
    public function mayHandleOthers(): bool
    {
        return ! $this->configured() && $this->source()->mayHandleOthers();
    }

    public function registered(): bool
    {
        return $this->event !== null;
    }

    public function canSubscribe(): bool
    {
        return method_exists($this->class, 'canaryContact') && method_exists($this->class, 'canaryDelete');
    }

    private function knownForms(): array
    {
        try {
            return $this->forms();
        } catch (Throwable) {
            return [];
        }
    }

    public function listener(): object
    {
        return app($this->class);
    }

    /**
     * Runs this listener alone, not the event, so the site's other listeners don't run.
     *
     * Jobs it dispatches run straight away on the sync queue, so the contact exists
     * before Canary checks for it and deletes it. Returns the connections of any job
     * that forced a real queue anyway, since that contact arrives after Canary has looked.
     */
    public function run(TestSubmission $submission): array
    {
        $default = config('queue.default');
        $queued = [];
        $running = true;

        // Dispatchers can't forget one listener, so this one switches itself off.
        Event::listen(JobQueued::class, function (JobQueued $event) use (&$queued, &$running) {
            if ($running) {
                $queued[] = $event->connectionName;
            }
        });

        config(['queue.default' => 'sync']);

        try {
            $this->listener()->{$this->method}(new ($this->event)($submission));
        } finally {
            config(['queue.default' => $default]);
            $running = false;
        }

        return array_values(array_unique($queued));
    }

    /**
     * Listeners are registered as 'Class', 'Class@method' or [Class, 'method'].
     * Closures can't have the Canary methods, so they're skipped.
     */
    private static function parse(mixed $listener, string $event): ?self
    {
        [$class, $method] = match (true) {
            is_string($listener) => array_pad(explode('@', $listener, 2), 2, null),
            is_array($listener) && is_string($listener[0] ?? null) => [$listener[0], $listener[1] ?? null],
            default => [null, null],
        };

        if (! $class || ! class_exists($class)) {
            return null;
        }

        return new self($class, $event, $method ?? (method_exists($class, 'handle') ? 'handle' : '__invoke'));
    }

    private static function appListeners(): array
    {
        $path = app_path('Listeners');

        if (! File::isDirectory($path)) {
            return [];
        }

        try {
            $namespace = app()->getNamespace();
        } catch (Throwable) {
            return [];
        }

        return collect(File::allFiles($path))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $namespace.'Listeners\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
            ->filter(fn ($class) => class_exists($class) && ! (new ReflectionClass($class))->isAbstract())
            ->values()
            ->all();
    }
}

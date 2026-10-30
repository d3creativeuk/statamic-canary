<?php

namespace D3Creative\Canary\Forms;

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Throwable;

/**
 * A form listener. It can tell Canary about itself through plain methods, so
 * the site's code never depends on Canary:
 *
 *   canaryForms(): array                                 form handle => fields it reads (required)
 *   canaryName(): string                                 e.g. 'MailerLite'
 *   canaryCheck(string $form): void                      a read-only API call that throws on failure
 *   canaryContact(string $form, string $email): ?string  the contact's ID in the account, or null
 *   canaryDelete(string $form, string $id): void         deletes that contact
 *
 * The last two are needed for --subscribe. Without canaryForms(), Canary reads
 * the listener's code instead (ListenerSource), which can't give an API check.
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

    /**
     * Listeners registered for a form event, plus any in app/Listeners that
     * aren't, so an unregistered one can be reported instead of missed.
     */
    public static function all(): Collection
    {
        $registered = collect(self::EVENTS)
            ->flatMap(fn ($event) => collect(Event::getRawListeners()[$event] ?? [])
                ->map(fn ($listener) => self::parse($listener, $event))
                ->filter())
            ->keyBy('class');

        $unregistered = collect(self::appListeners())
            ->reject(fn ($class) => $registered->has($class))
            ->map(fn ($class) => new self($class));

        return $registered->values()
            ->merge($unregistered)
            ->filter(fn (self $integration) => $integration->knownForms())
            ->values();
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
            ->reject(fn (?self $integration) => $integration?->knownForms())
            ->map(fn (?self $integration) => $integration?->class)
            ->unique()
            ->values();
    }

    /**
     * Whether a class is installed by Composer, so the site can't add methods to it.
     */
    public static function fromPackage(string $class): bool
    {
        // Keyed by each vendor directory. Herd preloads its own loader, so ClassLoader's own path can't be used.
        // findFile() keeps the vendor path of a symlinked path repository, which Reflection resolves away.
        return collect(ClassLoader::getRegisteredLoaders())
            ->contains(fn (ClassLoader $loader, string $vendor) => ($file = $loader->findFile($class))
                && str_starts_with(self::withoutDotSegments($file), self::withoutDotSegments($vendor).'/'));
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

        return $this->configured() ? class_basename($this->class) : (ListenerSource::fromClass($this->class)->service() ?? class_basename($this->class));
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
            : ListenerSource::fromClass($this->class)->forms();
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
        return method_exists($this->class, 'canaryCheck');
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
     */
    public function run(TestSubmission $submission): void
    {
        $this->listener()->{$this->method}(new ($this->event)($submission));
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

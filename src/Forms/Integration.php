<?php

namespace D3Creative\Canary\Forms;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Throwable;

/**
 * A form listener that tells Canary about itself through plain methods, so the
 * site's code never depends on Canary:
 *
 *   canaryForms(): array                                 form handle => fields it reads (required)
 *   canaryName(): string                                 e.g. 'MailerLite'
 *   canaryCheck(string $form): void                      a read-only API call that throws on failure
 *   canaryContact(string $form, string $email): ?string  the contact's ID in the account, or null
 *   canaryDelete(string $form, string $id): void         deletes that contact
 *
 * Only canaryForms() is required. The last two are needed for --subscribe.
 */
class Integration
{
    public const EVENTS = [FormSubmitted::class, SubmissionCreated::class];

    public function __construct(
        public readonly string $class,
        public readonly ?string $event = null,
        public readonly string $method = 'handle',
    ) {}

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
            ->filter(fn (self $integration) => method_exists($integration->class, 'canaryForms'))
            ->keyBy('class');

        $unregistered = collect(self::appListeners())
            ->reject(fn ($class) => $registered->has($class))
            ->filter(fn ($class) => method_exists($class, 'canaryForms'))
            ->map(fn ($class) => new self($class));

        return $registered->values()->merge($unregistered);
    }

    public function name(): string
    {
        return method_exists($this->class, 'canaryName')
            ? $this->listener()->canaryName()
            : class_basename($this->class);
    }

    public function forms(): array
    {
        return collect($this->listener()->canaryForms())
            ->mapWithKeys(fn ($fields, $form) => is_int($form) ? [$fields => []] : [$form => Arr::wrap($fields)])
            ->all();
    }

    public function handles(string $form): bool
    {
        try {
            return array_key_exists($form, $this->forms());
        } catch (Throwable) {
            return false;
        }
    }

    public function registered(): bool
    {
        return $this->event !== null;
    }

    public function canSubscribe(): bool
    {
        return method_exists($this->class, 'canaryContact') && method_exists($this->class, 'canaryDelete');
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

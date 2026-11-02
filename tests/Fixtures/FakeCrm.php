<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Statamic\Events\FormSubmitted;
use Throwable;

/**
 * Stands in for a listener like MailerLiteSubscriber, with an in-memory CRM.
 */
class FakeCrm
{
    /** @var array<string, string> email => contact ID */
    public static array $contacts = [];

    public static array $received = [];

    public static array $checked = [];

    public static array $deleted = [];

    public static ?string $failCheck = null;

    public static bool $dropSubmissions = false;

    public static bool $failDelete = false;

    /** Makes canaryCheck() try to write, then swallow the error. */
    public static bool $checkWrites = false;

    /** Add the contact from a job: true for the default connection, or a connection to force. */
    public static bool|string $viaJob = false;

    public static function reset(): void
    {
        static::$contacts = static::$received = static::$checked = static::$deleted = [];
        static::$failCheck = null;
        static::$dropSubmissions = static::$failDelete = static::$viaJob = static::$checkWrites = false;
    }

    public function handle(FormSubmitted $event): void
    {
        if (! array_key_exists($event->submission->form()->handle(), $this->canaryForms())) {
            return;
        }

        static::$received[] = $event->submission->data()->all();

        // Real listeners often save the submission, e.g. to store a status.
        $event->submission->set('crm_status', 'created')->save();

        if (static::$viaJob) {
            $job = AddToFakeCrm::dispatch($event->submission->get('business_email'));

            if (is_string(static::$viaJob)) {
                $job->onConnection(static::$viaJob);
            }
        } elseif (! static::$dropSubmissions) {
            static::$contacts[$event->submission->get('business_email')] = 'contact-1';
        }
    }

    public function canaryName(): string
    {
        return 'FakeCRM';
    }

    public function canaryForms(): array
    {
        return ['contact' => ['business_email'], 'newsletter' => ['business_email']];
    }

    public function canaryCheck(string $form): void
    {
        static::$checked[] = $form;

        if (static::$checkWrites) {
            try {
                Http::post('https://accounts.fakecrm.test/oauth/token');
            } catch (Throwable) {
                // Carries on as if the check passed.
            }
        }

        if (static::$failCheck) {
            throw new RuntimeException(static::$failCheck);
        }
    }

    public function canaryContact(string $form, string $email): ?string
    {
        return static::$contacts[$email] ?? null;
    }

    public function canaryDelete(string $form, string $id): void
    {
        if (static::$failDelete) {
            throw new RuntimeException('API down.');
        }

        static::$deleted[] = $id;
        static::$contacts = array_filter(static::$contacts, fn ($contact) => $contact !== $id);
    }
}

<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Statamic\Events\SubmissionCreated;

/**
 * Sends each submission to two services, like Outernet's Mailchimp and Zoho listener.
 */
class MultiServiceCrm
{
    public static bool $handled = false;

    /** What the Zoho check does: pass, warn, fail or write. */
    public static string $zoho = 'pass';

    public static function reset(): void
    {
        static::$handled = false;
        static::$zoho = 'pass';
    }

    public function handle(SubmissionCreated $event): void
    {
        static::$handled = true;
    }

    public function canaryForms(): array
    {
        return ['contact' => ['business_email']];
    }

    public function canaryChecks(string $form): array
    {
        return [
            'Mailchimp' => fn () => null,
            'Zoho' => fn () => match (static::$zoho) {
                'warn' => 'disabled in Globals',
                'fail' => throw new RuntimeException('Zoho Access Token is invalid'),
                'write' => Http::post('https://accounts.zoho.test/oauth/v2/token'),
                default => null,
            },
        ];
    }

    public function canaryContact(string $form, string $email): ?string
    {
        return null;
    }

    public function canaryDelete(string $form, string $id): void {}
}

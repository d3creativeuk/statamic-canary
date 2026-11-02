<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Statamic\Events\SubmissionCreated;

/**
 * Declares one service but its code sends to two, so --subscribe must refuse.
 */
class MailchimpWithZohoLead
{
    public static bool $handled = false;

    public function handle(SubmissionCreated $event): void
    {
        static::$handled = true;
        // AddToMailchimpAudience::dispatch($event->submission); CreateZohoLead::dispatch($event->submission);
        $jobs = ['AddToMailchimpAudience', 'CreateZohoLead'];
    }

    public function canaryName(): string
    {
        return 'Mailchimp';
    }

    public function canaryForms(): array
    {
        return ['contact' => ['business_email']];
    }

    public function canaryCheck(string $form): void {}

    public function canaryContact(string $form, string $email): ?string
    {
        return null;
    }

    public function canaryDelete(string $form, string $id): void {}
}

<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Closure;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\SubmissionCreated;

/**
 * Shaped like Outernet's listener: the forms have no emails in their settings,
 * and this builds them in code, routing staff emails by the interests ticked.
 */
class HandleFormSubmissions
{
    public static bool $handled = false;

    public static string $downloadTo = 'downloads@outer.test';

    /** Runs inside canaryEmails(), for testing what Canary allows there. */
    public static ?Closure $whileBuilding = null;

    public static array $rules = [
        ['interest' => 'events', 'to' => 'events@outer.test'],
        ['interest' => 'sales', 'to' => 'sales@outer.test'],
    ];

    public static function reset(): void
    {
        static::$handled = false;
        static::$downloadTo = 'downloads@outer.test';
        static::$whileBuilding = null;
    }

    public function handle(SubmissionCreated $event): void
    {
        static::$handled = true;

        $form = $event->submission->form();
        $form->email($this->emails($form->handle(), $event->submission));
        $event->submission->save();
    }

    public function canaryForms(): array
    {
        return ['enquiry' => ['email', 'interests'], 'download' => ['email']];
    }

    public function canaryEmails(string $form, Submission $submission): array
    {
        if (static::$whileBuilding) {
            (static::$whileBuilding)();
        }

        return $this->emails($form, $submission);
    }

    private function emails(string $form, Submission $submission): array
    {
        return match ($form) {
            'enquiry' => [
                ...collect(static::$rules)
                    ->filter(fn ($rule) => in_array($rule['interest'], (array) $submission->get('interests')))
                    ->map(fn ($rule) => $this->email($rule['to'], 'New enquiry'))
                    ->values(),
                $this->email($submission->get('email'), 'Thanks for your enquiry'),
            ],
            'download' => [$this->email(static::$downloadTo, 'Download requested')],
            default => [],
        };
    }

    private function email(?string $to, string $subject): array
    {
        return ['to' => $to, 'from' => 'site@outer.test', 'subject' => $subject, 'html' => 'emails/contact'];
    }
}

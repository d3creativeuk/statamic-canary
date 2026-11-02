<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Statamic\Events\SubmissionCreated;

/**
 * Builds a form's emails in code without telling Canary about them.
 */
class BuildsQuoteEmails
{
    public function handle(SubmissionCreated $event): void
    {
        if ($event->submission->form()->handle() === 'quote') {
            $event->submission->form()->email([['to' => 'quotes@outer.test', 'subject' => 'New quote']]);
        }
    }
}

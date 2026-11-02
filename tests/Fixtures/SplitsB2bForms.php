<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Illuminate\Support\Str;
use Statamic\Events\SubmissionCreated;

/**
 * Picks its forms by prefix and sends the rest down an else branch, as Outernet's does.
 */
class SplitsB2bForms
{
    public function handle(SubmissionCreated $event): void
    {
        $handle = $event->submission->form()->handle();

        if (Str::startsWith($handle, 'b2b_')) {
            $this->business($event);
        } else {
            $this->consumer($event);
        }
    }

    private function business(SubmissionCreated $event): void {}

    private function consumer(SubmissionCreated $event): void {}
}

<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Statamic\Events\FormSubmitted;

/**
 * A listener without the Canary methods, written the way lbf-com's are.
 */
class ContactToActiveCampaign
{
    public static array $received = [];

    public function handle(FormSubmitted $event): void
    {
        if ($event->submission->form()->handle() == 'contact') {
            // $event->submission->old_field was renamed.
            static::$received[] = [
                'email' => $event->submission->business_email,
                'name' => $event->submission->get('full_name'),
            ];
        }
    }
}

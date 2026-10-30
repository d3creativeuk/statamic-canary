<?php

namespace Acme\Crm;

use Statamic\Events\FormSubmitted;

/**
 * A package's listener, loaded through a symlinked vendor folder as a path
 * repository installs it.
 */
class SyncToAcme
{
    public function handle(FormSubmitted $event): void
    {
        if ($event->submission->form()->handle() !== 'contact') {
            return;
        }
    }
}

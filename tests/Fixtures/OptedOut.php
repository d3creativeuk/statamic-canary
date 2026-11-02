<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Statamic\Events\FormSubmitted;

/**
 * A form listener the site has told Canary to leave alone, by listing no forms.
 */
class OptedOut
{
    public function handle(FormSubmitted $event): void {}

    public function canaryForms(): array
    {
        return [];
    }
}

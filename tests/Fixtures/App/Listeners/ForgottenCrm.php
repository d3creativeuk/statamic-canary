<?php

namespace D3Creative\Canary\Tests\Fixtures\App\Listeners;

use Statamic\Events\FormSubmitted;

/**
 * Has the Canary methods but isn't registered for any event.
 */
class ForgottenCrm
{
    public function handle(FormSubmitted $event): void {}

    public function canaryName(): string
    {
        return 'ForgottenCRM';
    }

    public function canaryForms(): array
    {
        return ['newsletter'];
    }
}

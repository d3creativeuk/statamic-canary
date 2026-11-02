<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Illuminate\Validation\ValidationException;
use Statamic\Events\FormSubmitted;

/**
 * Rejects a submission rather than sending it anywhere, like Outernet's listener.
 */
class ValidateRecaptcha
{
    public function handle(FormSubmitted $event): void
    {
        if (! request('g-recaptcha-response')) {
            throw ValidationException::withMessages(['recaptcha' => 'Please confirm you are not a robot.']);
        }
    }
}

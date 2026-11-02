<?php

namespace D3Creative\Canary\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Adds the contact from a job, as lbf-com's listeners do with ActiveCampaign.
 */
class AddToFakeCrm implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $email) {}

    public function handle(): void
    {
        FakeCrm::$contacts[$this->email] = 'contact-1';
    }
}

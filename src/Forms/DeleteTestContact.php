<?php

namespace D3Creative\Canary\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes a --submit test contact once the integration's automations have had
 * time to send. Only the listener's class is stored, so the job serialises
 * without the listener's own dependencies.
 */
class DeleteTestContact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    public $backoff = [60, 300];

    public function __construct(
        public string $listener,
        public string $form,
        public string $id,
    ) {}

    public function handle(): void
    {
        app($this->listener)->canaryDelete($this->form, $this->id);
    }
}

<?php

namespace D3Creative\Canary\Tests\Fixtures\App\Listeners;

/**
 * An ordinary listener without the Canary methods, which Canary ignores.
 */
class PlainListener
{
    public function handle(): void {}
}

<?php

namespace D3Creative\Canary;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/canary.php', 'canary');
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/canary.php' => config_path('canary.php'),
        ], 'canary-config');
    }
}

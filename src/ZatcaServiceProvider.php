<?php

namespace Arkhan\Zatca;

use Illuminate\Support\ServiceProvider;

class ZatcaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

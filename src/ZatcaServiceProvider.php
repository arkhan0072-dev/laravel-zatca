<?php

namespace Arkhan\Zatca;

use Illuminate\Support\ServiceProvider;
use Saleh7\Zatca\ZatcaAPI;

class ZatcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zatca.php', 'zatca');
        $this->app->bind(ZatcaAPI::class, fn () => new ZatcaAPI(Zatca::environment()));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/zatca.php' => config_path('zatca.php')], 'zatca-config');
        $this->commands([Console\SubmitPendingCommand::class]);
    }
}

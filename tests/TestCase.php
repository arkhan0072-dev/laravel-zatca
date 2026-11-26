<?php

namespace Arkhan\Zatca\Tests;

use Arkhan\Ledger\LedgerServiceProvider;
use Arkhan\Zatca\ZatcaServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        // laravel-ledger is optional: loaded for the recipe that books invoices in it, when installed.
        return class_exists(LedgerServiceProvider::class)
            ? [ZatcaServiceProvider::class, LedgerServiceProvider::class]
            : [ZatcaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // DB_CONNECTION=pgsql or mysql in CI runs the same tests against those databases.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
        // Unit keys and secrets are encrypted with the app key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}

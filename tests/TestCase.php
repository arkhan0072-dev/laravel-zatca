<?php

namespace Arkhan\Zatca\Tests;

use Arkhan\Zatca\ZatcaServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [ZatcaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // DB_CONNECTION=pgsql or mysql in CI runs the same tests against those databases.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }
}

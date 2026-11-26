<?php

namespace Arkhan\Zatca\Tests;

use Arkhan\Zatca\ZatcaServiceProvider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Orchestra\Testbench\TestCase as Orchestra;

/** For tests whose data other processes must see: migrated and committed, not rolled back per test. */
abstract class ConcurrencyTestCase extends Orchestra
{
    use DatabaseMigrations;

    protected function getPackageProviders($app): array
    {
        return [ZatcaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }
}

<?php

use Arkhan\Zatca\ZatcaServiceProvider;
use Illuminate\Support\ServiceProvider;

it('defaults to ZATCA\'s sandbox', function () {
    expect(config('zatca.environment'))->toBe('sandbox');
});

it('lets the app publish the config file', function () {
    $paths = ServiceProvider::pathsToPublish(ZatcaServiceProvider::class, 'zatca-config');

    expect(array_values($paths))->toBe([config_path('zatca.php')])
        ->and(require array_key_first($paths))->toHaveKey('environment');
});

<?php

// One of several processes appending to the same unit's chain at once: php worker.php <unit> <count>
require __DIR__.'/../../vendor/autoload.php';

use Arkhan\Zatca\Chain;
use Arkhan\Zatca\Link;
use Arkhan\Zatca\SignedInvoice;
use Arkhan\Zatca\ZatcaServiceProvider;
use Illuminate\Support\Str;
use Orchestra\Testbench\Foundation\Application;

$app = Application::create();
$app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
$app->register(ZatcaServiceProvider::class);

[, $unit, $count] = $argv;
$chain = $app->make(Chain::class);

try {
    for ($i = 0; $i < (int) $count; $i++) {
        $chain->append($unit, function (Link $link) {
            usleep(random_int(0, 3000)); // widen the window in which another worker could read the same link
            $xml = "<Invoice><ICV>{$link->counter}</ICV><PIH>{$link->previousHash}</PIH></Invoice>";

            return new SignedInvoice((string) Str::uuid(), base64_encode(hash('sha256', $xml, true)), $xml);
        });
    }
} catch (Throwable $e) {
    // Laravel's exception handler would exit 0, hiding the failure from the test.
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}

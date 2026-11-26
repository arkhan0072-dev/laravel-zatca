<?php

use Arkhan\Zatca\Chain;
use Arkhan\Zatca\Models\Invoice;
use Illuminate\Support\Facades\DB;

it('gives concurrent processes one unbroken chain', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Needs a database server shared by several processes: run it on PostgreSQL or MySQL.');
    }
    $workers = 4;
    $each = 20;

    $processes = [];
    for ($w = 0; $w < $workers; $w++) {
        $processes[] = proc_open([PHP_BINARY, __DIR__.'/worker.php', 'pos-1', (string) $each], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outputs[] = $pipes;
    }
    $failures = [];
    foreach ($processes as $w => $process) {
        $output = stream_get_contents($outputs[$w][1]).stream_get_contents($outputs[$w][2]);
        if (proc_close($process) !== 0) {
            $failures[] = "worker {$w}: ".trim($output);
        }
    }

    $invoices = Invoice::where('unit', 'pos-1')->orderBy('counter')->get();

    expect($failures)->toBe([])
        ->and($invoices->pluck('counter')->all())->toBe(range(1, $workers * $each))
        ->and($invoices->first()->previous_hash)->toBe(Chain::FIRST_PREVIOUS_HASH);
    foreach ($invoices->skip(1) as $i => $invoice) {
        expect($invoice->previous_hash)->toBe($invoices[$i - 1]->hash, "invoice {$invoice->counter} is not linked to the one before");
    }
});

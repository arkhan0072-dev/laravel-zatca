<?php

use Arkhan\Zatca\Onboarding;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Zatca;

// Runs the whole flow against ZATCA's real developer sandbox with its public test OTP and sample
// taxpayer data. Opt-in, so CI never depends on ZATCA being up: ZATCA_SANDBOX=1 vendor/bin/pest tests/Integration
beforeEach(function () {
    if (! env('ZATCA_SANDBOX')) {
        $this->markTestSkipped('Set ZATCA_SANDBOX=1 to run against ZATCA\'s sandbox.');
    }
});

it('onboards a unit, then issues, reports and clears invoices', function () {
    $onboarding = app(Onboarding::class);

    $onboarding->start('sandbox-1', details(), '123345');
    $unit = $onboarding->complete('sandbox-1', [Invoices::standard(), Invoices::simplified()]);

    $zatca = app(Zatca::class);
    $first = $zatca->issue('sandbox-1', Invoices::simplified());
    $second = $zatca->issue('sandbox-1', Invoices::standard());

    $reported = $zatca->submit($first)->fresh();
    $cleared = $zatca->submit($second)->fresh();

    expect($unit->status)->toBe('active')
        ->and([$first->counter, $second->counter])->toBe([1, 2])
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($reported->status)->toBe('reported')
        ->and($cleared->status)->toBe('cleared')
        ->and($cleared->cleared_xml)->toContain('<cbc:UUID>'.$second->uuid.'</cbc:UUID>');

    $credit = $zatca->submit($zatca->creditNote($reported, [...Invoices::simplified(), 'id' => 'CN-1'], 'Customer returned the pen'))->fresh();
    $debit = $zatca->submit($zatca->debitNote($cleared, [...Invoices::standard(), 'id' => 'DN-1'], 'Delivery charge was not invoiced'))->fresh();

    expect($credit->status)->toBe('reported', json_encode($credit->zatca_response))
        ->and($debit->status)->toBe('cleared', json_encode($debit->zatca_response));

    $renewed = $onboarding->renew('sandbox-1', '123345');
    $afterRenewal = $zatca->submit($zatca->issue('sandbox-1', Invoices::simplified()))->fresh();

    expect($renewed->private_key)->not->toBe($unit->private_key)
        ->and($afterRenewal->status)->toBe('reported', json_encode($afterRenewal->zatca_response));
});

it('keeps the chain going after ZATCA rejects an invoice, as its guideline requires', function () {
    $onboarding = app(Onboarding::class);
    $onboarding->start('sandbox-2', details(), '123345');
    $onboarding->complete('sandbox-2', [Invoices::standard(), Invoices::simplified()]);
    $zatca = app(Zatca::class);
    $wrongTotal = fn (array $data) => array_replace_recursive($data, ['legalMonetaryTotal' => ['taxInclusiveAmount' => 999.99, 'payableAmount' => 999.99]]);

    $rejectedSale = $zatca->submit($zatca->issue('sandbox-2', [...$wrongTotal(Invoices::simplified()), 'id' => 'INV-9']))->fresh();
    $rejectedB2b = $zatca->submit($zatca->issue('sandbox-2', $wrongTotal(Invoices::standard())))->fresh();
    $corrected = $zatca->submit($zatca->issue('sandbox-2', Invoices::standard()))->fresh();
    $cancelled = $zatca->submit($zatca->creditNote($rejectedSale, [...Invoices::simplified(), 'id' => 'CN-9'], 'Cancels INV-9, which ZATCA did not report'))->fresh();

    expect([$rejectedSale->status, $rejectedB2b->status])->toBe(['rejected', 'rejected'])
        ->and($rejectedB2b->zatca_response['validationResults']['errorMessages'][0]['code'])->toBe('BR-CO-15')
        ->and($corrected->previous_hash)->toBe($rejectedB2b->hash)
        ->and($corrected->status)->toBe('cleared', json_encode($corrected->zatca_response))
        ->and($cancelled->status)->toBe('reported', json_encode($cancelled->zatca_response));
});

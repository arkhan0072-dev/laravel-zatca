<?php

use Arkhan\Zatca\Onboarding;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Zatca;
use Saleh7\Zatca\ZatcaAPI;

// Runs the whole flow against ZATCA's real developer sandbox with its public test OTP and sample
// taxpayer data. Opt-in, so CI never depends on ZATCA being up: ZATCA_SANDBOX=1 vendor/bin/pest tests/Integration
beforeEach(function () {
    if (! env('ZATCA_SANDBOX')) {
        $this->markTestSkipped('Set ZATCA_SANDBOX=1 to run against ZATCA\'s sandbox.');
    }
});

it('onboards a unit and issues invoices that pass ZATCA\'s checks', function () {
    $onboarding = app(Onboarding::class);

    $onboarding->start('sandbox-1', details(), '123345');
    $unit = $onboarding->complete('sandbox-1', [Invoices::standard(), Invoices::simplified()]);

    $zatca = app(Zatca::class);
    $first = $zatca->issue('sandbox-1', Invoices::simplified());
    $second = $zatca->issue('sandbox-1', Invoices::standard());

    $check = app(ZatcaAPI::class)->validateInvoiceCompliance($unit->compliance_certificate, $unit->compliance_secret, $second->xml, $second->hash, $second->uuid);

    expect($unit->status)->toBe('active')
        ->and([$first->counter, $second->counter])->toBe([1, 2])
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($check->getValidationStatus())->toBeIn(['PASS', 'WARNING'])
        ->and($check->getErrorMessages())->toBe([]);
});

<?php

use Arkhan\Zatca\Exceptions\SubmissionException;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Onboarding;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Zatca;
use PHPUnit\Framework\AssertionFailedError;

/** Onboard 'pos-1' against the fake, as an app's test would. */
function onboarded(): void
{
    app(Onboarding::class)->start('pos-1', details(), '123345');
    app(Onboarding::class)->complete('pos-1', [Invoices::standard(), Invoices::simplified()]);
}

it('onboards a unit without calling ZATCA', function () {
    Zatca::fake();

    onboarded();

    expect(Unit::where('unit', 'pos-1')->value('status'))->toBe('active');
});

it('reports and clears invoices, and records what was sent', function () {
    $fake = Zatca::fake();
    onboarded();
    $zatca = app(Zatca::class);

    $reported = $zatca->submit($zatca->issue('pos-1', Invoices::simplified()))->fresh();
    $cleared = $zatca->submit($zatca->issue('pos-1', Invoices::standard()))->fresh();

    expect($reported->status)->toBe('reported')
        ->and($cleared->status)->toBe('cleared')
        ->and($cleared->cleared_xml)->toBe($cleared->xml);
    $fake->assertReported($reported);
    $fake->assertCleared($cleared);
    $fake->assertSubmittedCount(2);
});

it('rejects the next invoice with the reason you give', function () {
    $fake = Zatca::fake();
    onboarded();
    $zatca = app(Zatca::class);

    $fake->rejectNext('BR-KSA-37', 'The seller address building number must contain 4 digits.');
    $rejected = $zatca->submit($zatca->issue('pos-1', Invoices::simplified()))->fresh();
    $next = $zatca->submit($zatca->issue('pos-1', Invoices::simplified()))->fresh();

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->zatca_response['validationResults']['errorMessages'][0]['code'])->toBe('BR-KSA-37')
        ->and($next->status)->toBe('reported');
});

it('simulates an outage until it is over', function () {
    $fake = Zatca::fake();
    onboarded();
    $zatca = app(Zatca::class);
    $invoice = $zatca->issue('pos-1', Invoices::simplified());

    $fake->outage();
    expect(fn () => $zatca->submit($invoice))->toThrow(SubmissionException::class, '(HTTP 503)');

    $fake->outage(false);
    expect($zatca->submit($invoice)->fresh()->status)->toBe('reported');
});

it('fails assertions that do not hold', function () {
    $fake = Zatca::fake();
    onboarded();
    $invoice = app(Zatca::class)->issue('pos-1', Invoices::simplified());

    $fake->assertNothingSubmitted();
    expect(fn () => $fake->assertReported($invoice))->toThrow(AssertionFailedError::class, "Invoice {$invoice->uuid} was not reported.");

    app(Zatca::class)->submit($invoice);
    expect(fn () => $fake->assertNothingSubmitted())->toThrow(AssertionFailedError::class, '1 invoice(s) were submitted to ZATCA.')
        ->and(fn () => $fake->assertCleared($invoice))->toThrow(AssertionFailedError::class, "Invoice {$invoice->uuid} was not cleared.");
});

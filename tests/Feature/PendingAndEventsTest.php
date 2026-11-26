<?php

use Arkhan\Zatca\Events\InvoiceCleared;
use Arkhan\Zatca\Events\InvoiceRejected;
use Arkhan\Zatca\Events\InvoiceReported;
use Arkhan\Zatca\Exceptions\SubmissionException;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Tests\Fixtures\TestCertificate;
use Arkhan\Zatca\Zatca;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Unit::create([
        'unit' => 'pos-1', 'environment' => 'sandbox', 'status' => 'active',
        'private_key' => TestCertificate::privateKey(),
        'compliance_certificate' => 'c', 'compliance_secret' => 's', 'compliance_request_id' => '1',
        'certificate' => TestCertificate::make()->getRawCertificate(), 'secret' => 'production-secret',
    ]);
    $this->zatca = app(Zatca::class);
    $this->fake = Zatca::fake();
});

afterEach(fn () => Carbon::setTestNow());

it('sends every pending invoice and reports what happened', function () {
    $this->zatca->issue('pos-1', Invoices::simplified());
    $this->zatca->issue('pos-1', Invoices::simplified());
    $this->zatca->issue('pos-1', Invoices::standard());
    $this->fake->rejectNext('BR-KSA-37', 'Building number must contain 4 digits.');

    $this->artisan('zatca:submit-pending')
        ->expectsOutputToContain('Sent 3 invoice(s): 1 reported, 1 cleared, 1 rejected, 0 still pending.')
        ->assertExitCode(1);

    expect(Invoice::where('status', 'pending')->count())->toBe(0);
});

it('succeeds when everything went through', function () {
    $this->zatca->issue('pos-1', Invoices::simplified());

    $this->artisan('zatca:submit-pending')
        ->expectsOutputToContain('Sent 1 invoice(s): 1 reported, 0 cleared, 0 rejected, 0 still pending.')
        ->assertExitCode(0);
});

it('keeps going through an outage and fails so the scheduler can alert', function () {
    $this->zatca->issue('pos-1', Invoices::simplified());
    $this->zatca->issue('pos-1', Invoices::simplified());
    $this->fake->outage();

    $this->artisan('zatca:submit-pending')
        ->expectsOutputToContain('0 reported, 0 cleared, 0 rejected, 2 still pending.')
        ->assertExitCode(1);
});

it('warns about simplified invoices close to ZATCA\'s 24-hour limit', function () {
    Carbon::setTestNow('2026-11-25 08:00');
    $this->zatca->issue('pos-1', Invoices::simplified());
    $this->zatca->issue('pos-1', Invoices::standard());
    Carbon::setTestNow('2026-11-26 09:00');
    $this->fake->outage();

    $this->artisan('zatca:submit-pending')
        ->expectsOutputToContain('1 simplified invoice(s) are more than 24 hours old; ZATCA expects them within 24 hours of issue (BR-KSA-98).')
        ->assertExitCode(1);
});

it('has nothing to do when nothing is pending', function () {
    $this->artisan('zatca:submit-pending')->expectsOutputToContain('Nothing to send.')->assertExitCode(0);
});

it('announces each outcome once, after it is saved', function () {
    Event::fake([InvoiceReported::class, InvoiceCleared::class, InvoiceRejected::class]);
    $reported = $this->zatca->issue('pos-1', Invoices::simplified());
    $cleared = $this->zatca->issue('pos-1', Invoices::standard());
    $rejected = $this->zatca->issue('pos-1', Invoices::simplified());

    $this->zatca->submit($reported);
    $this->zatca->submit($reported);
    $this->zatca->submit($cleared);
    $this->fake->rejectNext('BR-KSA-37', 'Building number must contain 4 digits.');
    $this->zatca->submit($rejected);

    Event::assertDispatchedTimes(InvoiceReported::class, 1);
    Event::assertDispatched(InvoiceReported::class, fn (InvoiceReported $e) => $e->invoice->is($reported) && $e->invoice->status === 'reported');
    Event::assertDispatched(InvoiceCleared::class, fn (InvoiceCleared $e) => $e->invoice->is($cleared));
    Event::assertDispatched(InvoiceRejected::class, fn (InvoiceRejected $e) => $e->invoice->is($rejected) && $e->reasons === ['BR-KSA-37: Building number must contain 4 digits.']);
});

it('announces nothing during an outage', function () {
    Event::fake([InvoiceReported::class, InvoiceCleared::class, InvoiceRejected::class]);
    $invoice = $this->zatca->issue('pos-1', Invoices::simplified());
    $this->fake->outage();

    try {
        $this->zatca->submit($invoice);
    } catch (SubmissionException) {
    }

    Event::assertNothingDispatched();
});

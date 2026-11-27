<?php

use Arkhan\Zatca\Exceptions\SubmissionException;
use Arkhan\Zatca\Jobs\SubmitInvoice;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Tests\Fixtures\TestCertificate;
use Arkhan\Zatca\Zatca;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

function validation(string $status, array $warnings = [], array $errors = []): array
{
    return ['infoMessages' => [], 'warningMessages' => $warnings, 'errorMessages' => $errors, 'status' => $status];
}

beforeEach(function () {
    Unit::create([
        'unit' => 'pos-1', 'environment' => 'sandbox', 'status' => 'active',
        'private_key' => TestCertificate::privateKey(),
        'compliance_certificate' => 'c', 'compliance_secret' => 's', 'compliance_request_id' => '1',
        'certificate' => TestCertificate::make()->getRawCertificate(), 'secret' => 'production-secret',
    ]);
    $this->zatca = app(Zatca::class);
    $this->simplified = $this->zatca->issue('pos-1', Invoices::simplified());
    $this->standard = $this->zatca->issue('pos-1', Invoices::standard());
});

it('starts every issued invoice as pending', function () {
    expect($this->simplified->fresh()->status)->toBe('pending');
});

it('reports a simplified invoice with the unit\'s production certificate', function () {
    $sent = fakeZatca(zatcaJson(200, ['validationResults' => validation('PASS'), 'reportingStatus' => 'REPORTED']));

    $invoice = $this->zatca->submit($this->simplified);

    $request = $sent[0]['request'];
    $body = json_decode((string) $request->getBody(), true);
    expect($request->getUri()->getPath())->toEndWith('/invoices/reporting/single')
        ->and($request->getHeaderLine('Clearance-Status'))->toBe('0')
        ->and($request->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode(base64_encode(TestCertificate::make()->getRawCertificate()).':production-secret'))
        ->and($body['uuid'])->toBe($this->simplified->uuid)
        ->and($body['invoiceHash'])->toBe($this->simplified->hash)
        ->and(base64_decode($body['invoice']))->toBe($this->simplified->xml)
        ->and($invoice->fresh())
        ->status->toBe('reported')
        ->attempts->toBe(1)
        ->submitted_at->not->toBeNull()
        ->and($invoice->fresh()->zatca_response['reportingStatus'])->toBe('REPORTED');
});

it('clears a standard invoice and keeps ZATCA\'s stamped copy', function () {
    $stamped = '<Invoice><Stamped/></Invoice>';
    $sent = fakeZatca(zatcaJson(200, ['validationResults' => validation('PASS'), 'clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode($stamped)]));

    $invoice = $this->zatca->submit($this->standard);

    expect($sent[0]['request']->getUri()->getPath())->toEndWith('/invoices/clearance/single')
        ->and($sent[0]['request']->getHeaderLine('Clearance-Status'))->toBe('1')
        ->and($invoice->fresh())->status->toBe('cleared')->cleared_xml->toBe($stamped);
});

it('accepts an invoice reported with warnings and keeps them', function () {
    fakeZatca(zatcaJson(202, ['validationResults' => validation('WARNING', [['code' => 'BR-KSA-98', 'message' => 'The simplified invoice should be submitted within 24 hours of issuing the invoice.']]), 'reportingStatus' => 'REPORTED']));

    $invoice = $this->zatca->submit($this->simplified)->fresh();

    expect($invoice->status)->toBe('reported')
        ->and($invoice->zatca_response['validationResults']['warningMessages'][0]['code'])->toBe('BR-KSA-98');
});

it('sends an invoice to ZATCA only once', function () {
    $sent = fakeZatca(zatcaJson(200, ['validationResults' => validation('PASS'), 'reportingStatus' => 'REPORTED']));

    $this->zatca->submit($this->simplified);
    $this->zatca->submit($this->simplified);
    $this->zatca->submit(Invoice::find($this->simplified->id));

    expect($sent)->toHaveCount(1)->and($this->simplified->fresh()->attempts)->toBe(1);
});

it('treats ZATCA\'s "already reported" as reported', function () {
    fakeZatca(zatcaJson(409, ['validationResults' => validation('ERROR', [], [['code' => null, 'message' => 'Invoice was already Reported successfully earlier.']]), 'reportingStatus' => 'NOT_REPORTED']));

    expect($this->zatca->submit($this->simplified)->fresh()->status)->toBe('reported');
});

it('marks a rejected invoice and keeps ZATCA\'s reasons, without retrying', function () {
    $sent = fakeZatca(zatcaJson(400, ['validationResults' => validation('ERROR', [], [['code' => 'BR-KSA-37', 'message' => 'The seller address building number must contain 4 digits.']]), 'reportingStatus' => 'NOT_REPORTED']));

    $invoice = $this->zatca->submit($this->simplified)->fresh();
    $this->zatca->submit($invoice);

    expect($invoice->status)->toBe('rejected')
        ->and($invoice->zatca_response['validationResults']['errorMessages'][0]['code'])->toBe('BR-KSA-37')
        ->and($sent)->toHaveCount(1);
});

it('links the next invoice to a rejected one and never reuses its counter (ZATCA guideline, pages 48 and 71)', function () {
    fakeZatca(zatcaJson(400, ['validationResults' => validation('ERROR', [], [['code' => 'BR-CO-15', 'message' => 'Invoice total amount with VAT (BT-112)']]), 'reportingStatus' => 'NOT_REPORTED']));
    $rejected = $this->zatca->submit($this->standard)->fresh();

    $next = $this->zatca->issue('pos-1', Invoices::standard());

    expect($rejected->status)->toBe('rejected')
        ->and($next->counter)->toBe($rejected->counter + 1)
        ->and($next->previous_hash)->toBe($rejected->hash);
});

it('reports a standard invoice when ZATCA has turned clearance off (HTTP 303)', function () {
    $sent = fakeZatca(
        zatcaJson(303, ['message' => 'Clearance is deactivated. Please use the /invoices/reporting/single endpoint instead.']),
        zatcaJson(200, ['validationResults' => validation('PASS'), 'reportingStatus' => 'REPORTED']),
    );

    $invoice = $this->zatca->submit($this->standard)->fresh();

    expect($sent)->toHaveCount(2)
        ->and($sent[1]['request']->getUri()->getPath())->toEndWith('/invoices/reporting/single')
        ->and($sent[1]['request']->getHeaderLine('Clearance-Status'))->toBe('0')
        ->and($invoice)->status->toBe('reported')->cleared_xml->toBeNull()->attempts->toBe(1);
});

it('leaves the invoice pending for a retry when ZATCA is unavailable', function (Response $response) {
    fakeZatca($response);

    expect(fn () => $this->zatca->submit($this->simplified))->toThrow(SubmissionException::class, 'ZATCA could not take invoice '.$this->simplified->uuid.' (HTTP');
    expect($this->simplified->fresh())->status->toBe('pending')->attempts->toBe(1);
})->with([
    'server error' => [new Response(500, ['Content-Type' => 'application/json'], '{"code":"500","message":"Something went wrong"}')],
    'unauthorised' => [new Response(401, ['Content-Type' => 'application/json'], '{"status":401,"error":"Unauthorized"}')],
]);

it('retries after an outage and then reports', function () {
    fakeZatca(new Response(503, [], ''), zatcaJson(200, ['validationResults' => validation('PASS'), 'reportingStatus' => 'REPORTED']));

    expect(fn () => $this->zatca->submit($this->simplified))->toThrow(SubmissionException::class);

    expect($this->zatca->submit($this->simplified)->fresh())->status->toBe('reported')->attempts->toBe(2);
});

it('submits from a queued job that ZATCA outages retry', function () {
    fakeZatca(zatcaJson(200, ['validationResults' => validation('PASS'), 'reportingStatus' => 'REPORTED']));
    $job = new SubmitInvoice($this->simplified);

    app()->call([$job, 'handle']);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBeGreaterThan(1)
        ->and($job->backoff())->toBe([60, 300, 900, 3600])
        ->and($this->simplified->fresh()->status)->toBe('reported');
});

it('locks the invoice while it is sent, so two workers cannot both send it', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite allows one writer at a time and has no row locks.');
    }
    fakeZatca(zatcaJson(200, ['validationResults' => validation('PASS'), 'reportingStatus' => 'REPORTED']));
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $this->zatca->submit($this->simplified);

    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'zatca_invoices') && str_contains($sql, 'for update')))->toBeTrue();
});

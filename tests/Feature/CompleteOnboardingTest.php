<?php

use Arkhan\Zatca\Exceptions\OnboardingException;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Onboarding;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Tests\Fixtures\TestCertificate;
use Arkhan\Zatca\Zatca;
use GuzzleHttp\Psr7\Response;

function passed(): Response
{
    return zatcaJson(200, ['validationResults' => ['infoMessages' => [], 'warningMessages' => [], 'errorMessages' => [], 'status' => 'PASS'], 'reportingStatus' => 'REPORTED']);
}

function productionCertificate(): Response
{
    return zatcaJson(200, ['requestID' => 30368, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => base64_encode(TestCertificate::make()->getRawCertificate()), 'secret' => 'production-secret']);
}

beforeEach(function () {
    $this->unit = Unit::create([
        'unit' => 'pos-1', 'environment' => 'sandbox', 'status' => 'compliance',
        'private_key' => TestCertificate::privateKey(),
        'compliance_certificate' => TestCertificate::make()->getRawCertificate(),
        'compliance_secret' => 'compliance-secret',
        'compliance_request_id' => '1234567890123',
    ]);
});

it('passes the compliance checks, then stores the production certificate', function () {
    $sent = fakeZatca(passed(), passed(), productionCertificate());

    $unit = app(Onboarding::class)->complete('pos-1', [Invoices::simplified(), Invoices::simplified()]);

    $paths = array_map(fn ($t) => $t['request']->getUri()->getPath(), $sent->getArrayCopy());
    $auth = 'Basic '.base64_encode(base64_encode(TestCertificate::make()->getRawCertificate()).':compliance-secret');
    expect($paths)->toBe(['/e-invoicing/developer-portal/compliance/invoices', '/e-invoicing/developer-portal/compliance/invoices', '/e-invoicing/developer-portal/production/csids'])
        ->and($sent[0]['request']->getHeaderLine('Authorization'))->toBe($auth)
        ->and(json_decode((string) $sent[2]['request']->getBody(), true))->toBe(['compliance_request_id' => '1234567890123'])
        ->and($unit->fresh())
        ->status->toBe('active')
        ->certificate->toBe(TestCertificate::make()->getRawCertificate())
        ->secret->toBe('production-secret');
});

it('chains the sample documents like real invoices', function () {
    $sent = fakeZatca(passed(), passed(), productionCertificate());

    app(Onboarding::class)->complete('pos-1', [Invoices::simplified(), Invoices::simplified()]);

    $second = xpath(base64_decode(json_decode((string) $sent[1]['request']->getBody(), true)['invoice']));
    $firstHash = json_decode((string) $sent[0]['request']->getBody(), true)['invoiceHash'];
    expect($second->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID)"))->toBe('2')
        ->and($second->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject)"))->toBe($firstHash);
});

it('stops at the first failed compliance check and keeps the unit in compliance', function () {
    $sent = fakeZatca(passed(), zatcaJson(400, ['validationResults' => ['errorMessages' => [['code' => 'BR-KSA-37', 'message' => 'The seller address building number must contain 4 digits.']], 'status' => 'ERROR'], 'reportingStatus' => 'NOT_REPORTED']));

    expect(fn () => app(Onboarding::class)->complete('pos-1', [Invoices::simplified(), Invoices::simplified(), Invoices::simplified()]))
        ->toThrow(OnboardingException::class, 'ZATCA rejected compliance sample 2 (HTTP 400): BR-KSA-37: The seller address building number must contain 4 digits.');
    expect($sent)->toHaveCount(2)->and($this->unit->fresh()->status)->toBe('compliance');
});

it('only completes a unit that is waiting for its compliance checks', function (string $unit, string $message) {
    $this->unit->update(['status' => 'active']);

    expect(fn () => app(Onboarding::class)->complete($unit, [Invoices::simplified()]))->toThrow(OnboardingException::class, $message);
})->with([
    ['pos-9', 'Unit pos-9 has not been onboarded.'],
    ['pos-1', 'Unit pos-1 is already active.'],
]);

it('needs at least one compliance sample', function () {
    app(Onboarding::class)->complete('pos-1', []);
})->throws(OnboardingException::class, 'Pass at least one sample document for the compliance checks.');

it('issues invoices with the unit\'s own production certificate', function () {
    fakeZatca(passed(), productionCertificate());
    app(Onboarding::class)->complete('pos-1', [Invoices::simplified()]);

    $invoice = app(Zatca::class)->issue('pos-1', Invoices::simplified());

    expect($invoice->counter)->toBe(1)
        ->and(xpath($invoice->xml)->evaluate('string(//ds:X509Certificate)'))->toBe(TestCertificate::make()->getRawCertificate());
});

it('refuses to issue for a unit without a production certificate', function () {
    app(Zatca::class)->issue('pos-1', Invoices::simplified());
})->throws(OnboardingException::class, 'Unit pos-1 has no production certificate yet; complete its onboarding first.');

it('keeps the unit in compliance when ZATCA refuses the production certificate', function () {
    fakeZatca(passed(), zatcaJson(400, ['errors' => [['code' => 'Invalid-Request-ID', 'message' => 'Compliance request ID is invalid']]]));

    expect(fn () => app(Onboarding::class)->complete('pos-1', [Invoices::simplified()]))
        ->toThrow(OnboardingException::class, 'ZATCA refused the production certificate request (HTTP 400): Invalid-Request-ID: Compliance request ID is invalid');
    expect($this->unit->fresh())->status->toBe('compliance')->certificate->toBeNull();
});

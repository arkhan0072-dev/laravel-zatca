<?php

use Arkhan\Zatca\Exceptions\OnboardingException;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Onboarding;
use Arkhan\Zatca\Tests\Fixtures\TestCertificate;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\DB;
use Saleh7\Zatca\ZatcaAPI;

beforeEach(function () {
    // ZATCA returns the certificate as base64 of the base64 DER.
    $this->token = base64_encode(TestCertificate::make()->getRawCertificate());
});

it('requests a compliance certificate and stores the unit', function () {
    $sent = fakeZatca(zatcaJson(200, ['requestID' => 1234567890123, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => $this->token, 'secret' => 'compliance-secret']));

    $unit = app(Onboarding::class)->start('pos-1', details(), '123345');

    $request = $sent[0]['request'];
    expect((string) $request->getUri())->toEndWith('/developer-portal/compliance')
        ->and($request->getHeaderLine('OTP'))->toBe('123345')
        ->and($request->getHeaderLine('Accept-Version'))->toBe('V2')
        ->and($unit->fresh())
        ->unit->toBe('pos-1')
        ->environment->toBe('sandbox')
        ->status->toBe('compliance')
        ->compliance_request_id->toBe('1234567890123')
        ->compliance_certificate->toBe(TestCertificate::make()->getRawCertificate())
        ->compliance_secret->toBe('compliance-secret');
});

it('sends a CSR for the unit\'s VAT number and keeps its private key', function () {
    $sent = fakeZatca(zatcaJson(200, ['requestID' => 1, 'binarySecurityToken' => $this->token, 'secret' => 's']));

    $unit = app(Onboarding::class)->start('pos-1', details(), '123345');

    $csr = base64_decode(json_decode((string) $sent[0]['request']->getBody(), true)['csr']);
    $subject = openssl_csr_get_subject($csr);
    expect($csr)->toStartWith('-----BEGIN CERTIFICATE REQUEST-----')
        ->and($subject['CN'])->toBe('TST-886431145-399999999900003')
        ->and($subject['O'])->toBe('Maximum Speed Tech Supply')
        ->and(openssl_pkey_get_details(openssl_pkey_get_public(openssl_csr_get_public_key($csr)))['key'])
        ->toBe(openssl_pkey_get_details(openssl_pkey_get_private($unit->private_key))['key']);
});

it('encrypts the private key and secret at rest', function () {
    fakeZatca(zatcaJson(200, ['requestID' => 1, 'binarySecurityToken' => $this->token, 'secret' => 'compliance-secret']));

    $unit = app(Onboarding::class)->start('pos-1', details(), '123345');
    $row = DB::table('zatca_units')->where('id', $unit->id)->first();

    expect($row->private_key)->not->toContain('PRIVATE KEY')
        ->and($row->compliance_secret)->not->toBe('compliance-secret')
        ->and($unit->fresh()->private_key)->toContain('PRIVATE KEY');
});

it('reports ZATCA\'s reason and stores nothing when the OTP is refused', function () {
    fakeZatca(zatcaJson(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid']]]));

    expect(fn () => app(Onboarding::class)->start('pos-1', details(), '111111'))
        ->toThrow(OnboardingException::class, 'ZATCA refused the compliance certificate request (HTTP 400): Invalid-OTP: The provided OTP is invalid');
    expect(Unit::count())->toBe(0);
});

it('refuses to onboard a unit twice', function () {
    fakeZatca(zatcaJson(200, ['requestID' => 1, 'binarySecurityToken' => $this->token, 'secret' => 's']));
    app(Onboarding::class)->start('pos-1', details(), '123345');

    app(Onboarding::class)->start('pos-1', details(), '123345');
})->throws(OnboardingException::class, 'Unit pos-1 is already onboarded.');

it('catches a second onboarding of the same unit that races the first', function () {
    $token = $this->token;
    $sent = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler([function () use ($token) {
        // Another request finishes onboarding pos-1 while this one waits for ZATCA.
        Unit::create(['unit' => 'pos-1', 'environment' => 'sandbox', 'status' => 'compliance', 'private_key' => 'k', 'compliance_certificate' => 'c', 'compliance_secret' => 's', 'compliance_request_id' => '1']);

        return zatcaJson(200, ['requestID' => 2, 'binarySecurityToken' => $token, 'secret' => 's']);
    }]));
    $stack->push(Middleware::history($sent));
    app()->instance(ZatcaAPI::class, new ZatcaAPI('sandbox', new Client(['handler' => $stack])));

    expect(fn () => app(Onboarding::class)->start('pos-1', details(), '123345'))->toThrow(OnboardingException::class, 'Unit pos-1 is already onboarded.');
    expect(Unit::count())->toBe(1);
});

it('refuses an unknown environment setting', function () {
    config(['zatca.environment' => 'staging']);

    app(Onboarding::class);
})->throws(InvalidArgumentException::class, "zatca.environment must be sandbox, simulation or production, got 'staging'.");

<?php

use Arkhan\Zatca\Exceptions\OnboardingException;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Onboarding;
use Arkhan\Zatca\Testing\FakeCertificate;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Zatca;
use Illuminate\Support\Carbon;

/** A unit onboarded through the package, so its details are stored. */
function activeUnit(): Unit
{
    Zatca::fake();
    app(Onboarding::class)->start('pos-1', details(), '123345');

    return app(Onboarding::class)->complete('pos-1', [Invoices::simplified()]);
}

afterEach(fn () => Carbon::setTestNow());

it('keeps the unit\'s details from onboarding', function () {
    $unit = activeUnit();

    expect($unit->fresh()->details)->toMatchArray(['vatNumber' => '399999999900003', 'commonName' => 'TST-886431145-399999999900003']);
});

it('renews the production certificate with a new key and the stored details', function () {
    $unit = activeUnit();
    $oldKey = $unit->private_key;
    $sent = fakeZatca(zatcaJson(200, ['requestID' => 9, 'binarySecurityToken' => base64_encode(FakeCertificate::pair()['certificate']), 'secret' => 'renewed-secret']));

    $renewed = app(Onboarding::class)->renew('pos-1', '654321');

    $request = $sent[0]['request'];
    $csr = base64_decode(json_decode((string) $request->getBody(), true)['csr']);
    expect($request->getMethod())->toBe('PATCH')
        ->and($request->getUri()->getPath())->toEndWith('/production/csids')
        ->and($request->getHeaderLine('OTP'))->toBe('654321')
        ->and($request->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode(base64_encode($unit->certificate).':'.$unit->secret))
        ->and(openssl_csr_get_subject($csr)['CN'])->toBe('TST-886431145-399999999900003')
        ->and($renewed->fresh())
        ->secret->toBe('renewed-secret')
        ->private_key->not->toBe($oldKey)
        ->status->toBe('active');
});

it('renews a unit onboarded before details were stored, given its details', function () {
    $unit = activeUnit();
    $unit->update(['details' => null]);

    expect(fn () => app(Onboarding::class)->renew('pos-1', '654321'))
        ->toThrow(OnboardingException::class, 'Unit pos-1 has no stored details; pass them to renew().');
    expect(app(Onboarding::class)->renew('pos-1', '654321', details())->fresh()->details['vatNumber'])->toBe('399999999900003');
});

it('only renews an active unit', function () {
    Zatca::fake();
    app(Onboarding::class)->start('pos-1', details(), '123345');

    app(Onboarding::class)->renew('pos-1', '654321');
})->throws(OnboardingException::class, 'Unit pos-1 has no production certificate to renew; complete its onboarding first.');

it('keeps the current certificate when ZATCA refuses the renewal', function () {
    $unit = activeUnit();
    fakeZatca(zatcaJson(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid']]]));

    expect(fn () => app(Onboarding::class)->renew('pos-1', '000000'))
        ->toThrow(OnboardingException::class, 'ZATCA refused the certificate renewal (HTTP 400): Invalid-OTP: The provided OTP is invalid');
    expect($unit->fresh())->secret->toBe($unit->secret)->private_key->toBe($unit->private_key);
});

it('reads when the production certificate expires', function () {
    $unit = activeUnit();
    $expected = openssl_x509_parse("-----BEGIN CERTIFICATE-----\n".chunk_split(FakeCertificate::pair()['certificate'], 64, "\n")."-----END CERTIFICATE-----\n")['validTo_time_t'];

    expect($unit->certificateExpiresAt()?->timestamp)->toBe($expected)
        ->and(Unit::create([...$unit->only(['environment', 'status', 'private_key', 'compliance_certificate', 'compliance_secret', 'compliance_request_id']), 'unit' => 'pos-2'])->certificateExpiresAt())->toBeNull();
});

it('warns from the scheduled command when a certificate expires within 30 days', function () {
    $unit = activeUnit();
    Carbon::setTestNow($unit->certificateExpiresAt()->subDays(10));

    $this->artisan('zatca:submit-pending')
        ->expectsOutputToContain('The certificate of unit pos-1 expires on '.$unit->certificateExpiresAt()->toDateString().'; renew it with Onboarding::renew().')
        ->assertExitCode(0);
});

<?php

namespace Arkhan\Zatca;

use Arkhan\Zatca\Exceptions\OnboardingException;
use Arkhan\Zatca\Models\Unit;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;
use Saleh7\Zatca\CertificateBuilder;
use Saleh7\Zatca\Exceptions\ZatcaApiException;
use Saleh7\Zatca\Helpers\Certificate;
use Saleh7\Zatca\ZatcaAPI;

/** Registers an EGS unit with ZATCA: key pair, certificate request, compliance certificate. */
class Onboarding
{
    private const CSR_ENVIRONMENT = [
        'sandbox' => CertificateBuilder::ENV_NONPROD,
        'simulation' => CertificateBuilder::ENV_SIMULATION,
        'production' => CertificateBuilder::ENV_PRODUCTION,
    ];

    public function __construct(
        private readonly ZatcaAPI $api,
        private readonly Signer $signer,
    ) {}

    /**
     * Pass ZATCA's compliance checks with sample documents signed by the compliance certificate,
     * then exchange it for the unit's production certificate. ZATCA expects a sample of each kind
     * of document the unit will issue (for 1100: standard and simplified invoices, credit and debit notes).
     *
     * @param  list<array<string, mixed>>  $samples  invoice data, as for Zatca::issue()
     */
    public function complete(string $unit, array $samples): Unit
    {
        if ($samples === []) {
            throw new OnboardingException('Pass at least one sample document for the compliance checks.');
        }
        $model = Unit::where('unit', $unit)->first() ?? throw new OnboardingException("Unit {$unit} has not been onboarded.");
        if ($model->status !== 'compliance') {
            throw new OnboardingException("Unit {$unit} is already active.");
        }
        $certificate = new Certificate($model->compliance_certificate, $model->private_key, $model->compliance_secret);

        // The samples form their own chain, just as real invoices will.
        $previousHash = Chain::FIRST_PREVIOUS_HASH;
        foreach ($samples as $i => $sample) {
            $signed = $this->signer->sign($sample, new Link($i + 1, $previousHash), $certificate);
            try {
                $this->api->validateInvoiceCompliance($model->compliance_certificate, $model->compliance_secret, $signed->xml, $signed->hash, $signed->uuid);
            } catch (ZatcaApiException $e) {
                throw new OnboardingException('ZATCA rejected compliance sample '.($i + 1).' '.$this->reason($e), previous: $e);
            }
            $previousHash = $signed->hash;
        }

        try {
            $production = $this->api->requestProductionCertificate($model->compliance_certificate, $model->compliance_secret, $model->compliance_request_id);
        } catch (ZatcaApiException $e) {
            throw new OnboardingException('ZATCA refused the production certificate request '.$this->reason($e), previous: $e);
        }
        $model->update(['certificate' => $production->getCertificate(), 'secret' => $production->getSecret(), 'status' => 'active']);

        return $model;
    }

    /** Generate the unit's key and CSR, and exchange the CSR and the taxpayer's OTP for a compliance certificate. */
    public function start(string $unit, UnitDetails $details, string $otp): Unit
    {
        if (Unit::where('unit', $unit)->exists()) {
            throw new OnboardingException("Unit {$unit} is already onboarded.");
        }
        [$csr, $privateKey] = $this->certificateRequest($details);

        try {
            $result = $this->api->requestComplianceCertificate($csr, $otp);
        } catch (ZatcaApiException $e) {
            throw new OnboardingException('ZATCA refused the compliance certificate request '.$this->reason($e), previous: $e);
        }

        $attributes = [
            'unit' => $unit,
            'environment' => Zatca::environment(),
            'status' => 'compliance',
            'private_key' => $privateKey,
            'compliance_certificate' => $result->getCertificate(),
            'compliance_secret' => $result->getSecret(),
            'compliance_request_id' => (string) $result->getRequestId(),
            'details' => $details->toArray(),
        ];

        try {
            // Its own transaction: inside the caller's, a savepoint keeps PostgreSQL's transaction usable after a clash.
            return DB::transaction(fn () => Unit::create($attributes));
        } catch (UniqueConstraintViolationException $e) {
            throw new OnboardingException("Unit {$unit} is already onboarded.", previous: $e);
        }
    }

    /**
     * Renew the unit's production certificate before it expires: a new key pair and certificate request
     * with the unit's stored details (or $details), exchanged with the taxpayer's new OTP.
     */
    public function renew(string $unit, string $otp, ?UnitDetails $details = null): Unit
    {
        $model = Unit::where('unit', $unit)->where('status', 'active')->first();
        if ($model === null || $model->certificate === null || $model->secret === null) {
            throw new OnboardingException("Unit {$unit} has no production certificate to renew; complete its onboarding first.");
        }
        $details ??= $model->details === null
            ? throw new OnboardingException("Unit {$unit} has no stored details; pass them to renew().")
            : UnitDetails::fromArray($model->details);
        [$csr, $privateKey] = $this->certificateRequest($details);

        try {
            $result = $this->api->renewProductionCertificate($model->certificate, $model->secret, $csr, $otp);
        } catch (ZatcaApiException $e) {
            throw new OnboardingException('ZATCA refused the certificate renewal '.$this->reason($e), previous: $e);
        }
        $model->update(['private_key' => $privateKey, 'certificate' => $result->getCertificate(), 'secret' => $result->getSecret(), 'details' => $details->toArray()]);

        return $model;
    }

    /** @return array{string, string} the certificate request (PEM) and its new private key (PEM) */
    private function certificateRequest(UnitDetails $details): array
    {
        $builder = (new CertificateBuilder)
            ->setOrganizationIdentifier($details->vatNumber)
            ->setSerialNumber($details->solutionName, $details->model, $details->serialNumber)
            ->setCommonName($details->commonName)
            ->setCountryName('SA')
            ->setOrganizationName($details->organizationName)
            ->setOrganizationalUnitName($details->branchName)
            ->setAddress($details->address)
            ->setInvoiceType($details->invoiceTypes)
            ->setEnvironment(self::CSR_ENVIRONMENT[Zatca::environment()])
            ->setBusinessCategory($details->businessCategory);
        $builder->generate();

        return [$builder->getCsr(), $this->privateKey($builder)];
    }

    /**
     * shortcut: CertificateBuilder can only write the key to a file, and a private key must never touch disk,
     * so read it from the builder in memory; switch to a getter once saleh7/php-zatca-xml offers one.
     */
    private function privateKey(CertificateBuilder $builder): string
    {
        $key = Closure::bind(fn () => $this->privateKey, $builder, CertificateBuilder::class)();
        // An empty config: exporting needs none, and PHP builds without a default openssl.cnf (common on Windows) fail otherwise.
        $emptyConfig = ['config' => PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'];

        // Only fails if saleh7/php-zatca-xml renames its key property; then fail loudly instead of storing no key.
        return $key instanceof OpenSSLAsymmetricKey && openssl_pkey_export($key, $pem, null, $emptyConfig) && is_string($pem)
            ? $pem
            : throw new OnboardingException('Could not read the generated private key.');
    }

    /** "(HTTP 400): Invalid-OTP: The provided OTP is invalid" from ZATCA's error response. */
    private function reason(ZatcaApiException $e): string
    {
        $context = $e->getContext();
        $status = $context['statusCode'] ?? null;
        // Certificate endpoints report "errors"; invoice endpoints report "validationResults.errorMessages".
        $errors = data_get($context, 'response.errors') ?? data_get($context, 'response.validationResults.errorMessages');
        $messages = [];
        foreach (is_array($errors) ? $errors : [] as $error) {
            $code = data_get($error, 'code');
            $message = data_get($error, 'message');
            $messages[] = trim((is_scalar($code) ? (string) $code : '').': '.(is_scalar($message) ? (string) $message : ''), ': ');
        }

        return '(HTTP '.(is_int($status) ? $status : '?').'): '.($messages === [] ? 'no reason given' : implode('; ', $messages));
    }
}

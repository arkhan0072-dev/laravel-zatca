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
use Saleh7\Zatca\ZatcaAPI;

/** Registers an EGS unit with ZATCA: key pair, certificate request, compliance certificate. */
class Onboarding
{
    private const CSR_ENVIRONMENT = [
        'sandbox' => CertificateBuilder::ENV_NONPROD,
        'simulation' => CertificateBuilder::ENV_SIMULATION,
        'production' => CertificateBuilder::ENV_PRODUCTION,
    ];

    public function __construct(private readonly ZatcaAPI $api) {}

    /** Generate the unit's key and CSR, and exchange the CSR and the taxpayer's OTP for a compliance certificate. */
    public function start(string $unit, UnitDetails $details, string $otp): Unit
    {
        if (Unit::where('unit', $unit)->exists()) {
            throw new OnboardingException("Unit {$unit} is already onboarded.");
        }
        $environment = Zatca::environment();

        $builder = (new CertificateBuilder)
            ->setOrganizationIdentifier($details->vatNumber)
            ->setSerialNumber($details->solutionName, $details->model, $details->serialNumber)
            ->setCommonName($details->commonName)
            ->setCountryName('SA')
            ->setOrganizationName($details->organizationName)
            ->setOrganizationalUnitName($details->branchName)
            ->setAddress($details->address)
            ->setInvoiceType($details->invoiceTypes)
            ->setEnvironment(self::CSR_ENVIRONMENT[$environment])
            ->setBusinessCategory($details->businessCategory);
        $builder->generate();

        try {
            $result = $this->api->requestComplianceCertificate($builder->getCsr(), $otp);
        } catch (ZatcaApiException $e) {
            throw new OnboardingException('ZATCA refused the compliance certificate request '.$this->reason($e), previous: $e);
        }

        $attributes = [
            'unit' => $unit,
            'environment' => $environment,
            'status' => 'compliance',
            'private_key' => $this->privateKey($builder),
            'compliance_certificate' => $result->getCertificate(),
            'compliance_secret' => $result->getSecret(),
            'compliance_request_id' => (string) $result->getRequestId(),
        ];

        try {
            // Its own transaction: inside the caller's, a savepoint keeps PostgreSQL's transaction usable after a clash.
            return DB::transaction(fn () => Unit::create($attributes));
        } catch (UniqueConstraintViolationException $e) {
            throw new OnboardingException("Unit {$unit} is already onboarded.", previous: $e);
        }
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
        $errors = data_get($context, 'response.errors');
        $messages = [];
        foreach (is_array($errors) ? $errors : [] as $error) {
            $code = data_get($error, 'code');
            $message = data_get($error, 'message');
            $messages[] = trim((is_scalar($code) ? (string) $code : '').': '.(is_scalar($message) ? (string) $message : ''), ': ');
        }

        return '(HTTP '.(is_int($status) ? $status : '?').'): '.($messages === [] ? 'no reason given' : implode('; ', $messages));
    }
}

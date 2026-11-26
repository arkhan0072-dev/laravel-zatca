<?php

namespace Arkhan\Zatca;

use Arkhan\Zatca\Exceptions\OnboardingException;
use Arkhan\Zatca\Exceptions\SubmissionException;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Testing\ZatcaFake;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Saleh7\Zatca\Api\ClearanceResponse;
use Saleh7\Zatca\Exceptions\ZatcaApiException;
use Saleh7\Zatca\Helpers\Certificate;
use Saleh7\Zatca\ZatcaAPI;

/** The package's entry point: issue invoices into an EGS unit's chain. */
class Zatca
{
    public function __construct(
        private readonly Chain $chain,
        private readonly Signer $signer,
    ) {}

    /** Answer every ZATCA call offline from now on; returns the fake for rejections, outages and assertions. */
    public static function fake(): ZatcaFake
    {
        $fake = new ZatcaFake;
        app()->instance(ZatcaAPI::class, new ZatcaAPI(self::environment(), new Client(['handler' => HandlerStack::create($fake)])));

        return $fake;
    }

    /** The ZATCA environment from config('zatca.environment'): sandbox (default), simulation or production. */
    public static function environment(): string
    {
        $environment = config('zatca.environment', 'sandbox');
        if (! in_array($environment, ['sandbox', 'simulation', 'production'], true)) {
            throw new InvalidArgumentException('zatca.environment must be sandbox, simulation or production, got '.var_export($environment, true).'.');
        }

        return $environment;
    }

    /**
     * Sign an invoice with the unit's production certificate (or $certificate) and add it to the unit's chain.
     *
     * @param  array<string, mixed>  $invoice  data in the shape saleh7/php-zatca-xml's InvoiceMapper accepts
     */
    public function issue(string $unit, array $invoice, ?Certificate $certificate = null): Invoice
    {
        $certificate ??= $this->certificate($unit);

        return $this->chain->append($unit, fn (Link $link) => $this->signer->sign($invoice, $link, $certificate));
    }

    /**
     * Send an invoice to ZATCA exactly once: reporting for simplified (B2C) invoices, clearance for
     * standard (B2B) ones. A rejection is stored with ZATCA's reasons and not sent again; an outage
     * leaves the invoice pending and throws SubmissionException so a retry can send it later.
     */
    public function submit(Invoice $invoice): Invoice
    {
        $outage = null;
        $saved = DB::transaction(function () use ($invoice, &$outage) {
            // The row stays locked during the call to ZATCA, so a second worker waits and then sees the result.
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return $locked;
            }
            $certificate = $this->certificate($locked->unit);
            $standard = $this->isStandard($locked->xml);
            $locked->attempts++;
            $locked->submitted_at = now();

            try {
                $response = $standard
                    ? app(ZatcaAPI::class)->submitClearanceInvoice($certificate->getRawCertificate(), (string) $certificate->getSecretKey(), $locked->xml, $locked->hash, $locked->uuid)
                    : app(ZatcaAPI::class)->submitReportingInvoice($certificate->getRawCertificate(), (string) $certificate->getSecretKey(), $locked->xml, $locked->hash, $locked->uuid);
                $locked->zatca_response = $response->toArray();
                $locked->status = $standard ? 'cleared' : 'reported';
                $locked->cleared_xml = $response instanceof ClearanceResponse ? $response->getDecodedClearedInvoice() : null;
            } catch (ZatcaApiException $e) {
                $status = $e->getContext()['statusCode'] ?? null;
                $body = $e->getContext()['response'] ?? null;
                $locked->zatca_response = is_array($body) ? $body : null;
                match ($status) {
                    409 => $locked->status = $standard ? 'cleared' : 'reported', // ZATCA already has it
                    400 => $locked->status = 'rejected',
                    default => $outage = $e,
                };
            }
            $locked->save();

            return $locked;
        });

        if ($outage !== null) {
            $status = $outage->getContext()['statusCode'] ?? null;
            throw new SubmissionException("ZATCA could not take invoice {$saved->uuid} (HTTP ".(is_int($status) ? $status : 'error').'); it stays pending for a retry.', previous: $outage);
        }

        return $saved;
    }

    /** Standard (B2B) invoices have an InvoiceTypeCode name starting "01", simplified ones "02". */
    private function isStandard(string $xml): bool
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $name = $xpath->evaluate('string(//cbc:InvoiceTypeCode/@name)');

        return is_string($name) && str_starts_with($name, '01');
    }

    private function certificate(string $unit): Certificate
    {
        $model = Unit::where('unit', $unit)->where('status', 'active')->first();
        if ($model === null || $model->certificate === null || $model->secret === null) {
            throw new OnboardingException("Unit {$unit} has no production certificate yet; complete its onboarding first.");
        }

        return new Certificate($model->certificate, $model->private_key, $model->secret);
    }
}

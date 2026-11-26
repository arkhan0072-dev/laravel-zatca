<?php

namespace Arkhan\Zatca;

use Arkhan\Zatca\Exceptions\OnboardingException;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Models\Unit;
use InvalidArgumentException;
use Saleh7\Zatca\Helpers\Certificate;

/** The package's entry point: issue invoices into an EGS unit's chain. */
class Zatca
{
    public function __construct(
        private readonly Chain $chain,
        private readonly Signer $signer,
    ) {}

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

    private function certificate(string $unit): Certificate
    {
        $model = Unit::where('unit', $unit)->where('status', 'active')->first();
        if ($model === null || $model->certificate === null || $model->secret === null) {
            throw new OnboardingException("Unit {$unit} has no production certificate yet; complete its onboarding first.");
        }

        return new Certificate($model->certificate, $model->private_key, $model->secret);
    }
}

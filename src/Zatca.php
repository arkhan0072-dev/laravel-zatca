<?php

namespace Arkhan\Zatca;

use Arkhan\Zatca\Models\Invoice;
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

    /** @param  array<string, mixed>  $invoice  data in the shape saleh7/php-zatca-xml's InvoiceMapper accepts */
    public function issue(string $unit, array $invoice, Certificate $certificate): Invoice
    {
        return $this->chain->append($unit, fn (Link $link) => $this->signer->sign($invoice, $link, $certificate));
    }
}

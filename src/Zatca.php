<?php

namespace Arkhan\Zatca;

use Arkhan\Zatca\Models\Invoice;
use Saleh7\Zatca\Helpers\Certificate;

/** The package's entry point: issue invoices into an EGS unit's chain. */
class Zatca
{
    public function __construct(
        private readonly Chain $chain,
        private readonly Signer $signer,
    ) {}

    /** @param  array<string, mixed>  $invoice  data in the shape saleh7/php-zatca-xml's InvoiceMapper accepts */
    public function issue(string $unit, array $invoice, Certificate $certificate): Invoice
    {
        return $this->chain->append($unit, fn (Link $link) => $this->signer->sign($invoice, $link, $certificate));
    }
}

<?php

namespace Arkhan\Zatca\Events;

use Arkhan\Zatca\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** ZATCA reported the invoice. Fired once, after the result is saved. */
final class InvoiceReported implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Invoice $invoice) {}
}

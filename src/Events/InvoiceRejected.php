<?php

namespace Arkhan\Zatca\Events;

use Arkhan\Zatca\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** ZATCA rejected the invoice. Fired once, after the result is saved; fix the data and issue a new invoice. */
final class InvoiceRejected implements ShouldDispatchAfterCommit
{
    /** @param  list<string>  $reasons  ZATCA's errors as "CODE: message" */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly array $reasons,
    ) {}
}

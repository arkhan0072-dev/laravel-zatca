<?php

namespace Arkhan\Zatca\Jobs;

use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Zatca;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Send an invoice to ZATCA from the queue; an outage is retried with backoff, a rejection is not. */
class SubmitInvoice implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public Invoice $invoice) {}

    /** @return list<int> seconds between attempts; simplified invoices must reach ZATCA within 24 hours */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(Zatca $zatca): void
    {
        $zatca->submit($this->invoice);
    }
}

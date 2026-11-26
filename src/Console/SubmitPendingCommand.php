<?php

namespace Arkhan\Zatca\Console;

use Arkhan\Zatca\Exceptions\SubmissionException;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Zatca;
use Illuminate\Console\Command;

/** Send every pending invoice; schedule it so nothing misses ZATCA's 24-hour window. */
class SubmitPendingCommand extends Command
{
    protected $signature = 'zatca:submit-pending';

    protected $description = 'Send every invoice still pending to ZATCA; fails when any is rejected or still pending';

    public function handle(Zatca $zatca): int
    {
        // An expired certificate stops all invoicing, so warn while there is time to renew.
        foreach (Unit::where('status', 'active')->get() as $unit) {
            $expires = $unit->certificateExpiresAt();
            if ($expires !== null && $expires->lte(now()->addDays(30))) {
                $this->warn("The certificate of unit {$unit->unit} expires on {$expires->toDateString()}; renew it with Onboarding::renew().");
            }
        }

        // Oldest first within each unit, the order they were issued in.
        $pending = Invoice::where('status', 'pending')->orderBy('unit')->orderBy('counter')->get();
        if ($pending->isEmpty()) {
            $this->info('Nothing to send.');

            return self::SUCCESS;
        }

        foreach ($pending as $invoice) {
            try {
                $zatca->submit($invoice);
            } catch (SubmissionException) {
                // ZATCA is unavailable; the invoice stays pending and is counted below.
            }
        }

        $sent = Invoice::whereKey($pending->modelKeys())->get();
        $count = fn (string $status) => $sent->where('status', $status)->count();
        $this->line("Sent {$sent->count()} invoice(s): {$count('reported')} reported, {$count('cleared')} cleared, {$count('rejected')} rejected, {$count('pending')} still pending.");

        $late = $sent->filter(fn (Invoice $i) => $i->status === 'pending' && ! $i->isStandard() && $i->created_at->lte(now()->subDay()))->count();
        if ($late > 0) {
            $this->warn("{$late} simplified invoice(s) are more than 24 hours old; ZATCA expects them within 24 hours of issue (BR-KSA-98).");
        }

        return $count('rejected') + $count('pending') > 0 ? self::FAILURE : self::SUCCESS;
    }
}

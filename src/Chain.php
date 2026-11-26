<?php

namespace Arkhan\Zatca;

use Arkhan\Zatca\Exceptions\ChainException;
use Arkhan\Zatca\Models\Invoice;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Each EGS unit's invoices form a chain: every invoice carries the next counter (ICV) and the
 * hash of the invoice before it (PIH). ZATCA rejects an invoice whose counter or previous hash is
 * wrong, so two invoices built at the same time must never get the same link.
 */
class Chain
{
    /** ZATCA's previous-invoice hash for a unit's first invoice: base64 of the hex SHA-256 of "0". */
    public const FIRST_PREVIOUS_HASH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    /**
     * Add an invoice to $unit's chain. $build receives the invoice's link and returns it signed;
     * it runs while the unit's chain is locked, so concurrent invoices queue instead of sharing a link.
     * If $build or storing fails, nothing is stored and the counter is not used up.
     *
     * @param  Closure(Link): SignedInvoice  $build
     */
    public function append(string $unit, Closure $build): Invoice
    {
        // Three attempts: a deadlock between two first invoices of a new unit is retried; nothing was stored yet.
        return DB::transaction(function () use ($unit, $build) {
            $head = $this->lockedHead($unit);

            $link = new Link((int) $head->counter + 1, $head->last_hash);
            $signed = $build($link);
            $this->validate($signed);

            try {
                $invoice = Invoice::create([
                    'unit' => $unit,
                    'counter' => $link->counter,
                    'uuid' => $signed->uuid,
                    'previous_hash' => $link->previousHash,
                    'hash' => $signed->hash,
                    'xml' => $signed->xml,
                    'qr' => $signed->qr === '' ? null : $signed->qr,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Two unique keys: the UUID, and the unit's counter. The driver's own message (not Laravel's,
                // which repeats the SQL and so always mentions the uuid column) names the key that clashed.
                throw new ChainException(str_contains((string) $e->getPrevious()?->getMessage(), 'uuid')
                    ? "Invoice UUID {$signed->uuid} was already used."
                    : "Counter {$link->counter} of unit {$unit} is already used; the chain head is out of step with the stored invoices.", previous: $e);
            }

            DB::table('zatca_chains')->where('unit', $unit)->update([
                'counter' => $link->counter, 'last_hash' => $signed->hash, 'updated_at' => now(),
            ]);

            return $invoice;
        }, 3);
    }

    /**
     * The unit's chain head, locked for this transaction. It is locked directly, and created only for a
     * unit's first invoice: on MySQL an INSERT IGNORE on an existing row takes a shared lock, and two
     * workers upgrading their shared locks to exclusive ones deadlock.
     *
     * @return object{counter: int|string, last_hash: string}
     */
    private function lockedHead(string $unit): object
    {
        $lock = fn () => DB::table('zatca_chains')->where('unit', $unit)->lockForUpdate()->first();
        $head = $lock();
        if ($head === null) {
            // The primary key settles a race between two first invoices of a new unit.
            DB::table('zatca_chains')->insertOrIgnore([
                'unit' => $unit, 'counter' => 0, 'last_hash' => self::FIRST_PREVIOUS_HASH, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $head = $lock();
        }

        /** @var object{counter: int|string, last_hash: string} $head */
        return $head;
    }

    private function validate(SignedInvoice $signed): void
    {
        $digest = base64_decode($signed->hash, true);
        if ($digest === false || strlen($digest) !== 32) {
            throw new ChainException('The invoice hash must be the base64 of a SHA-256 digest.');
        }
    }
}

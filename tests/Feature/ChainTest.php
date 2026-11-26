<?php

use Arkhan\Zatca\Chain;
use Arkhan\Zatca\Exceptions\ChainException;
use Arkhan\Zatca\Link;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\SignedInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A stand-in for slice 2's signer: any XML, its real ZATCA-style hash (base64 of the raw SHA-256). */
function signed(Link $link, ?string $uuid = null): SignedInvoice
{
    $xml = "<Invoice><ICV>{$link->counter}</ICV><PIH>{$link->previousHash}</PIH></Invoice>";

    return new SignedInvoice($uuid ?? (string) Str::uuid(), base64_encode(hash('sha256', $xml, true)), $xml);
}

beforeEach(fn () => $this->chain = app(Chain::class));

it('starts every unit at counter 1 with ZATCA\'s seed hash', function () {
    $invoice = $this->chain->append('pos-1', fn (Link $link) => signed($link));

    // ZATCA's seed for a unit's first invoice: base64 of the hex SHA-256 of "0".
    expect($invoice->counter)->toBe(1)
        ->and($invoice->previous_hash)->toBe(base64_encode(hash('sha256', '0')))
        ->and(Chain::FIRST_PREVIOUS_HASH)->toBe('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==');
});

it('links each invoice to the hash of the one before', function () {
    $first = $this->chain->append('pos-1', fn (Link $link) => signed($link));
    $second = $this->chain->append('pos-1', fn (Link $link) => signed($link));
    $third = $this->chain->append('pos-1', fn (Link $link) => signed($link));

    expect([$second->counter, $third->counter])->toBe([2, 3])
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($third->previous_hash)->toBe($second->hash);
});

it('keeps a separate chain for each unit', function () {
    $this->chain->append('pos-1', fn (Link $link) => signed($link));
    $this->chain->append('pos-1', fn (Link $link) => signed($link));

    $other = $this->chain->append('pos-2', fn (Link $link) => signed($link));

    expect($other->counter)->toBe(1)->and($other->previous_hash)->toBe(Chain::FIRST_PREVIOUS_HASH);
});

it('stores the signed invoice', function () {
    $invoice = $this->chain->append('pos-1', fn (Link $link) => signed($link, 'b1f0a3c2-6e0b-4f6c-9b5e-2d4b7f0c8a11'));

    expect($invoice->fresh())
        ->unit->toBe('pos-1')
        ->uuid->toBe('b1f0a3c2-6e0b-4f6c-9b5e-2d4b7f0c8a11')
        ->xml->toBe('<Invoice><ICV>1</ICV><PIH>'.Chain::FIRST_PREVIOUS_HASH.'</PIH></Invoice>');
});

it('does not use up a counter when building the invoice fails', function () {
    expect(fn () => $this->chain->append('pos-1', fn () => throw new RuntimeException('signer offline')))
        ->toThrow(RuntimeException::class, 'signer offline');

    expect($this->chain->append('pos-1', fn (Link $link) => signed($link))->counter)->toBe(1)
        ->and(Invoice::count())->toBe(1);
});

it('refuses a hash that is not a base64 SHA-256', function (string $hash) {
    $this->chain->append('pos-1', fn (Link $link) => new SignedInvoice((string) Str::uuid(), $hash, '<Invoice/>'));
})->with(['', 'not-base64!', base64_encode('too short')])
    ->throws(ChainException::class, 'The invoice hash must be the base64 of a SHA-256 digest.');

it('refuses a UUID that was already used', function () {
    $this->chain->append('pos-1', fn (Link $link) => signed($link, 'b1f0a3c2-6e0b-4f6c-9b5e-2d4b7f0c8a11'));

    expect(fn () => $this->chain->append('pos-1', fn (Link $link) => signed($link, 'b1f0a3c2-6e0b-4f6c-9b5e-2d4b7f0c8a11')))
        ->toThrow(ChainException::class, 'Invoice UUID b1f0a3c2-6e0b-4f6c-9b5e-2d4b7f0c8a11 was already used.');
    expect($this->chain->append('pos-1', fn (Link $link) => signed($link))->counter)->toBe(2);
});

it('locks the unit\'s chain while an invoice is added, so concurrent invoices queue', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite allows one writer at a time and has no row locks.');
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $this->chain->append('pos-1', fn (Link $link) => signed($link));

    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'zatca_chains') && str_contains($sql, 'for update')))->toBeTrue();
});

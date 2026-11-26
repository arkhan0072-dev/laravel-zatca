<?php

// The README's laravel-ledger recipe, run as written.

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\JournalEntry;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Tests\Fixtures\TestCertificate;
use Arkhan\Zatca\Zatca;

beforeEach(function () {
    if (! class_exists(Ledger::class)) {
        $this->markTestSkipped('laravel-ledger is not installed; laravel-zatca works without it.');
    }
    Unit::create([
        'unit' => 'pos-1', 'environment' => 'sandbox', 'status' => 'active',
        'private_key' => TestCertificate::privateKey(),
        'compliance_certificate' => 'c', 'compliance_secret' => 's', 'compliance_request_id' => '1',
        'certificate' => TestCertificate::make()->getRawCertificate(), 'secret' => 'production-secret',
    ]);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'SAR']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'SAR']);
    $this->vat = Account::create(['code' => '2200', 'name' => 'VAT payable', 'type' => AccountType::Liability, 'currency' => 'SAR']);
});

/** The recipe: book an issued ZATCA invoice once, linked to it. */
function bookInLedger(Invoice $invoice, array $data, Account $cash, Account $sales, Account $vat): JournalEntry
{
    $halalas = fn (float $sar) => (int) round($sar * 100);
    $totals = $data['legalMonetaryTotal'];

    return app(Ledger::class)->post("ZATCA invoice {$data['id']}", [
        Line::debit($cash, $halalas($totals['taxInclusiveAmount'])),
        Line::credit($sales, $halalas($totals['taxExclusiveAmount'])),
        Line::credit($vat, $halalas($data['taxTotal']['taxAmount'])),
    ], idempotencyKey: "zatca:{$invoice->uuid}", reference: $invoice);
}

it('books an issued invoice in laravel-ledger, linked to it and only once', function () {
    $data = Invoices::simplified();
    $invoice = app(Zatca::class)->issue('pos-1', $data);

    $entry = bookInLedger($invoice, $data, $this->cash, $this->sales, $this->vat);
    $retry = bookInLedger($invoice, $data, $this->cash, $this->sales, $this->vat);

    expect($retry->id)->toBe($entry->id)
        ->and($entry->reference->is($invoice))->toBeTrue()
        ->and([$this->cash->balance(), $this->sales->balance(), $this->vat->balance()])->toBe([23_115, 20_100, 3_015])
        ->and(app(Ledger::class)->incomeStatement('SAR')['net'])->toBe(20_100);
});

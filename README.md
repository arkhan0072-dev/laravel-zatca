# Laravel ZATCA

[![CI](https://github.com/arkhan0072-dev/laravel-zatca/actions/workflows/ci.yml/badge.svg)](https://github.com/arkhan0072-dev/laravel-zatca/actions/workflows/ci.yml)

ZATCA (Fatoora) Phase 2 e-invoicing for Laravel, built around the part that breaks under load: the invoice chain.

> **Status: 1.0, stable API.** Onboarding, the invoice chain, signed invoices and notes, exactly-once reporting and clearance, certificate renewal and test fakes pass ZATCA's developer sandbox end to end, and the chain holds under concurrent processes on PostgreSQL and MySQL. Breaking changes wait for 2.0. Before going live, onboard your unit in ZATCA's simulation environment (`ZATCA_ENVIRONMENT=simulation`) and send a few real invoices there: the sandbox does not run every check that production does.

## Why the chain matters

Every invoice an EGS unit issues carries the next counter (ICV) and the hash of the invoice before it (PIH). ZATCA requires one gap-free sequence per unit, and an ICV may never appear on two documents ([Detailed Technical Guideline](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf), pages 48 and 72). Two invoices built at the same moment, by two web requests or two queue workers, must therefore never receive the same link.

The sandbox will not catch a broken chain for you: in our tests it accepted a wrong previous hash and a counter jump without even a warning. A broken chain surfaces later, in production checks or an audit, when it is much harder to repair.

`Chain::append()` locks the unit's chain head (`SELECT ... FOR UPDATE`), hands your code the next link, stores the signed invoice and moves the head, all in one transaction. A second invoice waits for the first and then gets the next link.

## Usage

```bash
composer require arkhan0072-dev/laravel-zatca
php artisan migrate
```

### Onboard an EGS unit

```php
use Arkhan\Zatca\Onboarding;
use Arkhan\Zatca\UnitDetails;

$onboarding = app(Onboarding::class);

// 1. Key pair, certificate request and compliance certificate. The OTP comes from the taxpayer's Fatoora portal.
$onboarding->start('pos-1', new UnitDetails(
    vatNumber: '399999999900003',
    organizationName: 'Maximum Speed Tech Supply',
    branchName: 'Riyadh Branch',
    commonName: 'TST-886431145-399999999900003',
    solutionName: 'TST', model: 'TST', serialNumber: 'ed22f1d8-e6a2-1118-9b58-d9a8f11e445f',
    address: 'RRRD2929',
    businessCategory: 'Supply activities',
), $otp);

// 2. ZATCA's compliance checks with sample documents, then the production certificate.
$onboarding->complete('pos-1', [$standardInvoice, $simplifiedInvoice /* , credit and debit notes */]);
```

The unit's private key and secrets are stored encrypted with your `APP_KEY`, and the key is never written to disk. Set `ZATCA_ENVIRONMENT` in `.env` to `sandbox` (default), `simulation` or `production`; `php artisan vendor:publish --tag=zatca-config` copies the config file if you want it in your app. The sandbox accepts the OTP `123345`.

### Issue an invoice

```php
use Arkhan\Zatca\Zatca;

$invoice = app(Zatca::class)->issue('pos-1', [
    'uuid' => (string) Str::uuid(),
    'id' => 'INV-1001',
    'issueDate' => '2026-11-26',
    'issueTime' => '14:30:00',
    'invoiceType' => ['invoice' => 'simplified', 'type' => 'invoice'],
    'supplier' => [...],
    'invoiceLines' => [...],
    'taxTotal' => [...],
    'legalMonetaryTotal' => [...],
]); // signed with the unit's production certificate

$invoice->counter;  // ICV in its unit's chain
$invoice->xml;      // signed UBL 2.1 XML, ready for reporting or clearance
$invoice->qr;       // base64 QR code to print on the invoice
```

The invoice data uses the array format of [saleh7/php-zatca-xml](https://github.com/Saleh7/php-zatca-xml), which builds the UBL XML, signs it and makes the QR code. This package adds the counter (ICV) and previous invoice hash (PIH) from the unit's chain, replacing any you pass, and stores the result.

### Send it to ZATCA, exactly once

```php
use Arkhan\Zatca\Jobs\SubmitInvoice;

dispatch(new SubmitInvoice($invoice));   // queued, retried with backoff during ZATCA outages

// or right away
$invoice = app(Zatca::class)->submit($invoice);
$invoice->status;          // reported (simplified), cleared (standard), rejected, or still pending
$invoice->zatca_response;  // ZATCA's answer, with its warnings or the reasons for a rejection
$invoice->cleared_xml;     // ZATCA's stamped copy of a cleared invoice: this is the one to give the buyer
```

- Simplified (B2C) invoices go to reporting and standard (B2B) invoices to clearance, read from the invoice's own type code.
- The invoice row is locked while it is sent, so two workers never send it twice, and a reported or cleared invoice is never sent again. ZATCA's "already reported" (409) counts as reported.
- A rejection (400) is stored with ZATCA's reasons and not retried. See [When ZATCA rejects an invoice](#when-zatca-rejects-an-invoice).
- If ZATCA has turned clearance off (303), a standard invoice is sent through reporting instead, as ZATCA instructs; it is then `reported`, with no stamped copy.
- An outage (5xx, timeout, 401) leaves the invoice pending and throws `SubmissionException`, which the job retries. ZATCA expects simplified invoices within 24 hours.

### When ZATCA rejects an invoice

This follows ZATCA's [Detailed Technical Guideline](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf) (pages 48, 71, 72, 77 and 78), and the scenarios run against ZATCA's sandbox in `tests/Integration/SandboxTest.php`.

- **The rejected invoice keeps its place in the chain.** ZATCA records the hash of rejected documents, so the next invoice links to the rejected one, and its counter and UUID are never reused. The package does this on its own: never edit or delete a rejected invoice.
- **Never resend it.** Fix the data and issue a new invoice; it gets the next counter, a new UUID and a new hash.
- **Standard (B2B):** a rejected invoice is not a tax invoice, so do not give it to the buyer and do not raise a note against it. Issue a corrected invoice, dated when you issue it.
- **Simplified (B2C):** the buyer already has the invoice. Either issue a corrected invoice keeping the original transaction date, or cancel it with a credit note and issue a new one; ZATCA's guideline gives both answers.
- **VAT is still due** on the sale, even while its invoice is rejected.
- **If your system cannot issue invoices at all**, ZATCA requires you to [notify it](https://zatca.gov.sa/en/E-Invoicing/FailureNotifications/Pages/VerifyTaxpayer.aspx), then send everything once you are back.

```php
Event::listen(InvoiceRejected::class, function (InvoiceRejected $e) {
    // $e->reasons, e.g. ['BR-CO-15: Invoice total amount with VAT (BT-112) ...']: fix the data, then
    // app(Zatca::class)->issue($e->invoice->unit, $correctedData);
});
```

### Renew a certificate before it expires

```php
$unit->certificateExpiresAt();                 // read from the certificate itself
app(Onboarding::class)->renew('pos-1', $otp);  // new key, new certificate request, same details
```

`zatca:submit-pending` warns when a unit's certificate expires within 30 days. Renewal reuses the details stored at onboarding; units onboarded before 0.3 pass them: `renew('pos-1', $otp, $details)`.

### Credit and debit notes

```php
$note = $zatca->creditNote($invoice, $noteData, 'Customer returned one item');   // refund or price reduction
$note = $zatca->debitNote($invoice, $noteData, 'Delivery was not invoiced');     // extra charge
dispatch(new SubmitInvoice($note));
```

A note goes into the same unit's chain as its invoice, takes the invoice's kind (so it is reported or cleared the same way), references the invoice's number, and carries the reason ZATCA requires. A note against a rejected standard invoice is refused, since it never became a tax invoice; a rejected simplified invoice can be cancelled with a credit note.

### Never miss the 24-hour window

```php
// routes/console.php
Schedule::command('zatca:submit-pending')->everyFifteenMinutes();
```

The command sends every pending invoice, prints how many were reported, cleared, rejected or are still pending, warns about simplified invoices older than 24 hours (BR-KSA-98), and exits non-zero when anything is rejected or still pending, so your scheduler's failure alerts fire.

Listen for the outcome of each invoice, fired once after it is saved:

```php
use Arkhan\Zatca\Events\InvoiceCleared;
use Arkhan\Zatca\Events\InvoiceRejected;
use Arkhan\Zatca\Events\InvoiceReported;

Event::listen(InvoiceCleared::class, fn (InvoiceCleared $e) => Mail::to($buyer)->send(new ClearedInvoice($e->invoice->cleared_xml)));
Event::listen(InvoiceRejected::class, fn (InvoiceRejected $e) => Log::error('ZATCA rejected '.$e->invoice->uuid, $e->reasons));
```

### Test your app without ZATCA

```php
use Arkhan\Zatca\Zatca;

$fake = Zatca::fake();   // every ZATCA call is answered offline from here on

// ... onboard, issue and submit through your own code ...

$fake->assertReported($invoice);
$fake->assertCleared($b2bInvoice);
$fake->assertSubmittedCount(2);
$fake->assertNothingSubmitted();

$fake->rejectNext('BR-KSA-37', 'The seller address building number must contain 4 digits.');  // next submission is rejected
$fake->outage();          // ZATCA answers 503 until $fake->outage(false)
```

The fake issues self-signed certificates, passes compliance checks, and reports or clears invoices, so your onboarding and invoicing code runs unchanged in tests.

### Book invoices in laravel-ledger

laravel-zatca works on its own; laravel-ledger is optional. ZATCA needs the legal invoice; your books need the money. [laravel-ledger](https://github.com/arkhan0072-dev/laravel-ledger) is a double-entry ledger from the same author, and the two fit together: book each issued invoice once, linked to it.

```php
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;

$invoice = app(Zatca::class)->issue('pos-1', $data);

$halalas = fn (float $sar) => (int) round($sar * 100);

app(Ledger::class)->post("ZATCA invoice {$data['id']}", [
    Line::debit($cash, $halalas($data['legalMonetaryTotal']['taxInclusiveAmount'])),  // 231.15 SAR received
    Line::credit($sales, $halalas($data['legalMonetaryTotal']['taxExclusiveAmount'])), // 201.00 SAR earned
    Line::credit($vatPayable, $halalas($data['taxTotal']['taxAmount'])),               // 30.15 SAR owed to ZATCA
], idempotencyKey: "zatca:{$invoice->uuid}", reference: $invoice);
```

- The invoice UUID as idempotency key books each invoice exactly once, even when a job retries.
- `reference` links the ledger entry to the ZATCA invoice: `JournalEntry::whereMorphedTo('reference', $invoice)`.
- The ledger then gives you the VAT you owe (`$vatPayable->balance()`), profit and loss, a balance sheet and period closing, with balances that the database refuses to let anyone rewrite.

This recipe runs as a test in `tests/Feature/LedgerRecipeTest.php`.

### The chain on its own

```php
use Arkhan\Zatca\Chain;
use Arkhan\Zatca\Link;
use Arkhan\Zatca\SignedInvoice;

$invoice = app(Chain::class)->append('pos-1', function (Link $link) use ($order) {
    // Build and sign the UBL XML with $link->counter (ICV) and $link->previousHash (PIH).
    $xml = $signer->sign($order, $link);

    return new SignedInvoice($uuid, base64_encode(hash('sha256', $canonicalXml, true)), $xml);
});

$invoice->counter;        // 1, 2, 3 ... per unit
$invoice->previous_hash;  // the hash of the unit's previous invoice
```

- A unit's first invoice gets ZATCA's seed hash, the base64 of the hex SHA-256 of `"0"` (`Chain::FIRST_PREVIOUS_HASH`).
- If building or signing throws, nothing is stored and the counter is not used up.
- The hash must be the base64 of a SHA-256 digest, and an invoice UUID can be used only once.

## Supported

PHP 8.3 and 8.4 (with `ext-dom`, `ext-openssl`, `ext-mbstring`), Guzzle 7 (required by saleh7/php-zatca-xml), Laravel 12 and 13, MySQL, PostgreSQL and SQLite. Every combination is tested in CI with 100% line coverage, PHPStan at max level and Pint.

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

Set `DB_CONNECTION=pgsql` or `DB_CONNECTION=mysql` and the usual `DB_*` variables to run the same tests against PostgreSQL or MySQL.

`ZATCA_SANDBOX=1 vendor/bin/pest tests/Integration` runs the whole flow against ZATCA's real developer sandbox: onboarding, compliance checks, production certificate, issuing, reporting and clearance.

## License

MIT

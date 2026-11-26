# Laravel ZATCA

[![CI](https://github.com/arkhan0072-dev/laravel-zatca/actions/workflows/ci.yml/badge.svg)](https://github.com/arkhan0072-dev/laravel-zatca/actions/workflows/ci.yml)

ZATCA (Fatoora) Phase 2 e-invoicing for Laravel, built around the part that breaks under load: the invoice chain.

> **Status: in development.** Onboarding, the invoice chain and signed XML with QR codes are done and pass ZATCA's sandbox. Reporting and clearance follow. Not for production use before 1.0.

## Why the chain matters

Every invoice an EGS unit issues carries the next counter (ICV) and the hash of the invoice before it (PIH). ZATCA rejects an invoice whose counter or previous hash is wrong, and every later invoice of that unit fails with it. Two invoices built at the same moment, by two web requests or two queue workers, must therefore never receive the same link.

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

The unit's private key and secrets are stored encrypted with your `APP_KEY`, and the key is never written to disk. Set `zatca.environment` to `sandbox` (default), `simulation` or `production`. The sandbox accepts the OTP `123345`.

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

`ZATCA_SANDBOX=1 vendor/bin/pest tests/Integration` runs the whole flow against ZATCA's real developer sandbox: onboarding, compliance checks, production certificate, and issued invoices checked by ZATCA.

## License

MIT

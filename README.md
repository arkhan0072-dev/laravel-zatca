# Laravel ZATCA

[![CI](https://github.com/arkhan0072-dev/laravel-zatca/actions/workflows/ci.yml/badge.svg)](https://github.com/arkhan0072-dev/laravel-zatca/actions/workflows/ci.yml)

ZATCA (Fatoora) Phase 2 e-invoicing for Laravel, built around the part that breaks under load: the invoice chain.

> **Status: in development.** The invoice chain is done. Signed XML and QR codes, onboarding, and reporting and clearance follow. Not for production use before 1.0.

## Why the chain matters

Every invoice an EGS unit issues carries the next counter (ICV) and the hash of the invoice before it (PIH). ZATCA rejects an invoice whose counter or previous hash is wrong, and every later invoice of that unit fails with it. Two invoices built at the same moment, by two web requests or two queue workers, must therefore never receive the same link.

`Chain::append()` locks the unit's chain head (`SELECT ... FOR UPDATE`), hands your code the next link, stores the signed invoice and moves the head, all in one transaction. A second invoice waits for the first and then gets the next link.

## Usage

```bash
composer require arkhan0072-dev/laravel-zatca
php artisan migrate
```

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

PHP 8.3 and 8.4, Laravel 12 and 13, MySQL, PostgreSQL and SQLite. Every combination is tested in CI with 100% line coverage, PHPStan at max level and Pint.

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

Set `DB_CONNECTION=pgsql` or `DB_CONNECTION=mysql` and the usual `DB_*` variables to run the same tests against PostgreSQL or MySQL.

## License

MIT

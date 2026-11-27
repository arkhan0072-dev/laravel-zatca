# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-11-28

### Added

- Sandbox scenarios for a rejected invoice: the chain carries on from it, a corrected invoice is cleared, and a rejected simplified invoice is cancelled with a credit note.
- README section on handling rejections, following ZATCA's Detailed Technical Guideline.

### Changed

- A credit or debit note can now cancel a rejected simplified invoice, which ZATCA allows. Notes against a rejected standard invoice are still refused.

### Fixed

- When ZATCA turns clearance off (HTTP 303), a standard invoice is now sent through reporting, as ZATCA instructs. Before, it stayed pending and was retried forever.

## [1.0.0] - 2026-11-26

The API is now stable: breaking changes wait for 2.0. See UPGRADE.md.

### Added

- A publishable config file (`php artisan vendor:publish --tag=zatca-config`); the environment comes from `ZATCA_ENVIRONMENT`.
- A concurrency test that runs four PHP processes appending to one unit's chain at once on PostgreSQL and MySQL, and checks for one unbroken chain.

### Fixed

- Concurrent invoicing on MySQL could deadlock. A unit's chain head is now created before the locking transaction and only locked inside it, and a deadlock is retried, which is safe because nothing has been stored yet.
- A clash on a unit's counter was reported as a reused invoice UUID; the error now names the key that clashed.

## [0.3.0] - 2026-11-26

### Added

- `Zatca::creditNote()` and `debitNote()`: notes against an invoice, in its unit's chain, of its kind, referencing its number and carrying the reason ZATCA requires (KSA-10). Notes against a rejected invoice are refused.
- `Invoice::number()`, `isStandard()` and `documentType()`, read from the signed XML.
- `php artisan zatca:submit-pending`: sends every pending invoice, summarises the outcome, warns about simplified invoices older than 24 hours and certificates expiring within 30 days, and exits non-zero when anything is rejected or still pending.
- Events `InvoiceReported`, `InvoiceCleared` and `InvoiceRejected` (with ZATCA's reasons), fired once after the result is saved.
- `Onboarding::renew()` and `Unit::certificateExpiresAt()`: renew a production certificate with a new key pair before it expires.

### Upgrading

Run `php artisan migrate` (adds `details` to `zatca_units`). Units onboarded on 0.1 or 0.2 have no stored details: pass them to `renew()` the first time.

## [0.2.0] - 2026-11-26

### Added

- `Zatca::fake()` for testing apps without ZATCA: it issues self-signed certificates, passes compliance checks, and reports or clears invoices. `rejectNext()` and `outage()` drive the unhappy paths; `assertReported()`, `assertCleared()`, `assertSubmittedCount()` and `assertNothingSubmitted()` check what was sent.
- `composer.json` suggests laravel-ledger for booking invoices.

### Changed

- laravel-ledger stays optional, and that is now tested: an architecture test fails if the package's code uses it, and CI runs the full suite without it.

### Upgrading

No changes needed.

## [0.1.0] - 2026-11-26

First release. Every feature below passes ZATCA's developer sandbox end to end.

### Added

- `Chain::append()`: a concurrency-safe invoice chain per EGS unit. The chain head is locked while an invoice is added, so invoices built at the same moment never share a counter (ICV) or previous hash (PIH). A unit's first invoice gets ZATCA's seed hash; a failed build uses up no counter.
- `Signer` and `Zatca::issue()`: invoice data to signed UBL 2.1 XML with its hash and QR code, using saleh7/php-zatca-xml, with the chain's ICV and PIH.
- `Onboarding::start()` and `complete()`: key pair, certificate request, compliance certificate, ZATCA's compliance checks and the production certificate. Private keys and secrets are encrypted at rest and keys never touch disk.
- `Zatca::submit()` and the `SubmitInvoice` job: reporting for simplified and clearance for standard invoices, sent exactly once under a row lock. ZATCA's 409 counts as done, rejections are kept with their reasons, outages stay pending for a retry, and cleared invoices keep ZATCA's stamped XML.
- A README recipe, run as a test, for booking issued invoices in laravel-ledger.
- An opt-in integration test (`ZATCA_SANDBOX=1`) that runs the whole flow against ZATCA's sandbox.

[Unreleased]: https://github.com/arkhan0072-dev/laravel-zatca/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/arkhan0072-dev/laravel-zatca/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/arkhan0072-dev/laravel-zatca/compare/v0.3.0...v1.0.0
[0.3.0]: https://github.com/arkhan0072-dev/laravel-zatca/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/arkhan0072-dev/laravel-zatca/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/arkhan0072-dev/laravel-zatca/releases/tag/v0.1.0

# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/arkhan0072-dev/laravel-zatca/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/arkhan0072-dev/laravel-zatca/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/arkhan0072-dev/laravel-zatca/releases/tag/v0.1.0

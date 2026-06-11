# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project follows Semantic Versioning from `1.0.1` onward.

## [1.1.0] - 2026-06-11

### Added

- `einvoice:import --exchange-rate=` option for non-EUR documents in non-interactive runs (`--yes`/`--json`)
- Expired OAuth access tokens are now refreshed automatically before API calls when client credentials are stored (`expires_at` is tracked in `auth.json`)
- OAuth login now also requests the `receipts`, `emails:r`, `calendar`, and `stock` scopes
- Failed attachment uploads during XML import are now reported instead of being silently skipped

### Changed

- `einvoice:import --json` without `--dry-run` now actually imports (requires `--yes`) and outputs plan plus results; previously it always behaved as a dry run
- Removed the spec normalization layer: `spatie/laravel-openapi-cli` >= 1.1 merges path-level parameters natively (upstreamed in spatie/laravel-openapi-cli#3)
- `clients:search` / `suppliers:search` / `products:search` now report the merged result count instead of a misleading `total`
- `XDG_CONFIG_HOME` is now respected for the config directory location on Unix-like systems

### Fixed

- Single quotes in XML import entity-matching queries are now escaped by doubling them, as required by the Fatture in Cloud query syntax (backslash escaping was rejected by the API)
- `clear-cache` now clears the spec cache directory it documented (`~/.config/fattureincloud-cli/cache`)
- The browser is now opened correctly on Windows during OAuth login
- Folder XML imports no longer refetch the company list once per file
- Temporary files created for `.p7m` extraction and attachment uploads are now cleaned up
- The payment-mismatch warning no longer claims amounts were omitted for multi-installment imports
- Year-first invoice numbers (for example `2026/15`) now produce a warning about the number/numeration split

## [1.0.2] - 2026-03-22

### Changed

- Removed `--direction` option from `einvoice:import`; direction is now auto-detected from XML content by comparing seller/buyer fiscal codes against the selected company
- Self-invoice document types (TD16-TD23, TD28, TD29) are correctly classified as `issued` when both parties match the company

### Added

- Added reverse charge lifecycle documentation to skill workflows (TD01 N6.x -> TD16 self-invoice -> SDI round-trip)
- Added Natura N6.x and self-invoice TD type reference tables to skill docs

### Fixed

- Code formatting fixes via Pint (BuildCommand, XmlInvoiceMapper, config/app.php)

## [1.0.2] - 2026-03-22

### Changed

- Removed `--direction` option from `einvoice:import`; direction is now auto-detected from XML content by comparing seller/buyer fiscal codes against the selected company
- Self-invoice document types (TD16-TD23, TD28, TD29) are correctly classified as `issued` when both parties match the company

### Added

- Added reverse charge lifecycle documentation to skill workflows (TD01 N6.x -> TD16 self-invoice -> SDI round-trip)
- Added Natura N6.x and self-invoice TD type reference tables to skill docs

### Fixed

- Code formatting fixes via Pint (BuildCommand, XmlInvoiceMapper, config/app.php)

## [1.0.1] - 2026-03-20

### Added

- Added `einvoice:import` to import one XML file or a whole folder of fattura elettronica XML files with recap and dry run
- Added structured skill references for API filtering, sorting, pagination, FAQ guidance, troubleshooting, quotas, and XML import
- Added tests and fixtures for the XML import workflow
- Added support for signed `.xml.p7m` e-invoices, procurement references, fiscal blocks, and attachment carry-over during import

### Changed

- Renamed generated API commands from `fic:*` to `api:*`, so commands are now used as `fic api:...`
- Updated the README and bundled skill docs to focus more clearly on the skill, static binaries, and practical usage patterns
- Bumped the release version to `1.0.1`

### Fixed

- Improved handling of rate limits and quota-related API errors, especially when `Retry-After` is returned
- Preserved e-invoice XML-specific details through `ei_raw` when recreating documents via the API
- Fixed XML imports so FatturaPA issued documents are recreated as electronic invoices by default instead of plain non-electronic invoices
- Improved XML import recognition so the dry run shows whether the selected company was recognized, whether a client or supplier was matched, and how much fiscal metadata was mapped structurally

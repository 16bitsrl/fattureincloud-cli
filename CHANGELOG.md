# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project follows Semantic Versioning from `1.0.1` onward.

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

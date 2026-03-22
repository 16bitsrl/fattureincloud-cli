# Fatture in Cloud CLI

A CLI for the [Fatture in Cloud](https://www.fattureincloud.it) API, with full API coverage, agent-oriented docs, and a practical XML import workflow for fattura elettronica files.

See `CHANGELOG.md` for release notes starting from `1.0.1`.

## Install

### Composer

```bash
composer global require 16bitsrl/fattureincloud-cli
```

### Static binaries

Each release also ships static builds for Linux, macOS, and Windows.

- Download them from the GitHub release assets
- Or inspect the local examples in `builds/static/`

After download, make the binary executable on Unix-like systems:

```bash
chmod +x ./fic-linux-x86_64
./fic-linux-x86_64 --version
```

## Quick start

```bash
# Authenticate
fic auth:login

# Set your default company
fic company:set

# Explore the generated API commands
fic api:list

# Search helpers
fic clients:search acme --company-id=12345
fic suppliers:search studio --company-id=12345
fic products:search consulting --company-id=12345
```

Get your manual token at [secure.fattureincloud.it/api](https://secure.fattureincloud.it/api).

## XML e-invoice import

The official API does not ingest raw XML, so the CLI recreates documents through JSON APIs.

For issued XML imports, the recreated document is treated as an electronic invoice by default.

```bash
# Preview a single XML
fic einvoice:import /absolute/path/to/fattura.xml --company-id=12345 --dry-run

# Preview a signed XML.p7m file
fic einvoice:import /absolute/path/to/fattura.xml.p7m --company-id=12345 --dry-run

# Import a folder of XML files (direction is auto-detected from XML content)
fic einvoice:import /absolute/path/to/xml-dir --company-id=12345 --yes
```

The import supports recap before creation, dry runs, `.xml` and `.xml.p7m` inputs, client/supplier matching, embedded attachment carry-over, automatic direction inference (issued/received/self-invoice), and structured mapping for supported e-invoice fields before falling back to `ei_raw`. If neither XML party matches the selected company, the import is rejected.

## Agent skill

This repository includes an [agent skill](https://skills.sh) that teaches coding agents how to use the CLI, including filtering, sorting, pagination, FAQ edge cases, quotas, and XML import.

### Install the skill

```bash
fic install-skill
```

Skill sources live in `skills/fattureincloud/`.

## Handy examples

```bash
fic api:list-issued-documents --company-id=12345 --type=invoice --sort=-date --json
fic api:list-clients --company-id=12345 --q="vat_number = 'IT01234567890'" --json
fic api:send-e-invoice --company-id=12345 --document-id=99
```

## API coverage

| Resource | Commands |
|---|---|
| Clients | list, get, create, modify, delete, info |
| Suppliers | list, get, create, modify, delete |
| Products | list, get, create, modify, delete |
| Issued documents | list, get, create, modify, delete, totals, email, transform, join |
| E-Invoices | send, verify XML, get XML, rejection reason |
| Received documents | list, get, create, modify, delete, totals, pending |
| Receipts | list, get, create, modify, delete, monthly totals |
| Taxes (F24) | list, get, create, modify, delete, attachments |
| Cashbook | list, get, create, modify, delete |
| Archive | list, get, create, modify, delete, attachments |
| Settings | payment accounts/methods, VAT types, tax profile, templates |
| Info | cities, countries, currencies, languages, categories, etc. |
| Webhooks | list, create, get, modify, delete, verify |
| User | info, companies |
| Companies | info, plan usage |

## Agent skill

See the dedicated section above.

## Testing

```bash
composer test
```

## Releasing a new version

Use the release script:

```bash
./bin/release.sh X.Y.Z
```

It will:

- build `builds/fic` with the requested version
- verify that `builds/fic` matches the source version
- commit `builds/fic`
- create tag `vX.Y.Z`
- push branch and tag

Then the GitHub release workflow will build the PHAR and static binaries for Linux, macOS, and Windows.

If you only want to verify that the committed PHAR is still aligned with the source version:

```bash
./bin/check-phar-sync.sh
```

CI also runs the same sync check on pushes and pull requests.

Users install or update with `composer global require 16bitsrl/fattureincloud-cli`.

## Updating the OpenAPI spec

```bash
./bin/update-spec.sh              # latest from master
./bin/update-spec.sh v2.1.8       # specific tag
fic clear-cache                   # clear cached normalized spec
```

## Credits

- Built with [Laravel Zero](https://laravel-zero.com) and [Spatie Laravel OpenAPI CLI](https://spatie.be/docs/laravel-openapi-cli)
- API by [Fatture in Cloud](https://developers.fattureincloud.it)
- Made by [Mattia Trapani](https://github.com/zupolgec) at [16bit S.r.l.](https://16bit.it)

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.

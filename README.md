# Fatture in Cloud CLI

A comprehensive command-line interface for the [Fatture in Cloud](https://www.fattureincloud.it) API — Italy's most popular e-invoicing platform.

**123 API endpoints** auto-generated from the official OpenAPI spec. Built for humans and agents.

## Installation

```bash
composer global require 16bitsrl/fattureincloud-cli
```

Make sure Composer's global bin directory is in your `PATH`:

```bash
composer global config bin-dir --absolute
```

## Authentication

```bash
# Interactive login (choose between token or OAuth2)
fic auth:login

# Direct token login (non-interactive, ideal for CI/agents)
fic auth:login --token=YOUR_ACCESS_TOKEN

# OAuth2 flow
fic auth:login --client-id=ID --client-secret=SECRET

# Check status
fic auth:status
fic auth:status --json

# Refresh OAuth token
fic auth:refresh

# Logout
fic auth:logout
```

Get your access token at [secure.fattureincloud.it/api](https://secure.fattureincloud.it/api).

## Company context

Most API calls require a `company_id`. Set a default to avoid repeating it:

```bash
# Interactive selection from your companies
fic company:set

# Direct set
fic company:set 12345

# Check current
fic company:current
```

## Usage

Every Fatture in Cloud API endpoint has a corresponding command:

```bash
# List all 123 available API commands
fic fic:list

# Examples
fic fic:list-clients --company_id=12345
fic fic:get-issued-document --company_id=12345 --document_id=99
fic fic:create-client --company_id=12345 --data='{"data":{"name":"Acme S.r.l.","type":"company"}}'
fic fic:send-e-invoice --company_id=12345 --document_id=99
```

### Output formats

```bash
# JSON (default, best for agents)
fic fic:list-clients --company_id=12345 --json

# YAML
fic fic:list-clients --company_id=12345 --yaml

# Compact JSON (best for piping)
fic fic:list-clients --company_id=12345 --minify

# Human-readable tables
fic fic:list-clients --company_id=12345
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

This CLI ships with a skill for AI coding agents (Claude Code, etc.):

```bash
fic install-skill
```

## Updating the OpenAPI spec

```bash
./bin/update-spec.sh              # latest from master
./bin/update-spec.sh v2.1.8       # specific tag
```

## Development

```bash
# Run locally
php fic <command>

# Build PHAR
php fic app:build fic --build-version=1.0.0

# Run tests
composer test
```

## Credits

- Built with [Laravel Zero](https://laravel-zero.com) and [Spatie Laravel OpenAPI CLI](https://spatie.be/docs/laravel-openapi-cli)
- API by [Fatture in Cloud](https://developers.fattureincloud.it)
- Made by [16bit S.r.l.](https://16bit.it)

## License

MIT. See [LICENSE.md](LICENSE.md).

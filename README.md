# Fatture in Cloud CLI

A comprehensive command-line interface for the [Fatture in Cloud](https://www.fattureincloud.it) API — Italy's most popular e-invoicing platform.

**123 API endpoints** auto-generated from the official OpenAPI spec. Built for humans and agents.

## Installation

```bash
composer global require 16bitsrl/fattureincloud-cli
```

Make sure Composer's global bin directory is in your `PATH`. You can find the path with:

```bash
composer global config bin-dir --absolute
```

## Updating

```bash
composer global require 16bitsrl/fattureincloud-cli
```

## Usage

### Authentication

```bash
# Log in with your access token
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

# Log out
fic auth:logout
```

Get your access token at [secure.fattureincloud.it/api](https://secure.fattureincloud.it/api).

### Company context

Most API calls require a `--company-id` option. Set a default to avoid repeating it:

```bash
# Interactive selection from your companies
fic company:set

# Direct set
fic company:set 12345

# Check current
fic company:current
```

### Commands

Every Fatture in Cloud API endpoint has a corresponding command. Run `fic <command> --help` for details on a specific command.

```bash
# List all 123 available API commands
fic fic:list

# Examples
fic fic:list-clients --company-id=12345
fic fic:get-issued-document --company-id=12345 --document-id=99
fic fic:create-client --company-id=12345 --input='{"data":{"name":"Acme S.r.l.","type":"company"}}'
fic fic:send-e-invoice --company-id=12345 --document-id=99

fic fic:list-suppliers --company-id=12345
fic fic:list-products --company-id=12345
fic fic:list-issued-documents --company-id=12345 --type=invoice
fic fic:list-received-documents --company-id=12345
fic fic:list-receipts --company-id=12345
fic fic:list-f24 --company-id=12345
fic fic:list-archive-documents --company-id=12345
fic fic:list-cashbook-entries --company-id=12345 --date-from=2025-01-01 --date-to=2025-12-31

fic fic:get-company-info --company-id=12345
fic fic:get-user-info
fic fic:list-user-companies
```

### Output formats

```bash
# Human-readable tables (default)
fic fic:list-clients --company-id=12345

# JSON
fic fic:list-clients --company-id=12345 --json

# YAML
fic fic:list-clients --company-id=12345 --yaml

# Compact JSON (best for piping)
fic fic:list-clients --company-id=12345 --minify
```

### Attachments

Attachment upload for issued and received documents is token-based.
Upload commands only return an `attachment_token`: they do not attach the file directly to the document.

```bash
# Upload an issued document attachment
fic fic:upload-issued-document-attachment \
  --company-id=12345 \
  --field=filename=document.pdf \
  --field='attachment=@/absolute/path/to/document.pdf' \
  --json

# Then pass the token when creating or modifying the document
fic fic:modify-issued-document --company-id=12345 --document-id=99 --input='{
  "data": {
    "attachment_token": "abc123..."
  }
}'

# Upload a received document attachment
fic fic:upload-received-document-attachment \
  --company-id=12345 \
  --field=filename=invoice.pdf \
  --field='attachment=@/absolute/path/to/invoice.pdf' \
  --json

# Then pass the token when creating or modifying the document
fic fic:modify-received-document --company-id=12345 --document-id=77 --input='{
  "data": {
    "attachment_token": "abc123..."
  }
}'
```

Verify the result with `fic fic:get-issued-document` or `fic fic:get-received-document` and check fields like `attachment_url` or `attachment_preview_url`.

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

This repository includes an [agent skill](https://skills.sh) that teaches coding agents how to use the Fatture in Cloud CLI.

### Install

```bash
fic install-skill
```

## Testing

```bash
composer test
```

## Releasing a new version

1. **Build the PHAR**:

    ```bash
    php fic app:build fic --build-version=X.Y.Z
    ```

2. **Commit and push**:

    ```bash
    git add builds/fic
    git commit -m "Release vX.Y.Z"
    git push origin main
    ```

3. **Create a release** in the GitHub UI — this creates the tag, triggers Packagist, and automatically updates the changelog.

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

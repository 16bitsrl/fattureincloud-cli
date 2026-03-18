---
name: fattureincloud
description: Manage Fatture in Cloud invoicing platform via the fic CLI. Create and manage invoices, clients, suppliers, products, receipts, e-invoices, taxes, and more.
license: MIT
metadata:
  author: 16bit S.r.l.
  version: 1.0.0
  repository: https://github.com/16bitsrl/fattureincloud-cli
---

# Fatture in Cloud CLI Skill

Use the `fic` CLI to interact with the Fatture in Cloud API - Italy's most popular e-invoicing platform.

## Prerequisites

- `fic` must be installed: `composer global require 16bitsrl/fattureincloud-cli`
- Must be authenticated: run `fic auth:login` if not yet configured
- A default company must be set: run `fic company:set` if not yet configured

Check status with: `fic auth:status --json`

## Authentication

```bash
# Interactive login (prompts for token or OAuth)
fic auth:login

# Direct token login (non-interactive, ideal for CI/agents)
fic auth:login --token=YOUR_ACCESS_TOKEN

# OAuth2 flow with client credentials
fic auth:login --client-id=ID --client-secret=SECRET

# Check auth status
fic auth:status
fic auth:status --json

# Refresh OAuth token
fic auth:refresh

# Logout
fic auth:logout
```

## Company context

Most API calls require a `--company-id` parameter. Set a default to avoid repeating it:

```bash
# Interactive selection
fic company:set

# Direct set
fic company:set 12345

# Check current
fic company:current
fic company:current --json
```

When `company_id` is set as default, you still need to pass it as `--company-id` to API commands, but you can read it from `fic company:current --json`.

## Plain-text search helpers

The raw API `--q` parameter uses Fatture in Cloud query syntax such as `name like '%acme%'`.
For plain-text search, prefer the helper commands:

```bash
fic clients:search Acme --company-id=COMPANY_ID
fic clients:search Acme --company-id=COMPANY_ID --json
fic suppliers:search Studio --company-id=COMPANY_ID
fic products:search consulting --company-id=COMPANY_ID
```

When you use raw `--q`, write full query syntax:

```bash
fic fic:list-clients --company-id=COMPANY_ID --q="name like '%acme%'" --json
fic fic:list-suppliers --company-id=COMPANY_ID --q="name like '%studio%'" --json
fic fic:list-products --company-id=COMPANY_ID --q="name like '%consulting%'" --json
```

## Output formats

All API commands support multiple output formats:

- **Default (JSON)**: Pretty-printed JSON (best for agents)
- `--yaml`: YAML output
- `--minify`: Compact single-line JSON (best for piping)
- `--headers`: Include HTTP response headers
- Human-readable tables are shown for list endpoints when not using --json/--yaml

## Filtering with `--q` (query syntax)

The `--q` parameter uses a **SQL-like query language** based on `field op value` triplets.

### Operators

| Operator | Symbol |
|---|---|
| Equal | `=` |
| Not equal | `<>`, `!=` |
| Greater than | `>` |
| Greater or equal | `>=` |
| Less than | `<` |
| Less or equal | `<=` |
| Like | `like` |
| Not like | `not like` |
| Contains | `contains` |
| Starts with | `starts with` |
| Ends with | `ends with` |
| Is null | `is null`, `= null` |
| Is not null | `is not null`, `!= null` |

### Values

- Strings: `'value'` (single-quoted)
- Numbers: `46`, `12.34`
- Booleans: `true`, `false`

### Combining filters

Use `AND` / `OR` and parentheses:

```
--q="date >= '2025-01-01' AND date <= '2025-12-31'"
--q="entity.name = 'Acme' AND amount_net > 1000"
--q="city = 'Milano' OR city = 'Roma'"
```

### Filterable fields per endpoint

| Endpoint | Filterable fields |
|---|---|
| listClients | id, code, name, type, vat_number, tax_code, address_street, address_postal_code, address_city, address_province, country, email, certified_email, phone, fax, notes, e_invoice, ei_code, created_at, updated_at |
| listSuppliers | id, code, name, type, vat_number, tax_code, address_street, address_postal_code, address_city, address_province, country, email, certified_email, phone, fax, notes, e_invoice, ei_code, created_at, updated_at |
| listProducts | id, name, code, net_price, gross_price, net_cost, description, category, notes, in_stock, created_at, updated_at |
| listIssuedDocuments | type, entity.id, entity.name, entity.vat_number, entity.tax_code, entity.city, entity.province, entity.country, date, number, numeration, any_subject, amount_net, amount_vat, amount_gross, next_due_date, created_at, updated_at |
| listReceivedDocuments | id, type, category, description, entity.id, entity.name, date, next_due_date, amount_gross, amount_net, amount_vat, invoice_number, created_at, updated_at |
| listReceipts | date, type, description, rc_center, created_at, updated_at |
| listF24 | due_date, status, amount, description |
| listArchiveDocuments | date, category, description |

### Common filter examples

```bash
# Invoices from 2025
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice \
  --q="date >= '2025-01-01' AND date <= '2025-12-31'" --json

# Received documents from a specific supplier
fic fic:list-received-documents --company-id=COMPANY_ID \
  --q="entity.name = 'Fornitore S.r.l.'" --json

# Received documents from 2025 by a specific supplier
fic fic:list-received-documents --company-id=COMPANY_ID \
  --q="entity.name = 'Fornitore S.r.l.' AND date >= '2025-01-01' AND date <= '2025-12-31'" --json

# Unpaid invoices (next_due_date in the past)
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice \
  --q="next_due_date < '2025-03-18'" --json

# Client by VAT number
fic fic:list-clients --company-id=COMPANY_ID \
  --q="vat_number = '12345678901'" --json

# Products over 100 EUR
fic fic:list-products --company-id=COMPANY_ID \
  --q="net_price > 100" --json
```

**IMPORTANT**: Document list endpoints (issued, received) do NOT have `--date-from` / `--date-to` options. You MUST use `--q` with the `date` field to filter by date range. Only `fic:list-cashbook-entries` has `--date-from` and `--date-to` as dedicated parameters.

## Pagination

List endpoints return paginated results.

| Parameter | Description | Default | Max |
|---|---|---|---|
| `--page` | Page number | 1 | - |
| `--per-page` | Items per page | 50 | 100 |

The response includes pagination metadata: `current_page`, `last_page`, `total`, `per_page`, `from`, `to`, `first_page_url`, `last_page_url`, `next_page_url`, `prev_page_url`.

```bash
# Get page 2 with 25 items per page
fic fic:list-clients --company-id=COMPANY_ID --page=2 --per-page=25 --json

# Get all invoices (first check total, then paginate)
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice --per-page=100 --json
```

## Sorting with `--sort`

Use `--sort` with comma-separated field names. Prefix with `-` for descending order.

```bash
# Sort invoices by date descending
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice --sort=-date --json

# Sort clients by name ascending
fic fic:list-clients --company-id=COMPANY_ID --sort=name --json

# Sort received documents by date desc, then amount asc
fic fic:list-received-documents --company-id=COMPANY_ID --sort=-date,amount_net --json
```

### Sortable fields per endpoint

| Endpoint | Sortable fields |
|---|---|
| listClients | code, name, type, vat_number, tax_code, address_street, address_postal_code, address_city, address_province, country, email, certified_email, phone, fax, notes, e_invoice, ei_code, created_at, updated_at |
| listSuppliers | code, name, type, vat_number, tax_code, address_street, address_postal_code, address_city, address_province, country, email, certified_email, phone, fax, notes, e_invoice, ei_code, created_at, updated_at |
| listProducts | name, code, net_price, gross_price, net_cost, description, category, notes, in_stock, created_at, updated_at |
| listIssuedDocuments | entity.id, entity.name, entity.vat_number, entity.tax_code, entity.city, entity.province, entity.country, date, number, numeration, amount_net, amount_vat, amount_gross, next_due_date, created_at, updated_at |
| listReceivedDocuments | id, category, entity.id, entity.name, date, next_due_date, amount_gross, amount_net, amount_vat, created_at, updated_at |
| listReceipts | date, rc_center, created_at, updated_at |
| listF24 | due_date, status, amount, description |
| listArchiveDocuments | date, category, description |

## Errors

| Code | Meaning |
|---|---|
| 401 | Unauthorized - token missing, invalid or expired. Re-authenticate with `fic auth:login` |
| 403 | Forbidden - insufficient permissions/scopes, expired license, or hourly/monthly rate limit exceeded |
| 404 | Not found - the resource does not exist |
| 409 | Conflict - cannot perform the operation |
| 422 | Unprocessable entity - invalid request body (validation errors in response) |
| 429 | Too many requests - short-term rate limit exceeded. Wait for `Retry-After` seconds |

## Rate limits

| Limit type | Quota | Window |
|---|---|---|
| Short-term | 300 requests / 5 minutes | Sliding window, per company |
| Hourly | 1,000 requests / hour | Fixed window, per company-app |
| Monthly | 40,000 requests / month | Fixed window, per company-app (public) or per company (private) |

Rate limit headers are included in every response: `RateLimit-HourlyRemaining`, `RateLimit-HourlyLimit`, `RateLimit-MonthlyRemaining`, `RateLimit-MonthlyLimit`.

When exceeded: 403 for hourly/monthly limits, 429 for short-term limits. Both include a `Retry-After` header.

## Scopes

Scopes follow the `resource:level` pattern. Levels: `:r` (read-only), `:a` (full access).

| Scope | Description |
|---|---|
| situation:r | Dashboard, summary cards, deadlines |
| entity.clients:r/a | Clients |
| entity.suppliers:r/a | Suppliers |
| products:r/a | Products |
| issued_documents.invoices:r/a | Invoices |
| issued_documents.credit_notes:r/a | Credit notes |
| issued_documents.quotes:r/a | Quotes |
| issued_documents.proformas:r/a | Proforma invoices |
| issued_documents.receipts:r/a | Receipts (ricevute) |
| issued_documents.delivery_notes:r/a | Delivery notes (DDT) |
| issued_documents.orders:r/a | Orders |
| received_documents:r/a | All received documents (fatture passive) |
| receipts:r/a | Corrispettivi |
| taxes:r/a | F24 taxes |
| cashbook:r/a | Prima nota |
| archive:r/a | Archive |
| settings:r/a | Company settings |
| emails:r | Emails (read-only) |
| calendar:r/a | Scadenziario Plus |
| stock:r/a | Magazzino |

## Quick command reference

### List all available commands
```bash
fic fic:list
```

### User & Companies
```bash
fic fic:get-user-info
fic fic:list-user-companies
fic fic:get-company-info --company-id=COMPANY_ID
fic fic:get-company-plan-usage --company-id=COMPANY_ID --category=documents
```

### Clients
```bash
# List clients
fic fic:list-clients --company-id=COMPANY_ID

# Get a specific client
fic fic:get-client --company-id=COMPANY_ID --client-id=CLIENT_ID

# Create a client
fic fic:create-client --company-id=COMPANY_ID --input='{"data":{"name":"Acme S.r.l.","vat_number":"IT12345678901","type":"company"}}'

# Modify a client
fic fic:modify-client --company-id=COMPANY_ID --client-id=CLIENT_ID --input='{"data":{"name":"New Name"}}'

# Delete a client
fic fic:delete-client --company-id=COMPANY_ID --client-id=CLIENT_ID
```

### Suppliers
```bash
fic fic:list-suppliers --company-id=COMPANY_ID
fic fic:get-supplier --company-id=COMPANY_ID --supplier-id=SUPPLIER_ID
fic fic:create-supplier --company-id=COMPANY_ID --input='{"data":{"name":"Fornitore S.r.l.","type":"company"}}'
fic fic:modify-supplier --company-id=COMPANY_ID --supplier-id=SUPPLIER_ID --input='{"data":{"name":"New Name"}}'
fic fic:delete-supplier --company-id=COMPANY_ID --supplier-id=SUPPLIER_ID
```

### Products
```bash
fic fic:list-products --company-id=COMPANY_ID
fic fic:get-product --company-id=COMPANY_ID --product-id=PRODUCT_ID
fic fic:create-product --company-id=COMPANY_ID --input='{"data":{"name":"Widget","net_price":100,"code":"WDG001"}}'
fic fic:modify-product --company-id=COMPANY_ID --product-id=PRODUCT_ID --input='{"data":{"net_price":120}}'
fic fic:delete-product --company-id=COMPANY_ID --product-id=PRODUCT_ID
```

### Issued documents (invoices, quotes, orders, credit notes, etc.)
```bash
# List invoices
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice

# List invoices from 2025
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice \
  --q="date >= '2025-01-01' AND date <= '2025-12-31'" --json

# List quotes
fic fic:list-issued-documents --company-id=COMPANY_ID --type=quote

# Get a document
fic fic:get-issued-document --company-id=COMPANY_ID --document-id=DOC_ID

# Create an invoice
fic fic:create-issued-document --company-id=COMPANY_ID --input='{"data":{"type":"invoice","entity":{"id":CLIENT_ID},"items_list":[{"name":"Service","net_price":1000,"qty":1,"vat":{"id":VAT_ID}}]}}'

# Modify a document
fic fic:modify-issued-document --company-id=COMPANY_ID --document-id=DOC_ID --input='{"data":{...}}'

# Delete a document
fic fic:delete-issued-document --company-id=COMPANY_ID --document-id=DOC_ID

# Get document totals
fic fic:get-existing-issued-document-totals --company-id=COMPANY_ID --document-id=DOC_ID

# Get email data for sending
fic fic:get-email-data --company-id=COMPANY_ID --document-id=DOC_ID

# Schedule email
fic fic:schedule-email --company-id=COMPANY_ID --document-id=DOC_ID --input='{"data":{"sender_email":"you@example.com","recipient_email":"client@example.com","subject":"Invoice","body":"Please find attached."}}'
```

#### Attachments

Attachment upload for issued documents is a 2-step flow.
The upload command does not attach the file directly to the document: it only returns an `attachment_token`.

```bash
# 1. Upload the file and get attachment_token
fic fic:upload-issued-document-attachment \
  --company-id=COMPANY_ID \
  --field=filename=document.pdf \
  --field='attachment=@/absolute/path/to/document.pdf' \
  --json

# 2a. Attach it when creating a document
fic fic:create-issued-document --company-id=COMPANY_ID --input='{
  "data": {
    "type": "invoice",
    "entity": {"id": CLIENT_ID},
    "attachment_token": "abc123..."
  }
}'

# 2b. Or attach it to an existing document
fic fic:modify-issued-document \
  --company-id=COMPANY_ID \
  --document-id=DOCUMENT_ID \
  --input='{
    "data": {
      "attachment_token": "abc123..."
    }
  }'

# 3. Verify the attachment was linked
fic fic:get-issued-document --company-id=COMPANY_ID --document-id=DOCUMENT_ID --json
```

Notes:
- `filename` must be a plain string
- `attachment` must use the uploaded file with `@/absolute/path/to/file.pdf`
- `document-id` is not accepted by the upload endpoint
- After attaching, verify `attachment_url` on the document response

### E-Invoices (fattura elettronica)
```bash
# Send e-invoice to SDI
fic fic:send-e-invoice --company-id=COMPANY_ID --document-id=DOC_ID

# Verify e-invoice XML
fic fic:verify-e-invoice-xml --company-id=COMPANY_ID --document-id=DOC_ID

# Get e-invoice XML
fic fic:get-e-invoice-xml --company-id=COMPANY_ID --document-id=DOC_ID

# Get rejection reason
fic fic:get-e-invoice-rejection-reason --company-id=COMPANY_ID --document-id=DOC_ID
```

### Received documents (fatture passive)
```bash
fic fic:list-received-documents --company-id=COMPANY_ID
fic fic:get-received-document --company-id=COMPANY_ID --document-id=DOC_ID
fic fic:create-received-document --company-id=COMPANY_ID --input='{"data":{...}}'
fic fic:modify-received-document --company-id=COMPANY_ID --document-id=DOC_ID --input='{"data":{...}}'
fic fic:delete-received-document --company-id=COMPANY_ID --document-id=DOC_ID

# Filter by date range (use --q, NOT --date-from/--date-to)
fic fic:list-received-documents --company-id=COMPANY_ID \
  --q="date >= '2025-01-01' AND date <= '2025-12-31'" --json

# Filter by supplier name
fic fic:list-received-documents --company-id=COMPANY_ID \
  --q="entity.name = 'Fornitore S.r.l.'" --json
```

#### Attachments

Attachment upload for received documents is a 2-step flow.
The upload command does not attach the file directly to the document: it only returns an `attachment_token`.

```bash
# 1. Upload the file and get attachment_token
fic fic:upload-received-document-attachment \
  --company-id=COMPANY_ID \
  --field=filename=invoice.pdf \
  --field='attachment=@/absolute/path/to/invoice.pdf' \
  --json

# 2a. Attach it when creating a document
fic fic:create-received-document --company-id=COMPANY_ID --input='{
  "data": {
    "type": "expense",
    "entity": {"name": "Fornitore S.r.l."},
    "attachment_token": "abc123..."
  }
}'

# 2b. Or attach it to an existing document
fic fic:modify-received-document \
  --company-id=COMPANY_ID \
  --document-id=DOCUMENT_ID \
  --input='{
    "data": {
      "attachment_token": "abc123..."
    }
  }'

# 3. Verify the attachment was linked
fic fic:get-received-document --company-id=COMPANY_ID --document-id=DOCUMENT_ID --json
```

Notes:
- `filename` must be a plain string
- `attachment` must use the uploaded file with `@/absolute/path/to/file.pdf`
- `document-id` is not accepted by the upload endpoint
- After attaching, verify `attachment_url` or `attachment_preview_url` on the document response

### Receipts (corrispettivi)
```bash
fic fic:list-receipts --company-id=COMPANY_ID
fic fic:get-receipt --company-id=COMPANY_ID --document-id=DOC_ID
fic fic:create-receipt --company-id=COMPANY_ID --input='{"data":{...}}'
fic fic:get-receipts-monthly-totals --company-id=COMPANY_ID
```

### Taxes (F24)
```bash
fic fic:list-f24 --company-id=COMPANY_ID
fic fic:get-f24 --company-id=COMPANY_ID --document-id=DOC_ID
fic fic:create-f24 --company-id=COMPANY_ID --input='{"data":{"amount":1000,"description":"IRPEF","due_date":"2025-06-16"}}'
fic fic:modify-f24 --company-id=COMPANY_ID --document-id=DOC_ID --input='{"data":{...}}'
fic fic:delete-f24 --company-id=COMPANY_ID --document-id=DOC_ID
```

### Cashbook (prima nota)
```bash
# Cashbook is the ONLY endpoint with --date-from / --date-to parameters
fic fic:list-cashbook-entries --company-id=COMPANY_ID --date-from=2025-01-01 --date-to=2025-12-31
fic fic:get-cashbook-entry --company-id=COMPANY_ID --document-id=DOC_ID
fic fic:create-cashbook-entry --company-id=COMPANY_ID --input='{"data":{...}}'
```

### Archive
```bash
fic fic:list-archive-documents --company-id=COMPANY_ID
fic fic:get-archive-document --company-id=COMPANY_ID --document-id=DOC_ID
fic fic:create-archive-document --company-id=COMPANY_ID --input='{"data":{"date":"2025-01-15","description":"Contract"}}'
```

### Settings
```bash
fic fic:get-tax-profile --company-id=COMPANY_ID

# Payment accounts
fic fic:list-payment-accounts --company-id=COMPANY_ID
fic fic:create-payment-account --company-id=COMPANY_ID --input='{"data":{"name":"Conto corrente","type":"standard"}}'

# Payment methods
fic fic:list-payment-methods --company-id=COMPANY_ID
fic fic:create-payment-method --company-id=COMPANY_ID --input='{"data":{"name":"Bonifico","type":"standard"}}'

# VAT types
fic fic:list-vat-types --company-id=COMPANY_ID
fic fic:create-vat-type --company-id=COMPANY_ID --input='{"data":{"value":22,"description":"IVA 22%"}}'
```

### Info (reference data)
```bash
fic fic:list-cities --postal-code=20100
fic fic:list-countries
fic fic:list-currencies
fic fic:list-languages
fic fic:list-units-of-measure
fic fic:list-templates
fic fic:list-cost-centers --company-id=COMPANY_ID
fic fic:list-revenue-centers --company-id=COMPANY_ID
fic fic:list-product-categories --company-id=COMPANY_ID
```

### Webhooks
```bash
fic fic:list-webhooks-subscriptions --company-id=COMPANY_ID
fic fic:create-webhooks-subscription --company-id=COMPANY_ID --input='{"data":{"sink":"https://example.com/webhook","event_types":["it.fattureincloud.webhooks.issued_documents.invoices.create"]}}'
fic fic:delete-webhooks-subscription --company-id=COMPANY_ID --subscription-id=SUB_ID
```

## Common workflows

See [references/workflows.md](references/workflows.md) for detailed multi-step workflows.

## Important notes

- All monetary amounts are in EUR unless a currency is specified
- Document types for issued documents: `invoice`, `quote`, `proforma`, `receipt`, `delivery_note`, `credit_note`, `order`
- The `--input` flag accepts JSON strings for POST/PUT requests
- Attachment upload is token-based: upload first, then pass `attachment_token` to the document create/modify call
- Field filtering is available with `--fields` and `--fieldset` parameters
- When an API call fails, the error response includes details in JSON format
- `--date-from` / `--date-to` are only available on `fic:list-cashbook-entries`. For all other list endpoints, use `--q` with date filters

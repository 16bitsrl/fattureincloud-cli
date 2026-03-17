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

## Output formats

All API commands support multiple output formats:

- **Default (JSON)**: Pretty-printed JSON (best for agents)
- `--yaml`: YAML output
- `--minify`: Compact single-line JSON (best for piping)
- `--headers`: Include HTTP response headers
- Human-readable tables are shown for list endpoints when not using --json/--yaml

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
- List endpoints support `--page`, `--per-page`, `--sort`, `--q` (search) parameters
- Field filtering is available with `--fields` and `--fieldset` parameters
- When an API call fails, the error response includes details in JSON format

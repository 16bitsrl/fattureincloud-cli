# Common workflows

## Create and send an invoice

```bash
# 1. Find or create the client
fic clients:search Acme --company-id=COMPANY_ID --json

# 2. Get available VAT types
fic fic:list-vat-types --company-id=COMPANY_ID --json

# 3. Get pre-create info (default values, templates, etc.)
fic fic:get-issued-document-pre-create-info --company-id=COMPANY_ID --type=invoice --json

# 4. Create the invoice
fic fic:create-issued-document --company-id=COMPANY_ID --input='{
  "data": {
    "type": "invoice",
    "entity": {"id": CLIENT_ID},
    "date": "2025-03-17",
    "items_list": [
      {
        "name": "Consulting services",
        "net_price": 1000,
        "qty": 1,
        "vat": {"id": VAT_TYPE_ID}
      }
    ],
    "payment_method": {"id": PAYMENT_METHOD_ID}
  }
}'

# 5. Send via email
fic fic:schedule-email --company-id=COMPANY_ID --document-id=NEW_DOC_ID --input='{
  "data": {
    "sender_email": "you@example.com",
    "recipient_email": "client@example.com",
    "subject": "Invoice #1",
    "body": "Please find your invoice attached."
  }
}'

# 6. Send as e-invoice to SDI (if applicable)
fic fic:send-e-invoice --company-id=COMPANY_ID --document-id=NEW_DOC_ID
```

## Check unpaid invoices

```bash
# List all invoices, then filter for unpaid
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice --json | jq '.data[] | select(.is_marked == false)'
```

## Onboard a new client

```bash
# 1. Create the client
fic fic:create-client --company-id=COMPANY_ID --input='{
  "data": {
    "name": "Nuova Azienda S.r.l.",
    "type": "company",
    "vat_number": "IT12345678901",
    "tax_code": "12345678901",
    "address_street": "Via Roma, 1",
    "address_city": "Milano",
    "address_postal_code": "20100",
    "address_province": "MI",
    "country": "Italia",
    "email": "info@nuovaazienda.it",
    "certified_email": "nuovaazienda@pec.it",
    "e_invoice": true,
    "ei_code": "XXXXXXX"
  }
}'

# 2. Verify the client was created
fic fic:get-client --company-id=COMPANY_ID --client-id=NEW_CLIENT_ID --json
```

## Record a received invoice (fattura passiva)

```bash
# 1. Create the received document
fic fic:create-received-document --company-id=COMPANY_ID --input='{
  "data": {
    "type": "expense",
    "entity": {"name": "Fornitore S.r.l."},
    "date": "2025-03-15",
    "amount_net": 500,
    "amount_vat": 110,
    "amount_gross": 610,
    "category": "office_supplies",
    "description": "Office supplies March 2025"
  }
}'
```

## List received documents by date range

Use `--q` with date filters. Do NOT use `--date-from` / `--date-to` (those only work on cashbook).

```bash
# All received documents from 2025
fic fic:list-received-documents --company-id=COMPANY_ID \
  --q="date >= '2025-01-01' AND date <= '2025-12-31'" --json

# Received documents from a specific supplier in 2025
fic fic:list-received-documents --company-id=COMPANY_ID \
  --q="entity.name = 'Fornitore S.r.l.' AND date >= '2025-01-01' AND date <= '2025-12-31'" --json

# Issued invoices from Q1 2025, sorted by date
fic fic:list-issued-documents --company-id=COMPANY_ID --type=invoice \
  --q="date >= '2025-01-01' AND date <= '2025-03-31'" --sort=-date --json
```

## Monthly financial summary

```bash
# Get receipts monthly totals
fic fic:get-receipts-monthly-totals --company-id=COMPANY_ID --type=monthly --year=2025 --json

# Get cashbook entries for the month (cashbook supports --date-from/--date-to)
fic fic:list-cashbook-entries --company-id=COMPANY_ID --date-from=2025-03-01 --date-to=2025-03-31 --json

# Get company plan usage
fic fic:get-company-plan-usage --company-id=COMPANY_ID --category=documents --json
```

## Attach a file to an issued document

```bash
# 1. Upload the binary and save the token
fic fic:upload-issued-document-attachment \
  --company-id=COMPANY_ID \
  --field=filename=document.pdf \
  --field='attachment=@/absolute/path/to/document.pdf' \
  --json

# 2. Attach it to an existing document
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

## Attach a file to a received document

```bash
# 1. Upload the binary and save the token
fic fic:upload-received-document-attachment \
  --company-id=COMPANY_ID \
  --field=filename=invoice.pdf \
  --field='attachment=@/absolute/path/to/invoice.pdf' \
  --json

# 2. Attach it to an existing document
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

## Manage products catalog

```bash
# List all products
fic fic:list-products --company-id=COMPANY_ID --json

# Search for a specific product (raw API query syntax)
fic fic:list-products --company-id=COMPANY_ID --q="name like '%consulting%'" --json

# Or use the plain-text helper
fic products:search consulting --company-id=COMPANY_ID --json

# Update a product's price
fic fic:modify-product --company-id=COMPANY_ID --product-id=PRODUCT_ID --input='{
  "data": {"net_price": 150}
}'
```

## Paginate through large result sets

```bash
# 1. Get first page to check total
fic fic:list-clients --company-id=COMPANY_ID --per-page=100 --json

# 2. If total > 100, get subsequent pages
fic fic:list-clients --company-id=COMPANY_ID --per-page=100 --page=2 --json
fic fic:list-clients --company-id=COMPANY_ID --per-page=100 --page=3 --json
```

## Set up webhooks for automation

```bash
# Subscribe to new invoice creation events
fic fic:create-webhooks-subscription --company-id=COMPANY_ID --input='{
  "data": {
    "sink": "https://your-app.com/webhooks/fic",
    "event_types": [
      "it.fattureincloud.webhooks.issued_documents.invoices.create",
      "it.fattureincloud.webhooks.issued_documents.invoices.update"
    ]
  }
}'

# List active subscriptions
fic fic:list-webhooks-subscriptions --company-id=COMPANY_ID --json
```

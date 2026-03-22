# Common workflows

## Create and send an invoice

```bash
# 1. Find or create the client
fic clients:search Acme --company-id=COMPANY_ID --json

# 2. Get available VAT types
fic api:list-vat-types --company-id=COMPANY_ID --json

# 3. Get pre-create info (default values, templates, etc.)
fic api:get-issued-document-pre-create-info --company-id=COMPANY_ID --type=invoice --json

# 4. Create the invoice
fic api:create-issued-document --company-id=COMPANY_ID --input='{
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
fic api:schedule-email --company-id=COMPANY_ID --document-id=NEW_DOC_ID --input='{
  "data": {
    "sender_email": "you@example.com",
    "recipient_email": "client@example.com",
    "subject": "Invoice #1",
    "body": "Please find your invoice attached."
  }
}'

# 6. Send as e-invoice to SDI (if applicable)
fic api:send-e-invoice --company-id=COMPANY_ID --document-id=NEW_DOC_ID
```

## Check unpaid invoices

```bash
# List all invoices, then filter for unpaid
fic api:list-issued-documents --company-id=COMPANY_ID --type=invoice --json | jq '.data[] | select(.is_marked == false)'
```

## Onboard a new client

```bash
# 1. Create the client
fic api:create-client --company-id=COMPANY_ID --input='{
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
fic api:get-client --company-id=COMPANY_ID --client-id=NEW_CLIENT_ID --json
```

## Record a received invoice (fattura passiva)

```bash
# 1. Create the received document
fic api:create-received-document --company-id=COMPANY_ID --input='{
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
fic api:list-received-documents --company-id=COMPANY_ID \
  --q="date >= '2025-01-01' AND date <= '2025-12-31'" --json

# Received documents from a specific supplier in 2025
fic api:list-received-documents --company-id=COMPANY_ID \
  --q="entity.name = 'Fornitore S.r.l.' AND date >= '2025-01-01' AND date <= '2025-12-31'" --json

# Issued invoices from Q1 2025, sorted by date
fic api:list-issued-documents --company-id=COMPANY_ID --type=invoice \
  --q="date >= '2025-01-01' AND date <= '2025-03-31'" --sort=-date --json
```

## Monthly financial summary

```bash
# Get receipts monthly totals
fic api:get-receipts-monthly-totals --company-id=COMPANY_ID --type=monthly --year=2025 --json

# Get cashbook entries for the month (cashbook supports --date-from/--date-to)
fic api:list-cashbook-entries --company-id=COMPANY_ID --date-from=2025-03-01 --date-to=2025-03-31 --json

# Get company plan usage
fic api:get-company-plan-usage --company-id=COMPANY_ID --category=documents --json
```

## Attach a file to an issued document

```bash
# 1. Upload the binary and save the token
fic api:upload-issued-document-attachment \
  --company-id=COMPANY_ID \
  --field=filename=document.pdf \
  --field='attachment=@/absolute/path/to/document.pdf' \
  --json

# 2. Attach it to an existing document
fic api:modify-issued-document \
  --company-id=COMPANY_ID \
  --document-id=DOCUMENT_ID \
  --input='{
    "data": {
      "attachment_token": "abc123..."
    }
  }'

# 3. Verify the attachment was linked
fic api:get-issued-document --company-id=COMPANY_ID --document-id=DOCUMENT_ID --json
```

## Attach a file to a received document

```bash
# 1. Upload the binary and save the token
fic api:upload-received-document-attachment \
  --company-id=COMPANY_ID \
  --field=filename=invoice.pdf \
  --field='attachment=@/absolute/path/to/invoice.pdf' \
  --json

# 2. Attach it to an existing document
fic api:modify-received-document \
  --company-id=COMPANY_ID \
  --document-id=DOCUMENT_ID \
  --input='{
    "data": {
      "attachment_token": "abc123..."
    }
  }'

# 3. Verify the attachment was linked
fic api:get-received-document --company-id=COMPANY_ID --document-id=DOCUMENT_ID --json
```

## Manage products catalog

```bash
# List all products
fic api:list-products --company-id=COMPANY_ID --json

# Search for a specific product (raw API query syntax)
fic api:list-products --company-id=COMPANY_ID --q="name like '%consulting%'" --json

# Or use the plain-text helper
fic products:search consulting --company-id=COMPANY_ID --json

# Update a product's price
fic api:modify-product --company-id=COMPANY_ID --product-id=PRODUCT_ID --input='{
  "data": {"net_price": 150}
}'
```

## Paginate through large result sets

```bash
# 1. Get first page to check total
fic api:list-clients --company-id=COMPANY_ID --per-page=100 --json

# 2. If total > 100, get subsequent pages
fic api:list-clients --company-id=COMPANY_ID --per-page=100 --page=2 --json
fic api:list-clients --company-id=COMPANY_ID --per-page=100 --page=3 --json
```

## Import externally generated XML

```bash
# Review what will be imported
fic einvoice:import /absolute/path/to/xml-dir --company-id=COMPANY_ID --dry-run

# Import XML files (direction is auto-detected from XML content)
fic einvoice:import /absolute/path/to/xml-dir --company-id=COMPANY_ID --yes
```

## Reverse charge lifecycle (inversione contabile)

When a supplier invoice has VAT with Natura N6.x (reverse charge), the buyer must
integrate the VAT by creating a self-invoice. The full lifecycle on FIC is:

1. **Register the received invoice** — The supplier's TD01 arrives with 0% VAT / Natura N6.x.
   Register it as a received document (fattura passiva) on FIC.
2. **Create a self-invoice (autofattura TD16)** — The buyer creates an issued document of type
   `self_supplier_invoice` that mirrors the original amounts but applies the correct VAT rate
   (e.g. 22%). This document references the original via `DatiFattureCollegate`.
   On FIC this is an issued document with `ei_type: TD16`.
3. **Send the self-invoice to SDI** — The TD16 is transmitted to SDI.
   `SoggettoEmittente` will be `CC` (cessionario/committente).
4. **SDI delivers back the self-invoice** — SDI sends the TD16 back as an incoming e-invoice.
   This appears as a new received e-invoice on FIC. It should be matched/linked to the
   original received document from step 1 (it is effectively a duplicate).

### Direction inference for each step

- **Step 1** (TD01, seller != company, buyer == company): direction = `received`
- **Step 2** (TD16, seller == company, buyer == company): direction = `issued` (self-invoice type)
- **Step 4** (TD01 from SDI, same as step 1): direction = `received`

The `einvoice:import` command auto-detects direction correctly for all three steps.

### Common Natura codes for reverse charge

| Natura | Description |
|--------|-------------|
| N6.1 | Cessione di rottami e materiali di recupero |
| N6.2 | Cessione di oro e argento |
| N6.3 | Subappalto nel settore edile |
| N6.4 | Cessione di fabbricati |
| N6.5 | Cessione di telefoni cellulari |
| N6.6 | Cessione di prodotti elettronici |
| N6.7 | Prestazioni comparto edile e settori connessi |
| N6.8 | Operazioni settore energetico |
| N6.9 | Altri casi |

### Self-invoice document types (autofatture)

These TD types indicate self-invoices. When both seller and buyer match the company,
the `einvoice:import` command treats them as `issued`:

| TD type | Description |
|---------|-------------|
| TD16 | Integrazione fattura reverse charge interno |
| TD17 | Integrazione/autofattura per acquisto servizi dall'estero |
| TD18 | Integrazione per acquisto di beni intracomunitari |
| TD19 | Integrazione/autofattura per acquisto di beni ex art.17 c.2 DPR 633/72 |
| TD20 | Autofattura per regolarizzazione e integrazione |
| TD21 | Autofattura per splafonamento |
| TD22 | Estrazione beni da deposito IVA |
| TD23 | Estrazione beni da deposito IVA con versamento IVA |
| TD28 | Acquisti da San Marino con IVA (fattura cartacea) |
| TD29 | Acquisti da San Marino senza IVA (fattura cartacea) |

## Reverse charge lifecycle (inversione contabile)

When a supplier invoice has VAT with Natura N6.x (reverse charge), the buyer must
integrate the VAT by creating a self-invoice. The full lifecycle on FIC is:

1. **Register the received invoice** — The supplier's TD01 arrives with 0% VAT / Natura N6.x.
   Register it as a received document (fattura passiva) on FIC.
2. **Create a self-invoice (autofattura TD16)** — The buyer creates an issued document of type
   `self_supplier_invoice` that mirrors the original amounts but applies the correct VAT rate
   (e.g. 22%). This document references the original via `DatiFattureCollegate`.
   On FIC this is an issued document with `ei_type: TD16`.
3. **Send the self-invoice to SDI** — The TD16 is transmitted to SDI.
   `SoggettoEmittente` will be `CC` (cessionario/committente).
4. **SDI delivers back the self-invoice** — SDI sends the TD16 back as an incoming e-invoice.
   This appears as a new received e-invoice on FIC. It should be matched/linked to the
   original received document from step 1 (it is effectively a duplicate).

### Direction inference for each step

- **Step 1** (TD01, seller != company, buyer == company): direction = `received`
- **Step 2** (TD16, seller == company, buyer == company): direction = `issued` (self-invoice type)
- **Step 4** (TD01 from SDI, same as step 1): direction = `received`

The `einvoice:import` command auto-detects direction correctly for all three steps.

### Common Natura codes for reverse charge

| Natura | Description |
|--------|-------------|
| N6.1 | Cessione di rottami e materiali di recupero |
| N6.2 | Cessione di oro e argento |
| N6.3 | Subappalto nel settore edile |
| N6.4 | Cessione di fabbricati |
| N6.5 | Cessione di telefoni cellulari |
| N6.6 | Cessione di prodotti elettronici |
| N6.7 | Prestazioni comparto edile e settori connessi |
| N6.8 | Operazioni settore energetico |
| N6.9 | Altri casi |

### Self-invoice document types (autofatture)

These TD types indicate self-invoices. When both seller and buyer match the company,
the `einvoice:import` command treats them as `issued`:

| TD type | Description |
|---------|-------------|
| TD16 | Integrazione fattura reverse charge interno |
| TD17 | Integrazione/autofattura per acquisto servizi dall'estero |
| TD18 | Integrazione per acquisto di beni intracomunitari |
| TD19 | Integrazione/autofattura per acquisto di beni ex art.17 c.2 DPR 633/72 |
| TD20 | Autofattura per regolarizzazione e integrazione |
| TD21 | Autofattura per splafonamento |
| TD22 | Estrazione beni da deposito IVA |
| TD23 | Estrazione beni da deposito IVA con versamento IVA |
| TD28 | Acquisti da San Marino con IVA (fattura cartacea) |
| TD29 | Acquisti da San Marino senza IVA (fattura cartacea) |

## Set up webhooks for automation

```bash
# Subscribe to new invoice creation events
fic api:create-webhooks-subscription --company-id=COMPANY_ID --input='{
  "data": {
    "sink": "https://your-app.com/webhooks/fic",
    "event_types": [
      "it.fattureincloud.webhooks.issued_documents.invoices.create",
      "it.fattureincloud.webhooks.issued_documents.invoices.update"
    ]
  }
}'

# List active subscriptions
fic api:list-webhooks-subscriptions --company-id=COMPANY_ID --json
```

# XML import

## What the CLI does

`fic einvoice:import` accepts a single XML file or a folder of XML files and recreates each document through the Fatture in Cloud JSON APIs.

Signed `.xml.p7m` e-invoices are supported too.

That matters because the official API still does not support direct XML upload.

## Supported scope

- current FatturaPA XML structure (`FPR12` / `FPA12`)
- single file or recursive folder import
- import direction `issued` or `received`
- recap table before creation
- optional dry run
- embedded XML attachments are carried over; multiple attachments are bundled into a zip because Fatture in Cloud has a single structured attachment slot (`attachment_token`)
- `ei_raw` preservation so e-invoice-specific data is carried into the created document

## V.2025 notes

The CLI targets the current XML structure used by the 2025 technical specs (`specifiche tecniche 1.9`).

Important 2025 additions such as `TD29` and `RF20` are preserved inside `ei_raw`, so the recreated document keeps the XML nuance even though the import itself happens through JSON.

## Usage

```bash
# Preview a single XML
fic einvoice:import /absolute/path/to/fattura.xml --company-id=COMPANY_ID --dry-run

# Preview a signed XML.p7m file
fic einvoice:import /absolute/path/to/fattura.xml.p7m --company-id=COMPANY_ID --dry-run

# Import a folder of XML files (direction is auto-detected from XML content)
fic einvoice:import /absolute/path/to/xml-dir --company-id=COMPANY_ID --yes

# Machine-readable planning output
fic einvoice:import /absolute/path/to/xml-dir --company-id=COMPANY_ID --dry-run --json
```

The dry run should surface recognition details such as whether the selected company appears as seller or buyer, whether an existing client or supplier was matched, how many VAT rows were mapped cleanly, and whether references or attachments were recognized.

## Mapping strategy

- line items become `items_list`
- payment deadlines become `payments_list`
- the buyer or seller becomes the counterparty depending on import direction
- `TipoDocumento` is translated to the closest Fatture in Cloud document type
- issued XML imports set `e_invoice=true` by default and populate `ei_data` from the XML payment and invoice details
- `DatiOrdineAcquisto`, `DatiContratto`, and `DatiConvenzione` are promoted into structured `ei_data` fields such as original document type, number, date, CUP, and CIG when available
- `Causale`, withholding taxes, cassa previdenziale, and stamp duty are promoted into structured Fatture in Cloud document fields when the API supports them
- before creating documents, the importer compares `CedentePrestatore` and `CessionarioCommittente` against the selected company using VAT number, tax code, and fuzzy name matching
- if the counterparty already exists in Fatture in Cloud, the importer should reuse the existing client or supplier by id instead of creating an ad-hoc entity payload
- destination code is useful to populate e-invoice metadata, but it must not be used as an identity match key because it is not company-specific
- source XML is preserved in `ei_raw`
- VAT rows are matched against company VAT types by rate and, when present, natura/e-invoice code

## E-invoice-first behavior

In Italy, e-invoices are the normal case for XML documents.

- if the source is a FatturaPA XML and the import direction is `issued`, the recreated document should be electronic by default
- if the import direction is `received`, electronic XML is still the common case, but received documents may also come from non-electronic sources such as commercial receipts, foreign supplier invoices, or manually entered paper documents
- this means "non electronic" should be treated as the exception, not the default, when reasoning about XML imports in the skill
- if neither XML party matches the selected company, the XML can still be formally valid, but the importer should reject it as "not us"

## Caveats

- if an invoice number cannot be split into numeric `number` plus textual `numeration`, the CLI warns and lets Fatture in Cloud assign the numeric part for issued documents
- only the first `FatturaElettronicaBody` block is imported
- direct SDI submission still depends on the recreated document passing Fatture in Cloud validation
- for very custom fiscal cases, inspect the dry-run payload and the created document before sending to SDI

## Source docs covered

- `/docs/FAQs/`
- `/docs/guides/externally-generated-xml/`
- `https://fex-app.com/info/v2025`

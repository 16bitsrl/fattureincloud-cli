# API basics

This reference condenses the most useful Fatture in Cloud API behaviors for agents using `fic`.

## Filtering with `--q`

- Use SQL-like triplets: `field op value`
- Always quote strings with single quotes
- Combine clauses with `AND`, `OR`, and parentheses
- Fatture in Cloud URL-encodes queries at the HTTP layer; with `fic`, pass the raw query string

### Operators

| Operator | Syntax |
|---|---|
| equal | `=` |
| not equal | `<>`, `!=` |
| greater / less | `>`, `>=`, `<`, `<=` |
| like | `like`, `not like` |
| string helpers | `contains`, `not contains`, `starts with`, `ends with` |
| null checks | `is null`, `is not null`, `= null`, `!= null` |

### Examples

```bash
# Clients by VAT number
fic api:list-clients --company-id=COMPANY_ID --q="vat_number = 'IT01234567890'" --json

# Issued invoices in Q1 2025
fic api:list-issued-documents --company-id=COMPANY_ID --type=invoice \
  --q="date >= '2025-01-01' AND date <= '2025-03-31'" --json

# Received docs from a supplier, ordered later with --sort
fic api:list-received-documents --company-id=COMPANY_ID \
  --q="entity.name = 'Studio Beta S.r.l.'" --json
```

### Filterable fields

| Method | Main filterable fields |
|---|---|
| `listClients` | `id`, `code`, `name`, `vat_number`, `tax_code`, `address_city`, `email`, `ei_code`, `created_at` |
| `listSuppliers` | `id`, `code`, `name`, `vat_number`, `tax_code`, `address_city`, `email`, `ei_code`, `created_at` |
| `listProducts` | `id`, `name`, `code`, `net_price`, `gross_price`, `category`, `in_stock`, `created_at` |
| `listIssuedDocuments` | `type`, `entity.id`, `entity.name`, `date`, `number`, `numeration`, `amount_net`, `amount_gross`, `next_due_date` |
| `listReceivedDocuments` | `id`, `type`, `category`, `entity.id`, `entity.name`, `date`, `invoice_number`, `amount_net`, `amount_gross` |

## Sorting with `--sort`

- Use comma-separated fields
- Prefix with `-` for descending order
- Sorting applies to paginated lists, so keep `--page` and `--per-page` in mind

```bash
# Latest invoices first
fic api:list-issued-documents --company-id=COMPANY_ID --type=invoice --sort=-date --json

# Newest received docs, then cheapest first
fic api:list-received-documents --company-id=COMPANY_ID --sort=-date,amount_net --json
```

### Sortable fields

| Method | Main sortable fields |
|---|---|
| `listClients` / `listSuppliers` | `code`, `name`, `vat_number`, `tax_code`, `address_city`, `created_at`, `updated_at` |
| `listProducts` | `name`, `code`, `net_price`, `gross_price`, `category`, `created_at` |
| `listIssuedDocuments` | `entity.name`, `date`, `number`, `numeration`, `amount_net`, `amount_gross`, `next_due_date` |
| `listReceivedDocuments` | `id`, `entity.name`, `date`, `amount_net`, `amount_gross`, `next_due_date` |

## Pagination

- Default page size is 50
- Maximum `--per-page` is 100
- Use the response metadata: `current_page`, `last_page`, `total`, `next_page_url`

```bash
fic api:list-clients --company-id=COMPANY_ID --page=2 --per-page=100 --json
```

## Response customization

- Prefer `--json` for automation
- Use `--minify` when piping to tools
- Use `--headers` when diagnosing quotas, rate limits, or download URL expiration issues

## Source docs covered

- `/docs/basics/filter-results/queries/`
- `/docs/basics/sort-results/`
- `/docs/basics/paginate-results/`
- `/docs/basics/customize-response/`

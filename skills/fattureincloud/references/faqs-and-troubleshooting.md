# FAQs and troubleshooting

## Classic API limitations

- Raw XML upload is not supported by the official API; XML must be transformed into JSON documents or imported through the web ZIP history import flow
- List methods are always paginated; there is no "return everything" switch
- Download URLs can expire, so they should be consumed quickly and refreshed instead of cached forever

## Common errors

| Status | Meaning | Typical fix |
|---|---|---|
| `401` | Missing, invalid, or expired token | Run `fic auth:login` or `fic auth:refresh` |
| `403` | Missing scopes, plan restriction, or long-term quota hit | Check scopes, company permissions, plan usage, and `Retry-After` |
| `404` | Wrong company or resource id | Re-check `--company-id` and entity/document id |
| `409` | Business conflict | Inspect the API error payload and document state |
| `422` | Validation error | Read `validation_result` details in the response body |
| `429` | Short-term rate limit | Respect `Retry-After` and retry with backoff |

## Rate limits and quotas

| Limit | Value | Notes |
|---|---|---|
| short-term | `300 requests / 5 minutes` | Sliding window, per company, returns `429` |
| hourly | `1,000 requests / hour` | Fixed window, per company-app, returns `403` |
| monthly | `40,000 requests / month` | Public apps: per company-app; private apps: per company |

- Check `RateLimit-HourlyRemaining`, `RateLimit-HourlyLimit`, `RateLimit-MonthlyRemaining`, `RateLimit-MonthlyLimit`
- When the API sends `Retry-After`, wait that exact amount before retrying
- For bursts, use exponential backoff with jitter
- Short-term limits cannot be increased; the integration must behave better instead

## FAQ nuggets worth remembering

- To show richer customer details on issued documents, pass a real client entity rather than a partial ad-hoc entity
- "Il totale dei pagamenti non corrisponde al totale da pagare" usually comes from rounding differences; check the invoice totals guide
- If you only need a development setup, a manual token is enough; OAuth becomes relevant when distributing the integration
- There is a dry-run flag for e-invoice checks so you can validate before sending to SDI
- `net_price` is the single item price, not the extended line total

## Practical agent guidance

- Prefer helper commands like `clients:search` when you just need free-text lookups
- Prefer `--headers --json` when debugging quotas or HTTP-level issues
- If `403` and `Retry-After` are both present, treat it as quota exhaustion, not just a permission problem
- If importing XML, remind the user that the CLI recreates the document through JSON because the API still does not ingest XML directly

## Source docs covered

- `/docs/FAQs/`
- `/docs/basics/errors/`
- `/docs/basics/limits-and-quotas/`
- `/docs/guides/externally-generated-xml/`

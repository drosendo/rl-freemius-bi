# RL Freemius BI — Customer REST API

Read-only REST surface over the synced Freemius customer base, plus a forced-sync
endpoint. Everything is served from the plugin's own sync tables, so no Freemius
call happens per customer.

- Base path: `/wp-json/rl-fsbi/v1`
- Auth: WordPress authentication — cookie + `X-WP-Nonce`, or an **Application
  Password**. The caller must hold `manage_options` (401 when anonymous, 403 when
  authenticated without the capability).
- Caching: 1 hour (`transient`) for `/customers` and `/segments`. Add `refresh=1`
  to bypass (administrators only).

## `GET /customers`

One row per **customer**, not per licence. When a customer holds several licences
the most relevant one wins: `active` > `cancelled` (auto-renew off, period not
over) > `expired`, and within the same rank the one expiring last.

| Param | Type | Default | Rules |
|---|---|---|---|
| `page` | int | 1 | ≥ 1 |
| `per_page` | int | 100 | 1–500 |
| `status` | enum | `all` | `active`, `expired`, `cancelled`, `all` |
| `plan` | enum | `all` | `pro` (plan 3874), `self` (plan 4862), `all` |
| `marketing` | enum | `all` | `optin`, `no`, `all` |
| `updated_since` | ISO 8601 | — | only rows updated at/after this moment |
| `refresh` | `1` | — | bypass the cache (admin only) |

```bash
curl -s -u "agent@example.com:APP_PASSWORD" \
  "https://example.com/wp-json/rl-fsbi/v1/customers?status=active&plan=pro&per_page=5"
```

```json
{
  "page": 1,
  "per_page": 5,
  "total": 1096,
  "pages": 220,
  "generated_at": "2026-11-06T17:10:00+00:00",
  "cached": true,
  "skipped_no_email": 0,
  "email_unknown": 895,
  "customers": [
    {
      "user_id": "1708052",
      "email": null,
      "first_name": "",
      "created_at": "2026-06-29",
      "marketing_optin": false,
      "optin_at": null,
      "had_license": true,
      "updated_at": "2026-06-29T16:28:07+00:00",
      "license": {
        "id": "1989806",
        "status": "expired",
        "plan": "self",
        "plan_raw": "4862",
        "seats": 1,
        "activations": 0,
        "activated_ever": false,
        "created_at": "2026-06-29",
        "expires_at": "2026-06-29",
        "next_payment_at": "2027-06-29",
        "is_cancelled": true,
        "failed_payments": 0,
        "currency": "eur",
        "total_paid": 39.99,
        "payments_count": 1
      }
    }
  ]
}
```

Errors: `400 invalid_param` for bad enums/ranges; `401`/`403` for auth.

### Field notes and honest limitations

| Field | Available | Source | Note |
|---|---|---|---|
| `user_id` | yes | `licenses.user_id` | |
| `email` | yes | `rl_fsbi_users` (background crawl of `/users.json`) | `null` until the crawl has reached that user; see `email_unknown` |
| `first_name` | partial | `/users.json` | empty string for users the crawl has not reached |
| `marketing_optin` | yes | `/users.json` `is_marketing_allowed` | |
| `optin_at` | **no** | — | Freemius stores a boolean only; **always `null`** |
| `created_at` | yes | `licenses.created` | |
| `updated_at` | yes | max of `licenses.updated` and the user row | drives `updated_since` |
| `license.activated_ever` | yes | `licenses.activated` | "did they ever use it" |
| `license.activations` | **no** | — | the current site count is not exposed; **always `null`**, never `0` |
| `license.seats` | yes | `licenses.quota` | site allowance |
| `license.next_payment_at` | yes | `subscriptions.next_renewal` | `null` without a subscription row |
| `license.failed_payments` | yes | `subscriptions.failed_payments` | |
| `license.total_paid` / `payments_count` | yes | `payments` table | not converted; `currency` says which unit `total_paid` is in |

Rules the API follows: **a field the source does not provide is `null`, never `0`.**
`activations` and `optin_at` are the two such fields today. `GET /diagnostics`
reports this table machine-readably.

`total` and `pages` always describe the **filtered** set, before pagination.

### `updated_since`

`updated_since=2026-11-06T00:00:00Z` keeps only customers whose licence row or
user row changed at/after that instant. The timestamp is compared against
`max(licenses.updated, users.updated)` — Freemius does supply both, so no proxy
or sync-time fallback is needed. `updated_since=<now>` returns `total: 0`.

## `GET /segments`

Pre-computed counters over **licences** (not customers).

```bash
curl -s -u "agent@example.com:APP_PASSWORD" \
  "https://example.com/wp-json/rl-fsbi/v1/segments"
```

```json
{
  "generated_at": "2026-11-06T17:10:00+00:00",
  "segments": {
    "active_pro": 115,
    "active_self": 97,
    "expired_0_12m": 82,
    "expired_12_36m": 220,
    "expired_36m_plus": 666,
    "never_activated": 32,
    "refunded": 105,
    "mrr_eur": 799.93,
    "arr_eur": 9599.16
  }
}
```

- `expired_*` bucket every expired licence by how long ago it expired.
- `never_activated` counts **active** licences that never had an activation.
- `refunded` counts distinct customers with at least one refund payment.
- `mrr_eur` is the consolidated figure: each active subscription is monthlyised,
  converted to EUR with the stored rate and summed — never a single currency
  bucket. `arr_eur` is `mrr_eur × 12`.

## `GET /sync-status`

Progress of the background user crawl (emails + opt-in flags).

```bash
curl -s -u "agent@example.com:APP_PASSWORD" \
  "https://example.com/wp-json/rl-fsbi/v1/sync-status"
```

```json
{
  "state": "running",
  "offset": 250,
  "total_known": 6400,
  "with_email": 6381,
  "last_run": "2026-11-06T17:40:56+00:00",
  "last_error": null,
  "cycle_done": null,
  "plugin_index": 0,
  "plugins": [1234]
}
```

The crawl runs every 5 minutes, 50 pages (or 90 seconds) per run, and persists
its cursor after **every page** — killing the job loses at most one page.
When a plugin's user list is exhausted the cursor moves to the next plugin; at
the end of the list the cycle is marked complete and the cursor resets to 0.

Manual backfill: `wp rl-fsbi sync-users --all`.

## `GET /diagnostics`

Per-field availability and crawl counters — use it to tell "no data" apart from
"no source".

```bash
curl -s -u "agent@example.com:APP_PASSWORD" \
  "https://example.com/wp-json/rl-fsbi/v1/diagnostics"
```

```json
{
  "generated_at": "2026-11-06T18:05:00+00:00",
  "crons": {
    "rl_fsbi_user_sync": "2026-11-06T18:09:12+00:00",
    "rl_fsbi_rest_warm": "2026-11-06T18:46:21+00:00",
    "rl_fsbi_api_log_cleanup": "2026-11-06T18:40:07+00:00"
  },
  "cache": {
    "dataset_built_at": "2026-11-06T18:04:00+00:00",
    "dataset_cached": true,
    "segments_cached": true,
    "last_warm_error": null
  },
  "api_log": {
    "rows": 9,
    "retention_days": 90,
    "last": { "route": "customers", "rows": 1, "at": "2026-11-06 18:04:00" }
  },
  "counts": { "licences": 1180, "users": 17084, "users_with_email": 17084 },
  "fields": {
    "license.activations": { "available": false, "source": null, "note": "current site count is not exposed; only activated (ever)" },
    "optin_at": { "available": false, "source": null, "note": "Freemius stores a boolean only, no timestamp" }
  }
}
```

## `POST /sync`

Forces a licence re-sync from Freemius, drops the REST caches and rebuilds them.
Rate limited to one call per 60 seconds per user.

```bash
curl -s -X POST -u "agent@example.com:APP_PASSWORD" \
  "https://example.com/wp-json/rl-fsbi/v1/sync"
```

```json
{ "ok": true, "synced_at": "2026-11-06T17:12:04+00:00", "records": 1096, "duration_ms": 21340 }
```

On a second call within a minute:

```json
{
  "code": "rate_limited",
  "message": "A sync was requested less than a minute ago. Retry in 42 seconds.",
  "data": { "status": 429, "retry_after": 42 }
}
```

## Privacy and secrets

- Every route requires `manage_options`.
- Responses never contain `secret_key`, `public_key` or any Freemius credential;
  per-user `secret_key`/`public_key` from `/users.json` are discarded while the
  user map is built.
- Licence keys are not returned at all.
- Emails are returned only as data, never written to the PHP error log.

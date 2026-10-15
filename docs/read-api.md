# External read API v1

The API gives external scripts a conservative view of configured sites and their latest observations. It has two endpoints:

```http
GET /api/v1/sites?limit=50&after_id=0
GET /api/v1/sites/1
Authorization: Bearer <your read API token>
```

Use HTTPS outside loopback development. Supply the token in the Authorization header inside the client process; keep it out of URLs, shell arguments, shell history and request logs. Your HTTP server or proxy must forward Authorization. Forwarding through a particular production installation has to be verified there. No CORS permission, JSONP or browser bearer adapter is provided.

Create the token in **Settings → Read API** after signing in. The normal application upgrades the database to schema 10; API requests never perform upgrades. The new token table starts empty, so external access starts off. Creating a token atomically replaces the previous token and shows the new secret once in the direct private HTML response after commit. Save it immediately. A lost response requires another rotation; the plaintext cannot be recovered. Revoke removes the token. There is one token, with no names, scopes or automatic expiry.

The token contains 256 random bits and the database stores only its domain-separated SHA-256 digest, the current administrator credential fingerprint and creation time. Password changes and password rehashes invalidate the credential binding. Browser logout, browser idle expiry, vault keys and Git credentials are independent. Cookies cannot authenticate these endpoints, and this token cannot authenticate browser actions. Token management requires the current administrator cookie and CSRF token, and revalidates credentials and the committed idle policy after acquiring its write lock. Concurrent rotations are serialized; the last committed rotation wins. A read snapshot admitted before a concurrent revoke can finish; requests after that commit reject the old token.

Lists default to 50 sites and accept `limit` from 1 through 100 and `after_id` from `0` through `9223372036854775807`. IDs, configuration revisions and cursors are canonical decimal **strings**. Detail IDs start at `1`. Paging follows the numeric primary key in ascending order and uses a limit-plus-one sentinel. `page.next_after_id` is the last returned ID when another page was observed, otherwise null. Pages are live reads: edits, insertions and deletion gaps may be visible on later requests. There is no total count or snapshot across pages.

Only the literal ASCII parameters above are accepted. Duplicate, unknown, bracketed, encoded, signed, empty, whitespace, leading-zero and overflow values are rejected. Detail requests accept no query. Declared or observed request bodies are rejected. The raw query limit is 1024 bytes, the path limit 256, Authorization limit 256, and encoded response limit 262144 bytes. SQL projections bound acquired string bytes, reject malformed numeric types and validate the complete DTO before success. PHP and the web server already parse the incoming request: a SAPI may merge or discard duplicate headers, normalize a target, reject framing before PHP, or suppress HEAD response bodies. These limits do not claim control over those server-owned allocations or framing decisions.

Every response has `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store, private` and `X-Content-Type-Options: nosniff`. There are no login/setup redirects or HTML API errors. Missing or malformed bearer syntax returns 401 before Composer or database access. A well-formed wrong, revoked or credential-invalid token returns 401 before resource, method or parameter disclosure, once an existing compatible database can be read. An unavailable, missing or incompatible installation with well-formed bearer syntax returns fixed 500. Recognized authenticated routes accept only GET; HEAD, OPTIONS and other methods return 405 with `Allow: GET` (HEAD bodies may be suppressed).

| Status | Error code | Fixed message |
| --- | --- | --- |
| 400 | `invalid_parameter` | Invalid request. |
| 401 | `unauthorized` | Authentication required. |
| 404 | `not_found` | Not found. |
| 405 | `method_not_allowed` | Method not allowed. |
| 500 | `unavailable` | Service unavailable. |

Errors are `{"api_version":1,"error":{"code":"unauthorized","message":"Authentication required."}}`; 401 includes `WWW-Authenticate: Bearer realm="tablo-read-api"`. They do not contain request input, SQL, exception messages or provider diagnostics.

A detail response looks like this neutral example:

```json
{"api_version":1,"generated_at":"2026-10-14T10:00:00Z","data":{"id":"1","config_revision":"0","name":"Example site","origin":"https://example.test","provider":"github","repository":"fixture/public","branch":"main","enabled":true,"sort_order":0,"comparison_mode":"release","health_check_mode":"http","health_target":"endpoint","tracks_version":false,"created_at":"2026-10-14T09:00:00Z","updated_at":"2026-10-14T09:00:00Z","latest":{"status":"partial","checked_at":"2026-10-14T09:59:30Z","freshness":"fresh","online":true,"health_error_code":null,"health_http_status":200,"response_time_ms":0,"open_issues":0,"open_prs":null,"comparison":"not_tracked"}}}
```

The [JSON schema](../contracts/read-api-v1.schema.json) defines the exact whitelist and types. Configuration includes bounded name, repository, branch, enabled/sort/modes and whether health targets the homepage or an endpoint. Origin contains only a validated HTTP(S) scheme, host and optional port. Suspicious legacy URLs with user information, query, fragment or malformed host yield null. These administrator-supplied configuration fields may themselves contain private text; the contract makes no semantic secret-detection promise.

The external DTO omits credentials and their presence/identity/hash/fingerprint, vault material, notification destinations and payloads, full URLs and paths, JSON selectors/expected values, raw diagnostics, response bodies/headers, and raw remote version/tag/commit strings. It exposes only the resulting finite comparison. This limited contract serves scripts needing status; a future same-origin dashboard presentation adapter is separate work and must not put this bearer in browser JavaScript.

`latest.status` describes completeness of exposed observations: `paused` retains prior observations; without a check it is `unknown`; otherwise known health/issues/PRs and, when tracked, comparison determine `unknown`, `partial` or `complete`. Offline is a known observation. Null means unobserved, while `false` and actual zero remain values. Completeness does not assert that every upstream request succeeded. Persisted state has one shared `checked_at`, with no per-field success/error timestamps; the API invents none.

All times use actual canonical UTC seconds (`YYYY-MM-DDTHH:mm:ssZ`); invalid stored timestamps cause fixed 500. `generated_at` is shared by the response. Freshness is `never_checked`, `clock_invalid` for future checks, `fresh` through age 900 seconds, and `stale` at 901 seconds. Pausing does not rewrite age. Untracked versions give `not_tracked`; missing or malformed private operands give `unknown`. Release comparison normalizes a leading v before a digit and can report `current`, `update_available`, `ahead` or `different`; branch-prefix equality can report `current` or `different`, with no ancestry claim.

The reader opens only an existing database without CREATE, enables and confirms SQLite `query_only`, requires the current schema and checks necessary main-table metadata. It performs no migrations, WAL negotiation/checkpoints, full foreign-key scan, key reads/decryption, session/runtime initialization, token last-used writes, provider calls, checks, history/outbox/worker operations or selected installation DDL/DML. `query_only` is a logical SQL write restriction, not a filesystem immutability guarantee. Ordinary output and exceptions are contained; deliberate process exit, destroyed buffers or already-flushed transport cannot be universally undone.

The path and envelope fix version 1. Breaking types or meanings require v2; clients may ignore documented additive fields in a future compatible revision. This task adds no site write API, polling, SaaS accounts or token collections.

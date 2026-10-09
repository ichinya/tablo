# GitHub polling policy

Tablo uses serial REST requests with concurrency one within each check process/request. It does not retry or sleep after API failures. Successful health, version and GitHub fields survive failures in other metrics. Failed/deferred GitHub fields remain unknown with a safe explanation in `last_error`; no upstream error body or authorization header is displayed.

The response retains only six bounded rate headers: `retry-after`, `x-ratelimit-limit`, `x-ratelimit-remaining`, `x-ratelimit-used`, `x-ratelimit-reset`, `x-ratelimit-resource`. Names are case-insensitive. Interim blocks are discarded. Numeric values must fit 18 decimal digits; resource is `core` or `search`. Duplicate, conflicting or invalid values become unusable, never arbitrary response text.

401, plain 403 and inaccessible repository/branch 404 produce an `access` reason. A 429, exhausted primary window, Retry-After evidence or recognized secondary-limit message produces `rate-limit`. Transport, malformed/incomplete data and other unsuccessful statuses produce `unavailable`. `budget` and `credential-changed` are separate typed reasons. A release 404 becomes a successful no-release value only after repository metadata confirms access.

Primary cooldown is separate for core and search and scoped by anonymous access, saved credential ID, manual site ID, or authenticated unsaved preview. Same-ID token replacement preserves that handle's quota. Anonymous exhaustion does not block authenticated handles. Distinct tokens can still belong to the same GitHub account: Tablo cannot discover their account relationship without additional requests. Secondary cooldown is conservatively shared by the installation across every credential and anonymous access. A plain access failure does not establish shared cooldown.

Schema version 3 adds `github_cooldowns(scope, resource, eligible_at)` atomically from version 2, independently of WAL configuration. SQLite upsert keeps the maximum eligibility time; no write transaction spans HTTP. Web checks and CLI sweeps use the same database. No token or token digest is stored in cooldown keys. The most restrictive usable reset/Retry-After is honored with at least a 60-second conservative fallback; server timing is never truncated to the local request budget. Expired eligibility permits the next explicit attempt. There is no automatic retry queue.

One connection owns an in-memory memo of at most 256 validated semantic metric results. It ends with the CLI sweep or web request. Exact metric/repository/branch and a salted opaque effective credential/revision identity isolate values. Anonymous and authenticated results are separate. Only confirmed no-release values are cached; access, rate, malformed or incomplete results are not. Fresh credential checks run before and after loads and memo hits. Rotation/removal rejects an old provider and invalidates its memo; name-only token edits retain the revision. Existing configuration/credential snapshot guards still reject stale storage writes.

Monitoring allows at most five upstream attempts per uncached site, with a 24-second GitHub scheduling deadline. Branch enumeration allows eleven attempts, including repository metadata. A connection permits at most 100 attempts and a 120-second scheduling deadline. Memo hits do not consume attempts. Every new request checks both deadlines and cooldown; cURL's existing six-second total/three-second connection timeout is clamped to remaining time. Health/version are independent of this GitHub budget.

**These are scheduling/cURL budgets, not hard wall-clock limits.** SSRF preflight uses synchronous system DNS outside cURL. DNS can overrun the deadline; Tablo checks the deadline again when it returns and does not start HTTP after an overrun. Existing SQLite contention and health/version work can also extend total CLI duration. Independent web/CLI processes can have already-started requests in flight when another observes a limit. This issue adds neither a global scheduler nor distributed request admission.

The periodic worker must schedule deferred search metrics fairly rather than always prioritizing the first sites. A serial 20-site sweep of unique repositories still costs 40 searches; it can exceed GitHub's authenticated or anonymous search window. Public tokenless operation remains supported with partial/deferred results, without promising complete frequent anonymous coverage. See [GitHub rate limits](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api) and [REST best practices](https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api).

## Measured request counts

`tests/Network/GitHubSweepTest.php` exercises actual connection/provider/checker/storage with real cURL to a localhost synthetic REST server. Twenty sites per cell, five milliseconds of server delay and four milliseconds of fixture pacing, successful health adapter, optional version disabled. Timings are local fixture evidence, not live GitHub latency or observed quota.

| Credential mode | Repositories | Release present core/search | No release core/search |
| --- | ---: | ---: | ---: |
| Shared saved token | 1 | 2/2 (4 total) | 3/2 (5 total) |
| Shared saved token | 20 | 40/40 (80) | 60/40 (100) |
| 20 distinct saved tokens | 1 or 20 | 40/40 (80) | 60/40 (100) |
| Anonymous | 1 | 2/2 (4) | 3/2 (5) |
| Anonymous | 20 | 40/40 (80) | 60/40 (100) |

The previous implementation made 80/100 calls in every cell. Reuse saves 95% only for matching credential/revision and repeated requests. A 20-site all-429 secondary fixture sends one upstream request, then stores partial/health results without further GitHub requests. ETag, GraphQL, concurrency and persistent response caching were not selected; the measured bottleneck does not justify them.

Regression coverage includes real header capture/conflicts, cURL timeout and delayed DNS, zero/exhausted budgets, primary scope isolation and secondary sharing, persisted web/CLI eligibility, successful remaining-zero, conservative invalid timing, safe failure categories, no-release access confirmation, branch/token isolation, same-second/same-ID/in-flight credential rotation, stale result rejection, bounded memo eviction and atomic migration rollback. Contract capture is explicit; verification never recaptures fingerprints.

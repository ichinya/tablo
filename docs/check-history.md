# Check history

Schema 5 adds an initially empty `check_history` table. It preserves existing sites, last results,
administrator, settings, encrypted credentials and cooldowns. Each accepted check appends one
immutable row with its site configuration revision and measurement time. A current older result
may still be accepted under the existing policy; its original time is recorded. Editing a site
does not rewrite its history. Deleting a site cascades its history.

Manual checks and the one-shot CLI share the same guarded result/history transaction as worker
settlement. Worker settlement also credits only admitted fields in that transaction. Network work
finishes before the short immediate transaction starts. Stale, disabled, deleted, rotated or ABA
snapshots append nothing and earn no credit. Validation, INSERT, credit and COMMIT failures roll
back the pair; a failed BEGIN leaves caller transactions untouched.

Rows contain nullable health, fixed typed diagnostic codes and HTTP statuses, bounded response
time, version/release strings of at most 200 bytes, and validated commit SHA scalars. They exclude
raw error messages, bodies, headers, URLs, credentials and quota identities. Version strings are
upstream application data: this is a bounded projection, not a guarantee that their contents are
secret-free. Git or version failures preserve independently valid health and partial successes.
The existing current-state API, counts and `last_error` presentation remain unchanged.

## Read a page locally

```sh
php bin/history.php SITE FROM TO [LIMIT [CURSOR]]
php bin/history.php 1 2026-01-01T00:00:00Z 2026-02-01T00:00:00Z
```

This trusted-local CLI outputs JSON with `rows` and `next_cursor`. Times must be calendar-valid
RFC 3339 whole seconds with `Z` or a numeric offset; they are canonicalized to UTC. The interval
is half-open `[from,to)` and requires `from < to`. The site must exist. Limit defaults to 50 and
accepts 1 through 100. Follow `next_cursor` with the same site and interval. Rows are ordered by
descending measurement time, then ID. The bounded cursor holds the first page's global ID ceiling,
so later insertions are excluded even if their measurement is older. Deletion or retention may
reduce later pages; no durable snapshot or fixed total count is promised. Cursors are validated
local traversal state, not authenticated capabilities. Reads perform no HTTP, Git requests, key
creation or cleanup, and hold no transaction between pages.

## Prune locally

```sh
php bin/prune-history.php [SITE]
```

`TABLO_HISTORY_RETENTION_DAYS` defaults to 30 when absent. It requires a canonical decimal integer
from 1 through 3650; empty, zero, leading zeros and malformed values fail before destructive work.
Each invocation captures one UTC cutoff and deletes only rows strictly older than it, retaining
equality. Optional SITE restricts every batch to one existing site. Each immediate transaction
deletes at most 500 rows; an invocation commits at most ten batches (5000 rows). Output reports
`deleted` and `capped`; `capped: true` means the limit was reached and eligible rows may remain.
Earlier batches survive a later failure, and another invocation resumes. New rows at/after the
cutoff survive; newly inserted old rows are eligible for a subsequent batch or run.

Prune runs explicitly, separately from worker passes and reads, so it adds no worker stop/lock
or network scheduling behavior. It never changes current last results. Logical deletion does
not shrink the database or securely erase SQLite pages, WAL files or backups. This feature adds
no VACUUM, truncation or checkpoint policy.

Both CLIs return nonzero with fixed private-safe errors for invalid arguments or local storage
failure. They use the existing local database configuration; no new HTTP history API or UI is added.

# Webhook notifications

The installation starts with notifications off. Configure one HTTPS webhook in Settings,
select unavailable, recovery and/or version lag, then enable it. Saving or viewing settings
never sends a request. Blank endpoint/Bearer fields keep existing encrypted values; explicit
removal clears them. A revision conflict or active delivery returns a safe retry response.
Endpoints, Bearer values and opted-in payload fields use the existing vault and original key.
The external-key startup witness and lifetime policy covers all these ciphertexts.

Only accepted current checks produce events. Their current row, history, actual incident,
notification checkpoint/slot and admitted worker credit commit on the same PDO. Stale,
disabled, edited, rotated or deleted snapshots cannot enqueue. Network delivery follows the
commit and does not hold a database transaction or session lock. Notification failure never
changes the accepted health result and cannot prevent the next site's check.

Unavailable consumes the actual persisted incident opening ID. It needs two distinct
accepted Offline observations with at least 60 seconds between both sample and received
wall times. Unknown, late samples and backward wall time suspend confirmation; timers do
not supply evidence. First Offline is an observation, not an assertion of earlier or
continuous downtime. Pause/configuration changes never manufacture recovery. Recovery is
the actual same-epoch incident closure. If both subscriptions are chosen, recovery before
the first unavailable attempt cancels the pair. Recovery-only subscription is immediate.
History pruning may remove old timestamp anchors; stable incident identities persist.

Version lag needs successful accepted deployment/target data. Numeric release versions
use the existing leading-v/version_compare convention: lower qualifies, equal/ahead clears,
and arbitrary tags or partial failures are Unknown. Branch mismatch is labelled
`branch_difference`, without a commit-ancestry claim. Unknown restarts confirmation but
does not rearm a run; known catch-up resets it and cancels obsolete pending work. Ongoing
target changes while lagging retain one opening ID. Configuration epochs start fresh.
Channel replacement does not replay an unchanged handled transition. Activation is
future-only; migration/settings reads never replay historical incidents/checks.

There are at most three slots per site, one per kind. A newer same-kind event replaces
the slot, increments an observable coalesced count if pending/inflight work was discarded,
and fences its old ACK. This is bounded current delivery state, not a complete event log.
Each slot expires after one hour and has at most three attempts. Failures retry after
at least 60 seconds, then 300 seconds. A crash before sending still consumes its claim;
expired inflight claims are explicitly rescheduled after the lease, retaining the key.
Each manual action, one-shot CLI sweep or periodic pass updates at most 32 due invalid,
expired or lost-lease slots, then claims at most one eligible event for delivery. A separate
indexed eligible selection excludes expired/stale work even when the maintenance cap is
reached, so an expired backlog cannot consume a fresh recovery's TTL. Remaining maintenance
resumes on subsequent invocations; expiry/status observations are not a complete event log.
Due work resumes only on a later invocation; there is no queue daemon or sleeping retry.

Delivery is bounded best effort. A receiver may accept before its response/local ACK is
lost, so duplicates are possible. `Idempotency-Key` is the installation namespace, event
kind and immutable opening identity; receivers should atomically deduplicate it. `sent`
means a bounded 2xx response and conditional local ACK were observed. It does not mean a
unique remote effect, unconditional delivery or exactly-once HTTP.

The fixed JSON schema is `tablo.notification.v1`: event_id, event, subject_id, observed_at
and optional finite comparison. Names/URLs are excluded by default. Explicit opt-ins add
display_name and site_origin only (scheme, host, explicit port; no path/query/credentials).
The endpoint, Bearer, repository, response, exception and key identity never enter payloads
or status output. This implementation narrows payloads to 1024 UTF-8 bytes so its binary
stdin frame, including 500-byte endpoint and 512-byte Bearer, is at most 2048 bytes.
Oversized payloads fail visibly without launching a child.

Every attempt resolves all answers, accepts only globally routable unicast, pins the
validated address to the original HTTPS hostname and verifies its TLS certificate. Proxy
environment and redirects are disabled. Body/header caps are 4096/16384 bytes. Global
IPv6 policy is deliberately conservative: allocated 2000::/3, excluding special-purpose
2001::/23, documentation and transition ranges. Existing generic GET behavior is unchanged.

A single minimal PHP child receives the selected secrets only through private stdin.
It has no bootstrap/database/session and starts no descendants. Parent supervision uses
a 10-second monotonic budget including synchronous DNS, cURL up to 6 seconds/connect up
to 3 seconds, then at most one second of observed stop grace. Spawn, pipe and OS scheduling
overhead are additional; this is not a hard OS/process-tree guarantee. The <=2048-byte
frame fits observed supported anonymous-pipe buffers, including a child that never reads.
Native Windows requires public SystemRoot/WINDIR for networking; these are the only child
environment entries. Secret-bearing parameters/native failure boundaries are redacted;
this does not claim inspection of arbitrary opaque object graphs.

An unverified child stop retains local custody and marks the channel blocked durably;
further attempts refuse. There is no automatic unblock/retry after that condition. An
operator must establish process termination before offline repair of the blocked flag.
Settings and delivery share a nonblocking stable installation mutex. A successful setting
change follows confirmed termination; an already accepted receiver request cannot be
recalled. Site edits/deletion can invalidate inflight ACKs but cannot recall that request.

Tests use owned loopback/TLS receivers and disposable SQLite/key/runtime fixtures. They
are same-host evidence, not external network, real DNS outage or production delivery proof.

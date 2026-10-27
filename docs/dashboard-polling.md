# Saved dashboard refresh

The browser reads `GET /dashboard/state` using its existing administrator cookie.
It sends no Git or external API bearer. Local view/search/filter controls remain
local; only the independent refresh scheduler requests saved state.

The endpoint has the existing Web initialization policy, including configured
database/schema, vault and session admission. It is not a separate key-free or
migration-free ingress. After admission its handler selects only saved presentation
columns, renders the same Volt partials as `/`, and revalidates current session
authority after releasing the session lock. It calls no site check, Git provider,
history/notification writer or delivery operation, and does not consume the notice.

Automatic requests advance only session observation time. They preserve the original
login time and last human activity; the current idle policy and absolute 12-hour
limit still apply. Prepared data is discarded if the session changes while unlocked.
Browser expiry returns JSON 401 without a login redirect or protected snapshot.

## Response

A success is private `no-store` JSON with exactly these fields:

```json
{
  "dashboard_version": 1,
  "csrf": "<the current browser-session CSRF token>",
  "stats": {"total": 0, "online": 0, "attention": 0, "paused": 0},
  "html": "<shared canonical forms and Volt tile/table fragments>"
}
```

The projection does not select credentials, encrypted values, token snapshots,
health/version endpoint paths or JSON selectors. Display fields use the same
Presenter and escaped Volt templates as the authorized dashboard, including configured
site/repository links and bounded upstream version text. This browser-private display
is separate from the conservative external API DTO.

There is no 100-site limit. A saved projection is admitted against an 8 MiB raw-value
budget and the complete encoded response against a 2 MiB UTF-8 JSON budget. Exceeding
either refuses the whole automatic snapshot with HTTP 413 and fixed `too_large`
diagnostics. The browser retains the complete previous display and offers a page
refresh; the original server-rendered dashboard is not truncated by this limit.

## Browser lifecycle

One completion-scheduled timer waits 15 seconds after success. One operation owns
fetch, streamed body reading and decoding, with a 10-second complete-response
deadline. Network/server errors use 30/60/120-second delays, capped at 120 seconds;
a later success restores the normal cadence. Rapid visibility/network toggles do
not reset eligibility or create overlapping operations.

Hidden tabs, offline state and pagehide pause and invalidate pending results.
Native form navigation aborts refresh before submission. A restored page resumes
under the same pending-navigation and session rules as the original dashboard.
Authentication refusal, redirect/HTML, incompatible envelope, changed CSRF or an
oversized body pauses with an accessible recovery link. Client body admission counts
actual streamed bytes instead of trusting Content-Length.

A complete admitted snapshot updates both views, membership/order, counters and
empty-state hints together. Search/filter/mode/history state, expanded errors,
focused actions and document/table scrolling are preserved. Removed or hidden focused
sites return focus to the active view switch. Native CSRF forms and duplicate-submit
guards remain effective for both existing and newly added sites.

## Verification

`composer verify` runs PHP request, session, projection and normal dashboard regressions
with Mago/Lekalo. The CI Node step additionally runs the actual production scheduler's
fake-clock tests together with the existing lifecycle controls:

```sh
node --test --test-concurrency=1 tests/Browser/control-lifecycle.test.mjs tests/Browser/dashboard-polling.test.mjs
```

The browser acceptance harness uses the owned PHP fixture and `polling: true` config
to exercise real saved-row changes and more than 100 sites, preserving the existing
outbound-traffic and child-cleanup guards. Playwright/Chrome are test tooling, not
application dependencies. MySQL/MariaDB flow tests exercise the same cookie endpoint;
no database migration or additional database grants are introduced.

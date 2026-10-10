# Periodic checks

Run `php bin/worker.php` in the foreground with the same installation `.env` or exported `TABLO_DB` as the web application. The authenticated `/settings` form saves positive whole minutes in SQLite; default 10. Decimal leading zeros normalize. The upper technical bound is `PHP_INT_MAX / 60` whole minutes. Invalid input is rejected without changing the setting.

Passes are finite and serial. A fresh GitHub connection owns one aggregate policy/memo per pass. Each enabled ID is reloaded immediately before checking. Site failures continue to the next site with fixed diagnostics. After completion the worker reads the committed interval and waits that many minutes on a monotonic clock. A setting changed during a pass controls the following wait; a setting changed during a wait controls the wait after the next pass. There are no overlap, catch-up or provider retry loops. Small wait chunks hold no DB transaction, cursor or web session lock.

Stop with `php bin/worker.php --stop` using the same database configuration. This records a request for the observed run generation; it does not prove termination. Observe the foreground process exit before relaunching. The current synchronous site operation can finish before the request is handled. Linux images include PCNTL for SIGINT/SIGTERM; Windows supports the exercised cooperative `--stop` path. Windows console Ctrl+C/Break delivery is not claimed. Forced exit is distinct from cooperative completion; OS lock release and SQLite/result guards permit restart.

The lifetime nonblocking file lock is beside the actual canonical `PRAGMA main.database_list` database file, and stays held through waits. Exit 2 means lock contention; exit 1 means startup/global failure, including open/lock errors; exit 64 means invalid arguments; exit 0 means cooperative stop. Never delete or replace a live database or its `.worker.lock` file. Memory/temporary databases are rejected. Different source roots/CWDs and resolvable symlink aliases for one file contend; distinct database files are independent. Supported storage is ordinary same-host local storage with working file locks and accepted WAL runtime. Hard links, NFS/SMB/cloud-sync and multiple hosts are outside this installation layout.

For Docker Compose:

```sh
docker compose up -d --build tablo tablo-worker
docker compose stop tablo-worker
docker compose restart tablo-worker
```

Both services use the same built `tablo-local` image and local `tablo-data` volume. `TABLO_DB` is identical; the token vault key is the adjacent `github-token.key`, shared by that volume. Worker runs as `www-data`, has no published port, and uses init plus SIGTERM with 30 seconds supervisor grace and at most three failure restarts. Existing populated storage must be writable by uid/gid 33, including SQLite sidecars, key and stable lock. A changed image/environment requires `up --build`/recreation; `restart` only restarts existing container configuration. Preserve the complete storage directory for backup/restore. Do not copy only the main SQLite file while WAL clients are active.

Thirty seconds is supervisor grace, not a DNS/site/pass wall-time guarantee. Synchronous DNS occurs outside cURL's timeout, health/version and SQLite work add time, and the policy budget limits admission rather than whole-pass elapsed time. Work exceeding grace can be force-killed. Stop web, worker and external one-shot schedules before offline maintenance; restore DB and its original vault key together. Deploy both services from the same revision because older binaries reject schema 4.

Worker priority keeps one bounded row per site with four field service turns. Currently cooled resources do not pin priority. Admission failure receives no service credit; admitted upstream failures and validated memo/no-release completion do. Successful core fields leave search debt intact. Hints survive restart, reset with configuration revision and cascade on deletion; they contain no responses, URLs, tokens or history. Recurring positive quota gives finite eligible fields service opportunities; permanently unavailable quota/credentials have no freshness guarantee. Exact-token equivalence remains the accepted policy's proven identity; distinct tokens are not assumed to be distinct accounts.

Health/version still run when GitHub resources defer. `checked_at` records the site pass, not freshness of every GitHub metric. Partial/deferred fields remain explicit. Manual HTTP checks and `php bin/check.php` retain their default order, output and result whitelist. They may overlap the worker; worker singleton does not serialize all manual/one-shot quota admission. Configuration revision plus every existing snapshot/token predicate rejects intervening edits, disable-enable ABA and token rotation. Rename-only saved-token metadata edits keep snapshots compatible.

Tests use isolated SQLite/key/runtime directories, deterministic clocks, counted local HTTP and owned child processes. Production credentials and real GitHub quota are unnecessary. Default Mago and semantics are separate gates; no broad lint suppression is introduced.

# SQLite, MySQL and MariaDB

SQLite is the default. MySQL 8.4 and MariaDB 11.4 are explicitly selected
backends, with independent schema descriptors and admission checks. Selecting a
server requires `pdo_mysql`; a SQLite-only installation does not require it.
An unavailable driver, invalid configuration, unknown engine family, unavailable
catalog or incompatible schema fails the operation. There is no engine fallback.

The default `compose.yaml` retains the existing SQLite deployment. The server
examples use separate database, maintenance, writer, reader and metadata credentials.
Neither web requests nor workers run as a database administrator.

For a fresh Compose service, materialize only the catalog/account/migrator section
of `deploy/server-grants.sql.example` as private initialization SQL. After the
maintenance container has initialized the tables, apply the writer/reader section
with the administrative client. The template's two sections run at different
stages; do not mount the complete template as a single initialization script.
Use the server example and `compose.server-existing.yaml` in the same Compose
project, set the application hostname to `database`, and issue a matching server
certificate. For an existing remote service, use only the existing-server example
with that server's verified hostname and CA.

## Configuration

`TABLO_DB` remains a SQLite filename or admitted SQLite `file:` URI. It is never
a server DSN. An absent `TABLO_DB_DRIVER` selects SQLite when all server fields
are absent or empty. An explicitly empty or unknown driver is invalid. Server
fields conflict with SQLite selection, and a nonempty legacy `TABLO_DB` conflicts
with either server engine.

For a server, explicitly set:

```dotenv
TABLO_DB_DRIVER=mysql
TABLO_DB=
TABLO_DB_NAME=tablo
TABLO_DB_HOST=database.example.invalid
TABLO_DB_PORT=3306
TABLO_DB_USER=tablo_writer
TABLO_DB_PASSWORD=<from-private-runtime-configuration>
TABLO_DB_READ_USER=tablo_reader
TABLO_DB_READ_PASSWORD=<from-private-runtime-configuration>
TABLO_DB_MIGRATE_USER=tablo_migrator
TABLO_DB_MIGRATE_PASSWORD=<from-private-runtime-configuration>
TABLO_DB_METADATA_USER=tablo_metadata
TABLO_DB_METADATA_PASSWORD=<from-private-runtime-configuration>
TABLO_DB_TLS=verify
TABLO_DB_TLS_CA=/run/secrets/database-ca.pem
TABLO_TOKEN_KEY_FILE=/run/secrets/tablo-installation.key
```

Use `mariadb` for MariaDB. Provision the catalog before running Tablo maintenance.
Catalog identifiers contain 1–64 ASCII letters, digits or underscores. TCP needs
an explicit host and canonical decimal port from 1 to 65535. The special
`localhost` socket shortcut is refused. A Unix socket uses `TABLO_DB_SOCKET`
instead of HOST/PORT, with `TABLO_DB_TLS=local` and no TCP TLS fields.

TCP defaults to verified TLS. Supply the CA that issued the server certificate;
the certificate must match the configured hostname. Optional mutual TLS uses
both `TABLO_DB_TLS_CERT` and `TABLO_DB_TLS_KEY`. Verification cannot be disabled
after a TLS failure. Explicit `local` transport is restricted to a Unix socket
or the literal loopback addresses `127.0.0.1` and `::1`. A Docker bridge hostname
therefore uses verified TLS.

Writer credentials serve web/check/worker operations. Reader credentials serve
the API snapshot and do not require a writer password or vault key. Maintenance
uses MIGRATE credentials. A required password must be supplied; an explicitly
supplied empty password is distinguished from an absent value.

Every server admission also requires explicit METADATA credentials. Their
connection uses the same resolved engine, endpoint, catalog and TLS policy and
closes before the normal connection opens. It proves direct schema-wide SELECT,
TRIGGER, EVENT and routine visibility: ALTER ROUTINE on MySQL or SHOW CREATE
ROUTINE on MariaDB. Only the exact selected catalog grant pattern is supported;
escape each underscore as `\_` when provisioning schema grants. Missing or limited
visibility, additional roles, grant options, global privileges and MySQL
`partial_revokes=ON` fail closed. The metadata username is 1–32 ASCII letters,
digits or underscores; passwords retain the explicit-empty distinction.

The metadata account can read all catalog data and has DDL authority through
TRIGGER/EVENT and, on MySQL, ALTER ROUTINE. It is not a read-only account. Holding
both credentials in PHP provides operational separation, not security isolation.
Protect this additional authority and provision it before initialization; ordinary
writer, reader and migrator grants remain as listed in the deployment template.
Admission reads names, kinds, privilege rows and public installation metadata,
never routine/trigger bodies, authentication hashes or credential columns.

The complete observation rejects hidden tables/views/sequences and any trigger,
routine or event, including disabled events. It binds the authenticated metadata
account to the exact catalog and matches server identity, version and public
installation/version/checksum state against the normal session. This is a live
admission check under a trusted DDL lifecycle, not an atomic catalog snapshot or
protection against a malicious administrator. Quiesce writers before privileged
DDL, migration, restore or routing changes and reattest before reopening them.
The writer guard does not lock out administrator DDL. Use one directly selected
authoritative server; independently routed replicas and backend multiplexing are
unsupported. MariaDB admission requires `server_uid` (11.4.3 or later in the
11.4 series); older servers fail admission. Later layout/bootstrap checks obtain
fresh transient observations. The committed history watermark can advance during
ordinary settlement and is excluded from this stable installation binding.

Server connections use native prepared statements, typed fetches, no persistent
sessions, no multi-statements or local infile, verified UTC/SQL mode/charset and
engine-specific NO PAD collations. Deploy against one authoritative primary with
session affinity. Connection multiplexing, multiple writable primaries and
automatic reconnection during a transaction or advisory lock are unsupported.

## Shared installation key

Every server credential-bearing process uses the same existing raw **32-byte**
external key file, supplied through `TABLO_TOKEN_KEY_FILE`. Provision that file
separately, restrict its permissions/ACLs, and mount it read-only in both web and
worker containers. The application never creates a server installation key.
Keep the key outside source control, images, database dumps and transfer archives.

A legacy SQLite installation has no server metadata verifier. If it retains only
key-derived HMAC state after all ciphertexts were deleted, the helper cannot prove
which raw key originally produced that state. Supply the exact original key from
the separate custody/backup record; exporting with a replacement key cannot repair
the old HMAC identities. This does not change SQLite's legacy schema or key policy.

The catalog stores an installation-bound verifier. A missing, short, replaced or
wrong key fails before credential mutations, even after the last ciphertext was
deleted. Ciphertexts retain the existing v1 AES-GCM format. An individual corrupt
credential retains its existing per-item failure behavior. The API reader and
worker-stop control path remain independent of the vault key. SQLite retains its
legacy adjacent-key behavior and optional explicit external-key configuration.

## Initialization, migration and recovery

Stop web writes, the scheduler, worker and notification delivery before maintenance.
Observe process exit and reconcile uncertain deliveries; changing configuration or
requesting stop alone is insufficient. Take an engine-consistent backup and a
separate verified key backup first.

Use the selected catalog's maintenance account:

```sh
php bin/database.php --status
php bin/database.php --init --receipt /private/tablo-init.json
php bin/database.php --migrate
```

Initialization requires an empty, preprovisioned catalog and a new private receipt
filename. Preserve that receipt through an interrupted bootstrap. Its installation,
target and layout identities admit only the recognized exact bootstrap prefix.
An unrelated partial schema is refused. Normal web, API, CLI checks, worker and
control connections open an existing ready schema; they never initialize a catalog
or run a migration after a missing-table error.

Server format 1 contains fifteen domain tables and exactly three metadata tables:
`tablo_database_state`, `tablo_migration_journal`, `tablo_writer_guard`. Logical
version 10 is published only after the complete descriptor is admitted. The
supported logical-9 predecessor is a synthetic fourteen-domain-table compatibility
fixture; it is not a claim that an older server release was deployed. SQLite's
existing historical versions and schema number 10 remain unchanged.

Developer source format: each `database/*-layout.jsonl` is the selected engine's
sole runtime descriptor. It contains one `records=2` scalar-layout header and
18 complete-table records with fields in `name, columns, primary, checks, indexes,
foreign, create, checksum` order. Each complete table is native JSON inside
`{"tables":[{...}]}`, preserving the original decoder depth and flags.
The reader assembles the entire graph before checking engine, 18 tables and every
CREATE checksum; public layout values/order and logical-9/10 digests are unchanged.
Records require LF or CRLF terminators, including the last record, and at most
131,072 bytes each including the terminator; extra or malformed framing is refused.
Missing/unreadable/invalid sources produce a fresh sanitized `schema` failure with
no source path, payload or previous exception. This private framing deliberately
replaces old JSON parse-offset behavior. Old JSON bytes under
`tests/fixtures/server-layouts/v10/` are test-only compatibility oracles, excluded
from the Docker context; they are never a runtime fallback or cache. Source
streaming alone does not establish the release memory or timing qualification.
Complete-table framing reduces native decodes from 145 to 19, but its larger input
frame overlaps a larger decoded table; no memory or timing benefit is established.
Table admission and default metadata admission share one six-field projection:
`name, columns, primary, checks, indexes, foreign`. Both paths load and validate
the complete 18-table source, including every CREATE checksum and positive stream
closure, before projection and existing version selection. Validated tables supplied
to metadata admission are returned unchanged.
The fixed transfer manifest shares this reader through internal constant-engine
selection. Its existing reduced-projection cache is unchanged; its first source
read now also refuses malformed framing and any bad CREATE checksum before
constructing the projection, where the old separate JSON reader ignored them.

MySQL/MariaDB DDL can commit implicitly. The durable journal records intent, exact
before/after predicates and completion for each individual DDL step. A whole batch
does not roll back atomically. Resume only a recognized plan with the original
receipt; a wrong definition, checksum, unknown prefix or future version needs an
operator decision. Preserve failed state and backups. There is no downgrade or
manual version-repair command.

Each short application writer acquires the singleton writer guard on its exact
PDO transaction and rechecks ready/key policy. Network operations run outside the
transaction. Worker, migration and notification lifetime locks are separate,
session-affine advisory locks; losing a session loses its authority. An unknown
COMMIT outcome is reported as uncertain and is never blindly retried.
This also applies to migration phase and version publication. Restore the
connection and inspect durable state before starting a fresh recognized migration
or initialization with its original receipt; a publication may already be ready.
If read API token rotation reports an uncertain outcome, its generated bearer is
not returned. After restoring the connection, perform a new authenticated rotation
and distribute only that successfully returned bearer; the prior bearer may
already have been revoked. An uncertain revocation likewise requires a fresh
authenticated revocation or rotation before relying on its result.

## Domain and raw-SQL compatibility

Server integer values are signed 64-bit values, with explicit application decoding.
SQL NULL remains distinct from false, an empty string and zero. Timestamps retain
calendar-valid UTC text including years 0001–0999. Opaque identities and CAS inputs
use byte comparisons. Display text uses `utf8mb4_0900_bin` on MySQL and
`utf8mb4_nopad_bin` on MariaDB, including trailing-space distinctions.

Saved-token names fold only ASCII A–Z for uniqueness, with a stored generated
binary key and a real unique index. Unicode case, normalization and suffix spaces
remain distinct. Current provider behavior remains GitHub only.

The three native AUTO_INCREMENT IDs (`sites`, `git_tokens`, `check_history`) retain
native allocation semantics. MySQL does not permit the proposed positive CHECK on
an AUTO_INCREMENT column. `NO_AUTO_VALUE_ON_ZERO` preserves an explicit zero; it
does **not** reject it. Direct SQL can therefore create zero or negative IDs that
the application/API and offline transfer refuse. The helper never renumbers such
legacy rows. Singleton id=1 and legal non-auto derived-ID checks remain real
database constraints.

## Backups

For SQLite, use the documented [WAL-consistent backup procedure](../README.en.md#consistent-backups)
and copy the exact key separately. Copying only a live main SQLite file is not a
backup. For server dumps, use the selected engine's own client and a private client
option file containing the account/password/TLS settings. Prevent concurrent DDL
and stop all application writers before the backup/cutover window.

```sh
mysqldump --defaults-extra-file=/private/mysql-backup.cnf --single-transaction --routines=false --events=false tablo > /private/tablo.mysql.sql
mariadb-dump --defaults-extra-file=/private/mariadb-backup.cnf --single-transaction --routines=false --events=false tablo > /private/tablo.mariadb.sql
```

Use the corresponding `mysql` or `mariadb` restore client with a protected option
file. Verify the restored catalog and its key in a separately provisioned target
before changing the application configuration. A native dump is the rollback
backup; a cross-engine transfer is a separate logical operation.

## Offline current-layout transfer

The CLI helper transfers the fixed current fifteen-table layout. It accepts no
arbitrary SQL, schema inference, web upload or key bytes. Typed NULL/integer/base64
cells preserve IDs, password hashes, every ciphertext, HMAC scope, installation and
event identities, counters, leases and completed claim state. Generated columns
are recomputed by the checked-in target definition. Data and manifests are private.

The finite helper refuses a row with more than 98,304 aggregate string bytes,
chunks larger than 262,144 encoded bytes or 128 rows, more than 128 chunks in the
complete archive, and manifests larger than 65,536 bytes. Preflight uses the same
chunk accounting as export; import checks these bounds before target writes.
These operational transfer limits do not narrow application TEXT columns.
The chunk budget also bounds the archive to 16,384 rows and at most 25,165,824 raw
string bytes before base64 overhead. Source footprint checks reject data that
cannot fit before credential scans; actual encoded chunk accounting can refuse
earlier. An oversized installation needs a separate reviewed transfer choice.
SQLite cells must use the current logical integer/TEXT storage contract; a BLOB
stored in a TEXT primary key is refused rather than normalized or used as an
incompatible keyset cursor. Opaque string bytes in correctly stored TEXT cells
retain their existing per-column transfer rules.
Server source connections use an explicit CLI-only readonly admission mode with
the same existing-layout and installation-key verifier checks. The helper checks
row envelopes before per-item credential scans and uses unbuffered server reads.
Source and target PDOs belong exclusively to the finite helper operation; do not
reuse an application session. Actual peak working-data qualification is a release
check and must not be inferred from the encoded limits alone.
Oversize, malformed UTF-8/NUL text, invalid dates, unsupported layouts, duplicate or
orphan rows, NULL primary keys and nonpositive IDs fail preflight. Reconcile such
data explicitly; the helper does not normalize, truncate, discard or renumber it.
Saved token names must be valid UTF-8 without NUL, with 1 to 80 Unicode code points.
Preflight and archive admission check this server column limit before target writes;
permitted names retain their exact bytes, including trailing spaces.

Before `--offline`, stop writers and observe worker/delivery exit. Non-null worker
generations, inflight deliveries and unresolved pending claims/leases refuse the
helper. It never silently resets a generation, releases a claim or replays an
outbox. Maintain the stopped-writer window through backup, export, import,
verification and configuration cutover.

The source connection uses explicit configuration and an external copy of the
exact original key. Choose new private scratch filenames for each command. A
scratch database is a validation artifact, not an installation or a backup.

```sh
php bin/database-transfer.php --preflight --offline --archive /private/transfer-a --scratch /private/preflight-a.sqlite
php bin/database-transfer.php --export --offline --archive /private/transfer-a --scratch /private/export-a.sqlite
```

Export uses a consistent read-only source session, stable primary-key order and
bounded typed chunks, with table/chunk counts and SHA256 digests. Key bytes are
never included. Preserve the complete manifest and chunks without editing them.

For a server target, provision and explicitly initialize an empty catalog with
the shared external key, switch only the maintenance environment to that target,
then run:

```sh
php bin/database-transfer.php --import --offline --archive /private/transfer-a --scratch /private/import-a.sqlite
php bin/database-transfer.php --verify --offline --archive /private/transfer-a --scratch /private/verify-a.sqlite
```

The target enters non-ready `importing` state. Each committed row and its primary
cursor share a transaction with the import journal. A failed import retains that
state. Resume with the identical manifest, same target and a new scratch filename;
previous rows must match exactly. Conflicting rows, a different manifest or an
unrecognized installation are refused. Do not clear the journal or copy a source
version flag into the target.

For a new SQLite target, use a fresh private staging file in the same directory
as the destination. The destination must not already exist:

```sh
php bin/database-transfer.php --import --offline --archive /private/transfer-a --scratch /private/new-target.staging.sqlite --sqlite-target /private/new-target.sqlite
```

Exclusive hard-link publication is the SQLite import commit point and never
replaces a destination created by another caller. Once published, the original
manifest digest reports success even if staging cleanup fails. Cleanup is best
effort: the owner-specified scratch file may remain with its private 0600 mode as
another link to the published database. Keep that file private, do not open it as
a separate writable installation, and remove the staging link when safe. A cleanup
failure does not call for repeating the import against the existing destination.

The archive records the original allocation floors and committed history watermark
separately, including deleted high history IDs and prune-all installations. A server
target preserves both values independently: AUTO_INCREMENT reservation gaps remain
distinct from accepted observations.

Unchanged SQLite schema 10 has one sequence-based activation/cursor frontier rather
than a separate committed-watermark field. A SQLite target preserves the allocation
floor and represents its frontier as the greater of the archived committed watermark
and history allocation floor. A rolled-back server reservation can therefore promote
the SQLite frontier conservatively. No committed history row is invented, omitted or
rewritten, and the next accepted ID remains above every preserved reservation. A later
SQLite export reports this combined frontier; it cannot roundtrip the two original
numeric frontiers independently. Keep the original archive when that distinction is
needed for audit. Server verification still requires its exact committed watermark.

SQL foreign keys stay enabled. Publication follows complete byte/count/constraint/key/sequence checks;
API/password behavior must also be checked before the operator changes the live
configuration.

Before the first new target write, configuration rollback can return to the
unchanged source. **After new target writes, pointing back loses those changes.**
Restore/reconcile or forward-transfer into another verified target. The helper
does not provide an automatic reverse merge, dual writers or exactly-once delivery.

## Verification lanes

Default `composer test` and `composer verify` remain SQLite-only. Server verification
is explicit: `composer verify:server -- --engine=mysql` or `--engine=mariadb`,
invoked inside the admitted server cell below.
Required cases, behavioral assertion counts and selected-engine evidence are
checked independently; missing services, credentials, drivers, tests or non-passed
JUnit statuses fail the lane. Verification never recaptures source fingerprints.

The native wrapper requires an isolated source copy and an attested cell manifest.
`tools/server-cell.php --plan=/private/cell.json --engine=mysql --pull` declares
fresh names and freezes explicit source, vendor and tool inputs. Supply
`TABLO_TEST_SOURCE_MANIFEST` and its `TABLO_TEST_SOURCE_MANIFEST_SHA256`, plus
`TABLO_TEST_DOCKER_BIN`, `TABLO_TEST_COMPOSER_PHAR`, `LEKALO_BIN`,
`TABLO_TEST_NODE_BIN` (Node24.13.0) and an explicit unused local
`TABLO_TEST_OBSERVER_PORT`. Windows also requires `TABLO_TEST_POWERSHELL_BIN`
for the pinned PowerShell7 adapter. Linux x86_64 additionally requires
`TABLO_SERVER_LINUX_ADAPTER`, `TABLO_SERVER_LINUX_BUILD` and
`TABLO_SERVER_LINUX_QUALIFICATION`, each with its explicit `_SHA256` selector.
Also select the canonical regular command shell through
`TABLO_SERVER_LINUX_SHELL` and its `_SHA256` selector. Generate one fresh lower-case
32-hex cell nonce before qualification and explicitly set `TABLO_SERVER_CELL_NONCE`
to that same value for qualified declaration; it is never adopted from an old
receipt. Diagnostic-only mode rejects any nonce override and generates its own.
Qualified Linux declaration also requires `TABLO_SERVER_PHP_BOOTSTRAP_MODULES`
and its `_SHA256` selector. The newly prepared regular JSON has schema
`tablo/php-bootstrap-modules/v1` and exact ordered keys `schema`, `iniFiles`,
`extensionFiles`. Both arrays contain at most128 unique regular canonical
`{ "path": "...", "sha256": "..." }` pins in their declared load order. INI pins
must exactly match the existing bootstrap INI inputs. Module files are explicitly
selected and hashed; no filename, suffix, general INI discovery or capability is
inferred. An empty module list needs a successful actual Composer qualification
control with the selected PHP. Diagnostic-only mode never reads this manifest.
The native qualifier creates its private Composer-only INI with absolute selected
extensions and no automatic prepend/append/preload; this bootstrap is bound into
the referenced platform and runtime control evidence.
These identify the actual executable, compiler provenance and a separately
reviewed v2 qualification receipt bound to the helper source, build recipe,
current controls C source, explicit cell nonce and all five selected runtime pins
(Node, PHP, Composer, shell and Docker). PHP declaration opens only the explicitly
selected nonce-owned qualification and checks its structure and bindings. It does
not open paths merely because that receipt names them. Before creating product
resources, the native owner independently opens and validates every actual control
receipt and current platform/namespace identity; a PHP declaration is never
qualification. The build recipe emits provenance only. It cannot qualify
itself. Missing or mismatched runtime qualification refuses execution.
The four normal hosted cells compile only the reviewed C/control/recipe inputs
inside the immutable `docker.io/library/php@sha256:6114a2bd4956c50e4db20f08b9377e4fc7e08417b2aa34a44ec7ff52a07996b5`
compiler image, as the actual runner UID/GID, with no container network and a
fresh output directory. Image RepoDigest and platform are checked independently
from the daemon's inspect identity, which is bound to the created container.
Finite client exits and exact owned container cleanup are both required.

`tools/server-linux-prepare.cjs --cell=ABS --source=ABS` acquires only the
actual PHP-reported INIs into fresh regular files, preserving their exact bytes
and scan order. A second selected-PHP query must report exactly those owned
files. Normal PHP declaration commands select that main INI and its private
scan directory; original regular-file guards remain enforced. Literal configured
extension filenames/full paths become ordered module pins. Bare module names,
dynamic syntax or duplicate resolved modules refuse preparation; no suffix is
guessed. This is input acquisition, not a Composer capability test.
The same-cell plan is then passed to
`tools/server-supervisor-linux-qualify.cjs --plan=ABS --sha256=HASH`. Its thirty
strict neutral cases, tracer-death witness and five actual runtime controls must
all pass before it emits v2 qualification. Its separate `neutral-diagnostic`
mode cannot emit qualification and is never used by the four normal CI lanes.
An external-container namespace denial remains diagnostic noncoverage.

The source manifest lists regular repository files as `{ "files": [{ "path": "...", "sha256": "..." }] }`.
Install the pinned Mago release explicitly before declaring the cell. Execution
uses `node tools/server-supervisor.cjs --execute=/private/cell.json
--sha256=<reviewed-plan-sha256>` as a separate operation. Direct unobserved PHP
execution refuses the cell. It never downloads PHP packages or recaptures fingerprints.

`--declaration-diagnostic` instead emits an explicit `executionMode` of
`diagnostic-only`, preserving source, tool, vendor, Mago and PHP INI checks while
omitting Linux runtime qualification inputs. It cannot be combined with `--pull`
or `--cross`. The Node executor rejects this mode before creating product
resources. Its one additional MySQL/PHP 8.2 hosted job stops after declaration;
a successful declaration is not a database or engine test result. Diagnostics
contain only fixed stage/type values and allowlisted repository-relative source
locations; private PHP captures are not uploaded. The four required server jobs
retain `qualified` mode and their full runtime prerequisites.

The observer starts its monotonic parent clock before plan reads and preparation.
It runs three sequential controller phases, each with a fresh immutable adapter
plan, nonce, authority and capture paths. Preparation materializes source and
admits the real service through its native client, then closes. The next phase
runs actual Composer verification directly, preserving the same-child436-case
JSON/JUnit export. The final PHP controller invokes the engine verifier in the
same process, bootstraps its catalog pool once and performs fresh selected-engine
TLS PDO admission before consuming that existing baseline export and running the
engine cases. Native service admission before Composer is distinct from PDO
admission afterward; either failure rejects the complete engine cell.
Every native launch keeps its working directory in the phase-owned cwd directory.
Composer selects the declared source with its global --working-dir option; the
engine entrypoint derives its source from absolute script and plan paths.
The private fixture CLI INI displays redacted diagnostics on stderr with exception
arguments omitted and PHP logging disabled. HTTP fixtures explicitly disable
displayed errors in their server command.
Each native operation and complete server case is admitted before its setup, and
the case lease remains armed through native closure, JUnit, descendant audit and
result custody. The original8/20-second limits include one1-second settlement
reserve; nested leases share their earliest containing end. The parent remains
armed through source audit and success-only owned cleanup. Missing or late native
closure fails the entire cell and retains evidence; an empty OS scope does not
replace PHP child receipts. These are observed process bounds on qualified hosts,
with native blocking-I/O or kernel failures reported as UNKNOWN.

The supervised host/client executable ceiling is eleven: Node and one adapter
occupy two slots, and the original Composer baseline has nine managed slots.
Its overlapping password race needs Composer, its actual command shell, the
verifier and Testo, plus three HTTP server peers, the session request and the
actual password CLI. Preparation, engine and neutral control phases retain an
eight-process admission limit. The MySQL/MariaDB service containers are separately
declared resources outside the host Job. The Job's configured limit is enforced
for each phase. Its sampled associated-object counter
can include rejected process associations until Windows releases them; it is
retained as raw accounting, never reported as a runnable-process peak. Actual
empty-scope closure and each required native child receipt remain necessary.
Connections and active leases remain limited to eight. Every prior controller,
adapter and scope must positively close before the next phase starts; phases
share the original parent end. Windows creates its private kill-on-close Job before work and
assigns a suspended controller atomically at creation, with no breakaway. Linux
x86_64 uses the separately pinned native `tgid-seccomp-ptrace-x64-v3` adapter.
It admits process/TGID units before execution, including descendants that change
process groups, and binds its monotonic READY event to the owner interval.
Owner loss cancels only the adapter-created scope. Missing native closure or
qualification rejects ordinary success; an earlier root exit remains a separate
observed fact. Linux compilation and native/runtime controls must pass on the
selected host before an engine run is admitted. No global process scan or adoption
by a supplied PID is used. The private raw32-byte protocol keys are
distinct from installation keys and never appear in command arguments or reports.
The default SQLite lanes and production PHP/Docker image do not use this observer.

Policy v3 traces `exit_group` and holds one elected caller until every known
creation pending in its TGID has either a registered birth or a proven failure.
Later valid creations in that TGID stay stopped without reservations, including
after the elected exit is released. Existing flag/quota refusals remain counted.
A validated exec identity exchange clears obsolete group exit intent; it does
not resume old held siblings or clear unknown-child quarantine. External fatal
signals and the separate exec/birth race retain the existing fail-closed rules.

The Linux adapter always suppresses `io_uring_setup` before kernel entry and writes
EPERM at the suppressed syscall exit. Its total `deniedAdmissions` includes these
refusals. Native `ioUringSetupAttempts` and `ioUringSetupEpermWrites` distinguish
attempts from successful exit-register writes; a register write alone does not
prove that application code observed the return. Ordinary success requires
`failed=false` and all three counts equal, together with every existing root,
adapter, input, stream, identity, terminal-wait and empty-scope condition. Other
security/quota denials and infrastructure failures remain fatal and latched.
Overflow and incomplete writes cannot qualify. The optional `DENIALS` diagnostic
never supplies acceptance authority. This policy prevents new setup calls in the
owned traced tree; it is not a whole-host inherited-capability isolation claim.

Fresh qualification uses `tablo/linux-adapter-qualification/v3` and the v4 private
pipe protocol. The original30 controls remain mandatory in their original order;
eight additional owned scopes cover handled/repeated/argument/thread/fork-exec
refusals, mixed security/quota failures and a deliberately nonzero root. Their
separate nonce-owned `fallback-controls.json` binds exact source, build, controls,
owner, qualifier, platform and selected runtime pins. Successful EPERM markers and
nonzero authoritative native counts are both required, including for the exact
pinned Node client. Host EPERM alone is insufficient. The unchanged tracer-death
witness and all five clients must also pass. Old policy, qv2, binaries or relabelled
receipts cannot establish the new contract.

CI first requires one separate isolated C fault-shim resource per workflow
invocation, including distinct push and PR merge-tree invocations. It includes the
actual native functions with every reachable process/kernel boundary stubbed,
uses only the two copied public C sources and the existing immutable compiler
image, and must exit successfully with positive owned cleanup before dependent
server qualification. The production compiler recipe/resources and runtime
limits are unchanged. Authoring this source does not establish compilation,
native/client qualification, engine acceptance or permission for local execution.

The runner creates a private PHP configuration, checks its actual extensions,
generates synthetic credentials and TLS certificates, and verifies the immutable
image's original OCI configuration and entrypoint bytes before starting it.
Published database ports bind only to `127.0.0.1`; authenticated native readiness
and fresh verified-TLS PDO readiness are separate checks. Root remains local to
the service socket. Each engine has eight reusable catalog slots; a failed or
unknown case prevents reuse and retains its evidence. Cleanup addresses only the
saved container/network IDs and nonce-labelled volume created by this cell.

Add `--cross` when declaring the MySQL/PHP8.5 cell. The resulting wrapper runs the
main and cross lanes once, sharing the same catalog owner, after the unchanged
SQLite verification prerequisite. The cross lane includes both transfer
directions and actual SIGKILL/restart at the durable DDL and prepublication
boundaries of both engines. The complete transfer memory case measures PHP-used
working data against a 2MiB bound over a cold roundtrip with more than 8MiB of source payload; it does
not claim to measure external native allocator RSS.
When that upper assertion fails, an optional `memoryDiagnostic` on failed native
progress can report its bounded `peakPhpWorkingBytes` value. It requires the exact
known assertion context and public caller; it is a reported diagnostic with
`rootCause=UNKNOWN`, not independent measurement, provenance or acceptance proof.

The six SQLite OS/PHP cells remain required. Four additional Ubuntu cells cover
both server engines on PHP 8.2 and 8.5. Direct MySQL↔MariaDB transfer and owned
server crash/restart are required sublanes of MySQL/PHP 8.5. Windows SQLite results
do not establish a Windows server or an Ubuntu server result.

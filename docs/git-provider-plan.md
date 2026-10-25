# План первого дополнительного Git-провайдера: Gitea

Для [#25](https://github.com/ichinya/tablo/issues/25) выбран **Gitea**. Этот документ
задаёт будущую реализацию и условия её приёмки. В текущем приложении поддерживается
только GitHub; адаптер Gitea, миграция и APIv2 ещё не реализованы. Описанные ниже
интерфейсы, таблицы, маршруты и ограничения являются планом.

Исходная точка на **16 октября 2026 года**: commit
`f2118e09e83a22c20fd0324a5d3bcd10045533ef`, schema 10, 436 статических
идентичностей тестов, 53 bindings и 949 reviewed dependencies. Это инвентаризация
исходников, а не результат запуска тестов. Протокольный образец —
[Gitea API 1.26](https://docs.gitea.com/api/1.26/) и tagged source **v1.26.4**.
Версия 1.26.4 не рекомендуется для нового развёртывания: перед реализацией нужно
выбрать поддерживаемую исправленную версию и повторить проверку контракта.

Gitea подходит к четырём существующим показателям: отдельные операции последнего
стабильного релиза, точной ветки, issues и PR. Forgejo остаётся отдельным будущим
адаптером со своей версионной матрицей: [его документация 17.0](https://forgejo.org/docs/v17.0/user/api/usage/)
не доказывает совместимость со всем Gitea 1.26. GitLab требует дополнительных
решений о namespace/subgroup, MR и смысле последнего релиза, который
[выбирается по released_at](https://docs.gitlab.com/api/releases/).
Выбор отражает техническое соответствие; распространённость установок не измерялась.

## Границы и владельцы изменений

Первый MVP обращается только к **публичным HTTPS-адресам Git API**. Приватные
репозитории на таком сервере поддерживаются при достаточных правах токена;
intranet/private-network Git-серверы отложены. Разрешение частной сети для health
не распространяется на Git. GitHub сохраняет прежний wire-контракт, inline
`github_token`, saved tokens, сообщения и cooldown identities.

| Область | Текущий владелец и требуемое изменение |
| --- | --- |
| Контракт и создание провайдера | [RepositoryProvider](../app/RepositoryProvider.php), [GitProviders](../app/GitProviders.php), [GitHubConnection](../app/git-hub-connection.php): сохранить четыре metric-метода, добавить нейтральный lifecycle и выбор зарегистрированного host. |
| Ввод и credentials | [SiteInput](../app/SiteInput.php), [SiteIdentityInput](../app/SiteIdentityInput.php), [GitRepositoryName](../app/GitRepositoryName.php), [GitTokenRepository](../app/git-token-repository.php), [SelectedSiteToken](../app/SelectedSiteToken.php), [SavedGitTokenUpdate](../app/SavedGitTokenUpdate.php), [SiteTokenResolver](../app/SiteTokenResolver.php): provider/host identity, проверка до decrypt, неизменяемая привязка. |
| Актуальность, memo и квоты | [GitCredentialSnapshot](../app/GitCredentialSnapshot.php), [GitCredentialCurrent](../app/GitCredentialCurrent.php), [GitHubMemo](../app/GitHubMemo.php), [TokenVault](../app/TokenVault.php), [GitHubRequestPolicy](../app/GitHubRequestPolicy.php), [GitCooldownLedger](../app/GitCooldownLedger.php): изоляция host/revision и отдельная политика Gitea. |
| HTTP и ответы | [HttpClient](../app/HttpClient.php), [GetAddressAdmission](../app/GetAddressAdmission.php), [CurlGetOptions](../app/CurlGetOptions.php), [GitResponseHeaders](../app/GitResponseHeaders.php), [HttpSizeBudget](../app/HttpSizeBudget.php): независимый public-only режим и ограниченный захват pagination headers. Сейчас Git headers сохраняют только rate metadata; общий GET без endpoint chain не имеет совокупного header/body budget. |
| Проверка и settlement | [GitCheck](../app/GitCheck.php), [GitFieldCheck](../app/GitFieldCheck.php), [GitCheckResult](../app/GitCheckResult.php), [WorkerFieldOrder](../app/WorkerFieldOrder.php), [WorkerRuntime](../app/WorkerRuntime.php), [WorkerHooks](../app/WorkerHooks.php), [CheckCommand](../app/check-command.php), [SiteCheckWriter](../app/SiteCheckWriter.php), [SiteResultSettlement](../app/SiteResultSettlement.php): убрать конкретные GitHub-предположения, расширить CAS и сохранить атомарный учёт результата. |
| Формы и consumers | [SiteFormPage](../app/SiteFormPage.php), [SiteBranchesPage](../app/SiteBranchesPage.php), [SitePages](../app/site-pages.php), [форма](../views/form.volt), [app.js](../public/assets/app.js), [read API DTO](../app/read-api-repository.php), [ReadApiRepository](../app/ReadApiRepository.php), [v1 schema](../contracts/read-api-v1.schema.json): provider-aware выбор/ссылки и отдельный compatibility gate. |
| SQLite | [SchemaUpgrade](../app/SchemaUpgrade.php), [SchemaLayoutUpgrade](../app/schema-layout-upgrade.php), [SchemaLayout](../app/SchemaLayout.php), [SchemaShape](../app/SchemaShape.php): новый переход с сохранением всех допускаемых lineages. [database/schema.sql](../database/schema.sql) остаётся замороженным bootstrap-v1. |
| Модель и проверка | [entities](../lekalo/modules/dashboard/entities.yaml), [commands](../lekalo/modules/dashboard/commands.yaml), [scenarios](../lekalo/modules/dashboard/scenarios.yaml), [bindings](../contracts/php-bindings.json), [reviewed dependencies](../contracts/reviewed-dependencies.json), [verify](../tools/verify.php): обновлять только после реализации и просмотра diff, без автоматического capture при verify. |

[#23](https://github.com/ichinya/tablo/issues/23) владеет общей архитектурой
credentials, [#39](https://github.com/ichinya/tablo/issues/39) — другими СУБД.
План сохраняет inline GitHub и saved-only Gitea; описанный ниже rebuild относится
только к SQLite. Номер будущей схемы не зарезервирован.

## Минимальный контракт

Существующий `RepositoryProvider` сохраняет `getLatestRelease(): ?string`,
`getLatestCommit(): string`, `getOpenIssuesCount(): int` и
`getOpenPullRequestsCount(): int` с прежними аргументами repository/branch.
Предлагаемый `ConnectedRepositoryProvider extends RepositoryProvider` добавляет:

- `getBranches(repository)` → `{repository, default_branch, branches: list<string>}`;
- `requireBranch(repository, branch): void`;
- `beginCheck(): void`, `admissions(): int`;
- `eligibleFields()` → boolean для `latest_release`, `latest_commit`, `open_issues`, `open_prs`.

GitHub отображает свои core/search buckets на эти поля; scheduler не должен знать
названия buckets провайдера. Простой FakeProvider остаётся допустимым для SiteChecker.
Предлагаемый `RepositoryConnection` предоставляет `provider(?site, input=[])`,
`branches(input, ?site)` и `validate(input, ?site)`; новый GitConnection выбирает
provider/host, а GitHubConnection остаётся совместимым facade для bindings/hooks.

Безопасный `RepositoryFailure` предоставляет `errorCode(): string`,
`httpStatus(): ?int`, `eligibleAt(): ?int`, `safeMessage(): string`.
Существующий GitHubFailure сохраняет публичные поля и сообщения; GiteaFailure
имеет собственные фиксированные сообщения. Неизвестное исключение нельзя
публиковать через `getMessage()`. Общие PDO/key failures сохраняют отдельный
типизированный путь и не превращаются в обычную ошибку одной Git-метрики.

Внутренний ответ Gitea содержит HTTP status, приватное body, проверенные total,
numeric next page, признак invalid pagination и Retry-After. Для него нужен
ограниченный provider-specific режим захвата; прежний GitHub transport не меняется.

## Host, repository и credential identity

Host ID — выданный сервером неизменяемый ключ tuple
`(provider, canonical HTTPS origin, mount path)`. URL репозитория не регистрирует
host. GitHub получает `github-public` с API `https://api.github.com` и web
`https://github.com`. Для Gitea suffix фиксирован `/api/v1`: mount `/git` даёт
`https://forge.example/git/api/v1`. Другой provider, порт или mount — другая identity.

Предлагаемая регистрация: отдельный administrator `POST /settings/git-hosts`
с session/CSRF, canonicalization и проверкой всех DNS-ответов без credentials.
Формы site/token передают только существующий host ID. Политика первого MVP:

- HTTPS; lowercase ASCII DNS A-label, без trailing dot, raw IDN, userinfo,
  IP literal и dotless/local имени. Порт 443 удаляется; иной явно зарегистрированный
  порт 1–65535 входит в origin.
- Mount пустой либо `/` + ASCII unreserved segments с сохранением case;
  запрещены percent escapes, backslash, dot segments, query/fragment,
  двойной и завершающий slash.
- Provider/origin/mount нельзя редактировать; нужно создать новый host и явно
  перепривязать sites/credentials. Удаление используемого host запрещено.

Для Gitea repository принимает `owner/repo`, один terminal `.git` либо HTTPS web URL точно
зарегистрированного origin/mount с этими двумя сегментами и необязательным конечным
slash. Каждый сегмент: 1–100 ASCII `[A-Za-z0-9_][A-Za-z0-9_.-]*`, общий slug
до 140 байт; case сохраняется, один terminal `.git` удаляется. Это ограниченный
поднабор Tablo, а не максимумы Gitea. SSH/scp/git, network-path, nested namespace,
userinfo, encoded repository path, query/fragment и произвольный API URL запрещены.

Эта новая grammar применяется только к Gitea. Для GitHub сохраняются текущий owner
`^[a-zA-Z0-9][a-zA-Z0-9-]{0,38}$`, repository `[a-zA-Z0-9_.-]{1,100}`
с запретом `.` и `..`, прежняя нормализация GitHub URL/terminal `.git` и общий
предел slug 140 байт. Контракт ввода GitHub не расширяется.

Текущая проверка branch требует непустую строку до 128 байт без regex-whitespace
(`\s`) и байтов `0x00`–`0x1f`; она дополнительно не проверяет валидность UTF-8
и не исключает DEL (`0x7f`).
Literal кодируется один раз как один path parameter: `feature/topic` →
`feature%2Ftopic`, `x%2Fy` → `x%252Fy`, `release+fix` → `release%2Bfix`.
Ответ обязан подтвердить точное имя и `commit.id` из 40/64 hex. Tag/ref/commit
не заменяет отсутствующую ветку. Символ `?` используется как отрицательный тест
сериализации request-target, без утверждения о допустимости такого имени в Git.

Gitea MVP требует reusable saved token: `git_token_id=NULL` вызывает validation
по `git_token_id` до любых запросов и decrypt. Анонимного режима Gitea в MVP нет;
существующий анонимный GitHub сохраняется. Достаточный набор прав токена —
`read:repository` + `read:issue` и права пользователя на private repo и нужные
units; public-only token недостаточен, admin/write scopes не требуются.
[API usage 1.26](https://docs.gitea.com/1.26/development/api-usage/)
описывает scopes и передачу `Authorization: token …`; токен не передаётся в URL.
После auth failure анонимного повтора нет.

Lookup проверяет `id + provider + host` **до decrypt**; эти поля входят в revision
proof и CAS вместе с config revision и selected credential snapshot. Provider/host
saved token неизменяемы даже при blank edit. Rename сохраняет ciphertext и site
revision; rotation в той же identity инвалидирует результаты и повышает revisions
привязанных sites. Используемый token удаляется с RESTRICT. Переключение GitHub site
с inline token требует явного удаления inline credential и нового выбора;
переносить ciphertext на Gitea запрещено.

Новый length-delimited HMAC domain v2 связывает provider/host/token с прежним
private vault key. Одинаковый token объединяет квоту только внутри одного
provider/host; прежний GitHub HMAC domain и `github_cooldowns` сохраняются.
Memo включает identity, repository, metric, branch и revision. Проверки актуальности
выполняются до/после HTTP и на cache hit; A → B → A не оживляет старый handle.
Endpoint bearer и read API bearer никогда не входят в Git carriers.

## Операции Gitea и отсутствие данных

Все пути ниже относительны к зарегистрированному API base; только GET.

| Показатель | Запрос и условие результата |
| --- | --- |
| Последний релиз | `/repos/{owner}/{repo}/releases/latest`: непустой `tag_name` до 200 байт без NUL, `draft=false`, `prerelease=false`. [Операция](https://docs.gitea.com/api/1.26/operations/repo-get-latest-release/) и [model v1.26.4](https://github.com/go-gitea/gitea/blob/v1.26.4/models/repo/release.go) исключают draft/prerelease/plain tags и сортируют `created_unix DESC, id DESC`; локальной semver-сортировки нет. |
| HEAD выбранной ветки | `/repos/{owner}/{repo}/branches/{encoded literal}`: точное `name` и `commit.id`. [Branch API](https://docs.gitea.com/api/1.26/operations/repo-get-branch/). Metadata default branch нужна для picker, выбранная ветка проверяется снова при save. |
| Открытые issues | `/repos/{owner}/{repo}/issues?state=open&type=issues&limit=1&page=1`; отдельный `type=issues` обязателен. [Issues API](https://docs.gitea.com/api/1.26/operations/issue-list-issues/). |
| Открытые PR | `/repos/{owner}/{repo}/pulls?state=open&limit=1&page=1`; открытые draft PR входят в count. [Pulls API](https://docs.gitea.com/api/1.26/operations/repo-list-pull-requests/). |

После release 404 нужен второй запрос
`/repos/{owner}/{repo}/releases?draft=false&pre-release=false&limit=1&page=1`.
Только **200 + пустой массив + единственный валидный X-Total-Count: 0** подтверждает
`value=null, status=absent, reason=no-release`. Latest и list защищены одним
`reqRepoReader(TypeReleases)` в [router v1.26.4](https://github.com/go-gitea/gitea/blob/v1.26.4/routers/api/v1/api.go);
фильтры и total заданы в [release handler](https://github.com/go-gitea/gitea/blob/v1.26.4/routers/api/v1/repo/release.go).
Repository metadata 200, включая `has_releases=true`, не доказывает доступ к release
unit. Повторный 404 означает `access`, непустой filtered list — `release-unconfirmed`,
невалидный total/body — `invalid-data`. Latest tag и первая запись произвольного
списка не заменяют этот протокол.

Counts требуют единственный проверенный `X-Total-Count` в диапазоне 0–2147483647,
array body и согласованную нулевую/ненулевую cardinality страницы. Нельзя брать
длину первой страницы, `repository.open_issues_count` или частичную сумму.
Измеренный **0** — успешное значение; неизвестное значение — `null` с причиной.
Четыре запроса не образуют общей транзакционной snapshot сервера;
[issue handler v1.26.4](https://github.com/go-gitea/gitea/blob/v1.26.4/routers/api/v1/repo/issue.go)
использует indexer, поэтому атомарность/мгновенная согласованность counts не обещается.

Picker получает metadata и до 10 последовательных страниц, максимум 1000 уникальных
веток. Нужны согласованные total/next, полный список и присутствующий default branch.
Короткая страница при server clamp не означает конец: допустим effective limit
1–100, постоянный на протяжении обхода. Link проверяется на origin, mount, известный
operation path, разрешённые query и следующий numeric page; URL строится локально,
сырой Link не запрашивается. Missing/duplicate/contradictory headers, cycle/jump,
foreign origin, prefix escape, opaque cursor или исчерпание cap/deadline отклоняют
весь picker. Частичного списка нет. Основа wire pagination —
[API usage 1.26](https://docs.gitea.com/1.26/development/api-usage/).

Конкурентное создание/удаление веток может изменить `X-Total-Count` между страницами
и вызвать ожидаемый fail-closed отказ по согласованности без частичного списка.
Позднейший свежий явный preview или новая операция может начать обход заново
с новым budget; немедленного retry внутри текущего ограниченного обхода нет.
Это поведение picker, отдельное от периодических worker-проверок HEAD выбранной ветки.

## Transport, budgets и безопасные ошибки

Git принудительно использует public-only admission независимо от health settings:
все DNS answers проверяются, выбранный адрес pin-ится, каждый запрос проходит
новую admission. TLS verification включена, proxy и redirects выключены, включая
same-origin redirect. Loopback, RFC1918, metadata/link-local, IPv6 ULA,
IPv4-mapped private, mixed public/private DNS и rebinding запрещены. Hostile URL
с userinfo, чужим suffix host, encoded traversal или `?access_token=MARKER`
отклоняется до credential HTTP; ввод не отражается в ошибках.

| Локальная граница Tablo | Предел |
| --- | --- |
| Monitoring | 4 обычных запроса, до 5 при подтверждении отсутствия релиза; 24 s на site |
| Branch preview | Metadata + до 10 pages = 11 запросов; 24 s, до 1000 branches |
| Общий composed sweep | 100 HTTP admissions / 120 s для всех провайдеров вместе |
| Memo | 256 entries; только проверенный успех/подтверждённое отсутствие |
| Ответ | Headers до 64 KiB, headers + body до 1 MiB, JSON depth до 32 |
| Retry/sleep/HTTP cache | 0 retries, 0 sleeps; без ETag/304/persistent HTTP cache в первом адаптере |

Это бюджеты клиента, не квоты Gitea. Синхронное DNS-разрешение может завершиться
после deadline перед безопасным отказом; жёсткая wall-clock гарантия не заявляется.
GitHub-only поведение и его отдельные core/search/secondary правила сохраняются.

| Условие | Безопасный результат |
| --- | --- |
| Невалидный input, unknown host или несовпадение token tuple | Validation 422 до decrypt/HTTP |
| 401/403/required 404 | `access`; обычный 403 не создаёт cooldown |
| 3xx, кроме 304 | `renamed`, без follow; 304 остаётся `unavailable` |
| 429 | `rate-limit`; Retry-After delta/date с локальным floor 60 s и cap 24 h; missing/invalid/duplicate/overflow/past → явно локальные 60 s |
| Malformed rate headers на 200 | Сохранить валидную метрику, не выдумывать cooldown |
| 5xx, TLS/DNS/network/timeout, HTML 200, неожиданный 304 | `unavailable` |
| Плохой shape/SHA/UTF-8/count/pagination | `invalid-data`; несовпавшее имя ветки → `branch-unconfirmed` |
| Локальный budget или stale credential/config proof | `budget` или `credential-changed`; stale результат не записывается |
| Empty/too large/timed-out branch preview | `branches-empty`, `branches-too-large`, `branches-timeout` только для preview |

Эта таблица задаёт Gitea; текущая классификация GitHub 301/302 как `renamed`
и остальных неожиданных 3xx как `unavailable` сохраняется.

Независимые успешные поля сохраняются, failed fields равны null, совокупный Git
статус может быть partial; health/version не теряют успешные результаты.
[Whitelist истории](../app/CheckHistorySnapshot.php) сохраняется; preview-only
причины не расширяют persistent domain. Gitea cooldown изолирован по host/provider
и не наследует GitHub secondary cooldown.

Ошибки, логи и captures допускают provider, opaque host ID, operation/reason enums,
HTTP status и ограниченный eligible_at. Запрещены raw URL/query/Link/body/upstream
message, токены/ключи/ciphertext/digests, headers/cookies и исходное исключение
с previous/trace/secret-bearing closures. Полные приватные arrays и native
URL/header/body arguments требуют SensitiveParameter; raw exception уничтожается
до безопасного wrapper. Capture аргументов в тестах остаётся включённым.

## SQLite и сохранение legacy данных

Предлагаются `git_hosts` с уникальным immutable tuple, `git_tokens.git_host_id`
и `sites.git_host_id`. Composite FK `(host, provider)` и
`(git_token_id, provider, host)` исключают cross-binding; удаление используемых
credentials/hosts — RESTRICT. Token names уникальны внутри provider/host
без учёта регистра; IDs, timestamps и ciphertext сохраняются. Все поддерживаемые
legacy GitHub строки backfill в `github-public` **без decrypt/re-encrypt**;
unknown provider/corrupt binding вызывает атомарный отказ. Для Gitea нужна отдельная
provider/host-scoped cooldown table; старые GitHub rows не переписываются.

Расширение provider CHECK требует rebuild parent tables. DROP `sites` при
`foreign_keys=ON` может каскадно удалить дочерние данные, а rename старого parent
может переписать child references. План connection-owned wrapper:

1. Отказать при caller transaction, классифицировать lineage; выключить FK
   enforcement **до** `BEGIN IMMEDIATE` и проверить состояние.
2. Повторно классифицировать под write lock. Создать replacement tables,
   копировать все колонки явно, сохранить `sqlite_sequence` high-water marks,
   даже если sequence выше max ID.
3. При выключенной enforcement удалить originals и переименовать replacements
   в исходные имена. Сохранить child rows, FK/index/trigger structure.
4. Проверить exact shape и `foreign_key_check` до `user_version`/COMMIT.
   Copy/DDL/FK/COMMIT failure откатывает целиком schema/data/version; failed BEGIN
   не даёт права откатывать чужую transaction.
5. В finally восстановить и проверить `foreign_keys=ON` при успехе и отказе.

Это проект wrapper, требующий native failure/race qualification. Порядок следует
проверять по [SQLite generalized ALTER TABLE](https://www.sqlite.org/lang_altertable.html#otheralter)
и [foreign_keys pragma](https://www.sqlite.org/pragma.html#pragma_foreign_keys).
Нужны fresh bootstrap и каждый admitted lineage 0–10, включая обе исторические
schema 5, конкурирующий lock, held read snapshot, backup/interruption и все fault
points. Сохраняются users/session settings, site order/config/endpoints,
inline/saved/endpoint ciphertext, external-key custody, history, incidents и
checkpoints, notification state/slots, worker credit и read API token.

## Блокирующий gate совместимости read API

[Текущий v1](read-api.md) и [его schema](../contracts/read-api-v1.schema.json)
требуют `provider=github`; DTO также ограничивает repository двумя сегментами
и 140 байтами. **APIv2 обязателен до активации смешанных провайдеров.** Предлагаются
`GET /api/v2/sites` и `/api/v2/sites/{id}` с прежними auth/snapshot/privacy
правилами, прежними публичными полями, provider enum github/gitea и required
`git_host_id` (`^[A-Za-z0-9_-]{1,64}$`), `additionalProperties=false`.
Raw origin/mount/credentials в ответ не входят.

**НЕ РЕШЕНО:** поведение v1 при появлении Gitea. Кандидат — GitHub-only projection,
фильтрация до cursor/limit и 404 для Gitea singleton, с сохранением GitHub IDs,
ordering/cursors и формы DTO. Такая фильтрация меняет смысл «all sites» и пока
не является принятой политикой. Нужны явное решение о совместимости, документация
и путь миграции consumers до activation. Подмена provider на github или случайный
500 из whitelist недопустимы; наличие APIv2 само по себе этот gate не закрывает.

## Этапы будущей реализации

| Этап | Результат и независимое доказательство |
| --- | --- |
| P1 — identity/schema | Host registry, immutable token binding, SQL carriers и атомарный rebuild. Все lineages/fault points; byte equality ciphertext/child rows/sequences; mismatch до decrypt. |
| P2 — lifecycle | Нейтральные connection/failure/field interfaces, worker/CLI/web wiring с GitHub facade. Прежние GitHub wire assertions, partial/CAS/credit и hooks сохраняются. |
| P3 — protocol | Gitea request/branch/release/count, bounded headers/pagination и public-only transport. Независимые Unit inputs и настоящий cURL на собственном HTTPS/DNS receiver. |
| P4 — consumers/API | Provider/host selectors и labels/links, stale preview invalidation, branch recheck на save, APIv2 и принятое решение по v1. Exact DTO/privacy и native HTTP/CLI/worker consumers. |
| P5 — model/acceptance | Модели, scenarios/bindings и fingerprints после review исходных изменений; полный существующий verify и отдельная qualification реального Gitea. |

Смена provider/host должна отменять pending branch request и инвалидировать его
ответ так же, как смена token/repository. Все этапы нуждаются в отдельных
reviewable изменениях; завершение этого плана не означает приёмку runtime.

## Независимые acceptance fixtures

Следующие **30 сценариев запланированы и НЕ ЗАПУСКАЛИСЬ**. Inputs и ожидаемые
результаты задаются независимо от будущей реализации. Примеры используют
синтетические `team/app`, host `forge-a` и маркеры TOKEN_A/TOKEN_B;
это не данные установки. Каждый вариант строки требует собственной assertion.

| ID | Вход/воздействие → ожидаемый результат |
| --- | --- |
| G01 private | Private repo, оба read scopes и unit access; latest `v2.1.0`, branch main с SHA `a` × 40, issues total 4, pulls total 2 (включая draft) → четыре точных значения, 4 GET, token только в Authorization выбранного origin. |
| G02 no release | Latest 404, filtered release-list 200 + `[]` + total 0 → null/absent/no-release; ровно 2 release GET. Metadata не заменяет list. |
| G03 hidden repo | Latest 404 и filtered list 404 при скрытом private repo → null/failed/access, без anonymous retry и без no-release. |
| G04 release classes | Seed: draft v9, prerelease v8-rc, stable v2.1, plain tag v99 → native latest v2.1. Fake latest с draft/prerelease=true → invalid-data. |
| G05 release order | Stable v3 создан 1 октября, v2.1 — 2 октября; при равном времени проверить descending ID → выбор по времени/ID, без semver-сортировки. |
| G06 branch encoding | `feature/topic`, `123`, `release+fix`, `x%2Fy` → branch target соответственно `%2F`, literal 123, `%2B`, `%252F`; exact name + commit.id. `x?y#z` проверяет отсутствие query/fragment injection, без обещания допустимого Git name. |
| G07 tag is not branch | Branch `v2.1.0` возвращает 404, одноимённый tag существует → access; commit/ref/tag fallback GET = 0. |
| G08 wrong branch | Запрошен feature/topic, ответ main с корректным SHA → branch-unconfirmed, value null, memo entry отсутствует. |
| G09 complete picker | Metadata default develop; effective page limit 37, страницы 37+23, total 60 → все 60 уникальных веток, develop присутствует; короткая первая страница не EOF. |
| G10 hostile pagination | Duplicate/missing/contradictory totals/next, foreign origin/prefix escape, cycle/jump/opaque cursor, duplicate names, premature empty page, page 11 или >1000 branches → отказ целого picker, без частичного списка/запроса по raw Link. |
| G11 issue/PR split | Seed 4 open issues, 2 open PR (один draft), 9 closed issues и 8 closed PR → issues 4, PR 2; exact отдельные filters, без вычитания repo counter. |
| G12 invalid count | Total missing, -1, 1.5, NaN, 2147483648, duplicate 4/4 или 4/5; body/total противоречат друг другу → invalid-data/null, без fallback к длине страницы. |
| G13 measured zero | Issues 200 + `[]` + total 0 → value 0/status ok; transport/access failure рядом остаётся null, а не 0. |
| G14 least privilege | Только read:repository → issues access; только read:issue → commit access; оба scopes без private membership → access; оба с unit rights → успех. Admin/write не требуются, public-only token не получает private data. |
| G15 host isolation | Одинаковый token на forge-a, forge-b, другом порту/mount и GitHub; token forge-a выбран для forge-b → validation git_token_id до decrypt/HTTP (оба счётчика 0); cache/quota разных identities не объединяются. |
| G16 hostile input | Userinfo `forge-a.example@evil.example`, network-path/SSH, чужой suffix host, encoded slash/traversal, query с marker, fragment, backslash или CRLF token → validation, credential HTTP = 0, ввод не отражён в ошибке. |
| G17 SSRF/DNS | Loopback/RFC1918/metadata, ::1, mapped private, ULA/link-local, mixed DNS, rebinding → requests к запрещённому receiver = 0 даже при health private permission. Отдельный собственный public TLS host подтверждает положительную admission/pin. |
| G18 redirects | Каждый 301/302/303/307/308 на same-origin, foreign origin, downgrade, prefix escape или malformed Location → renamed, follow requests = 0, credential не переслан. |
| G19 rate | 429 с Retry-After 120 и body с TOKEN_A → rate-limit, eligible_at=now+120, дальнейших GET до eligibility = 0; no sleep/retry, marker отсутствует в outputs. |
| G20 rate metadata | 429 без/с duplicate/invalid/overflow/past header → local 60 s; valid HTTP-date → floor 60 s/cap 24 h. Обычный 403 → access без cooldown; malformed rate на валидном 200 сохраняет metric; 500/503 → unavailable. GitHub secondary не блокирует Gitea. |
| G21 rotation/ABA | Барьеры до admission, во время HTTP, перед memo hit/settlement; A→B, A→B→A, replacement/deletion или site host/provider change → credential-changed; stale current/history/incidents/notifications writes и worker credit = 0. Изменение identity самого host отвергается. |
| G22 token aliases | Один token у двух saved handles в одном provider/host → общая quota identity; другой host/provider и legacy GitHub domain изолированы. Rotation всегда инвалидирует revision memo. |
| G23 partial | Release/PR успешны, commit access, issues rate-limit, health online/version ok → Git partial, успешные поля сохранены, failed null; measured 0 остаётся 0, health/version сохранены. |
| G24 malformed/oversize | HTML 200, JSON scalar/depth>32/invalid UTF-8, NUL или tag>200 bytes, invalid SHA, headers>64 KiB, headers+body>1 MiB и deadline exhaustion → безопасный typed failure, без memo/raw payload. |
| G25 privacy inspector | TOKEN_A/B, endpoint/read API bearer и key marker в hostile upstream/error/closure → 0 occurrences в query/path, foreign requests, DTO/HTML/JS, CLI, history/incidents/notifications, Throwable previous/trace/native args и receipts. Сначала инспектор обязан найти marker в намеренно unsafe control; argument capture не отключается. |
| G26 migration | Fresh и каждый lineage 0–10, обе schema 5; seed ciphertext/child state/read API token и sequence>max ID → точное сохранение, backfill github-public. Unknown provider/lock/copy/DDL/FK/COMMIT fault → старая schema/data/user_version целы, FK ON восстановлен; отдельно held snapshot/backup/interruption. |
| G27 native consumers/API | GitHub-only v1 сохраняет exact schema/IDs/order/cursors; mixed case проверяет будущее явно принятое решение и v2 Gitea DTO/privacy. Stale UI preview после смены host/provider не применяется; save повторно проверяет branch; CLI field statuses совпадают, selector union ≤64. |
| G28 budgets | Обычный check=4 GET, no-release=5, preview≤11/1000 branches; 24 s/site, composed 100 GET/120 s, memo 256 → over-cap typed refusal без скрытых GET/retry/sleep; GitHub-only поведение сохранено, sync DNS caveat явно учтён. |
| G29 binding mutation | Rename/blank edit сохраняют identity/ciphertext (rename не повышает site revision); изменение token provider/host отвергнуто, нужен новый credential. Delete used token/host → RESTRICT; GitHub inline→Gitea без явного удаления/выбора отвергнут до decrypt/HTTP. |
| G30 release unit | Repo metadata 200, в том числе has_releases=true, но release unit disabled/unreadable; latest/list оба 404 → failed/access/null. Доступ к repo не превращается в no-release и не считается доказательством unit access. |

Unit проверяет exact request-target, headers, calls и semantic values. Network
использует настоящий cURL, синтетические credentials и собственный HTTPS/DNS
receiver с TLS/redirect/rotation barriers. Fixture-only loopback hook проверяет
wire, но не доказывает production public admission: нужны отдельные refusal
с нулём запросов к denied IP и положительный owned-public-origin тест.
Http проверяет session/CSRF и consumers; SQLite — native migrations/races;
отдельная одноразовая установка поддерживаемого Gitea с private repo подтверждает
реальные scopes/units, proxy encoding, headers и server clamps.

Исторический baseline — 436 test identities по
[contracts/php-bindings.json на pinned commit df0a5e8](https://github.com/ichinya/tablo/blob/df0a5e81edf8084835ddaec1d0edc0bbb7ff77dd/contracts/php-bindings.json).
Он не ограничивает сохраняемый набор: перед реализацией зафиксировать точный
commit актуальной базовой ветки и сохранить все его test identities и native
привязки, включая добавленные после исторического baseline. База этой редакции
плана `023503e8fe6a23a669ec115f1a2c6547ba01fbda` содержит 451 test identity,
включая 15 HSTS-тестов. Воспроизводимая инвентаризация выбранной базы:
прочитать command/query symbols
декларации, вычислить их native test-file union по правилам `tools/verify.php`
и перечислить `#[Test]` identities (namespace/class/method) в этих файлах.
Будущий набор обязан включать каждую identity исходного набора; отдельный
непроверенный числовой binding baseline не задаётся. В существующем
`composer verify` reviewed-dependency guard идёт первым, затем Mago lint/analyze
с Note threshold, model validate/offline lock/source fingerprints и native
attachment union. Не добавлять suppressions (baseline: 3 analysis, 23 lint),
не менять tools/locks, default subprocess 8 s, существующие helper cases 20 s
или selector cap 64 ради прохождения нового поведения. Capture — отдельное
явное действие после review diff, не доказательство корректности.

Для приёмки нужны per-test statuses/assertions, реальные process exits и сохранённые
stdout/stderr; hosted CI и native Gitea qualification учитываются отдельно.
Сценарии G01–G30 остаются плановыми. Адаптер и миграция Gitea не реализованы;
их проверки, private-repo и race qualification не выполнялись. Проверки существующего
проекта не доказывают поддержку Gitea. Неизвестными остаются поведение конкретной
поддерживаемой версии и proxy, runtime races/rebuild и решение по v1.

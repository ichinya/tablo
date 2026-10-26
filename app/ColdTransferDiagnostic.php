<?php
declare(strict_types=1);

namespace Tablo;

/** Temporary observer; all state is allocated before the unchanged actor baseline.
 * @phpstan-type State array{mode: string, loader: \Composer\Autoload\ClassLoader|null, output: resource|null,
 * classIds: array<string, int>, phaseIds: list<int>, phases: list<int>, stack: list<array{int, int, int, int, int}>,
 * loads: list<array{int, int, int, int}>, depth: int, loadDepth: int, phase: int, kind: int, file: int,
 * exports: int, verifies: int, imports: int, sqliteImports: int, factoryCheckpoint: int, armed: bool, broken: bool,
 * overflow: bool, written: bool, final: bool, started: int, winnerPeak: int, winnerUsed: int, winnerPhase: int,
 * winnerKind: int, winnerFile: int, includeBeforeUsed: int, includeAfterUsed: int, includeBeforePeak: int,
 * baseline: int, peak: int, working: int} */
final class ColdTransferDiagnostic
{
    /** @var array<int, string> */
    private const CLASS_NAMES = [
        1 => 'Tablo\\Tests\\Support\\EngineFixture',
        2 => 'Tablo\\Tests\\Support\\EngineSchedule',
        100 => 'Tablo\\AddressRange',
        101 => 'Tablo\\AdministratorCredentialWrite',
        102 => 'Tablo\\AdministratorFingerprint',
        103 => 'Tablo\\AdministratorPasswordChange',
        104 => 'Tablo\\AdminPasswordCommand',
        105 => 'Tablo\\ApiSiteDto',
        106 => 'Tablo\\ApiSiteState',
        107 => 'Tablo\\Auth',
        108 => 'Tablo\\AuthenticationPages',
        109 => 'Tablo\\BaseSchema',
        110 => 'Tablo\\BranchPresentation',
        111 => 'Tablo\\CheckAbortProjection',
        112 => 'Tablo\\CheckCommand',
        113 => 'Tablo\\CheckDiagnostics',
        114 => 'Tablo\\CheckFormat',
        115 => 'Tablo\\CheckHistoryRepository',
        116 => 'Tablo\\CheckHistorySnapshot',
        117 => 'Tablo\\CheckNotificationPass',
        118 => 'Tablo\\CheckObservation',
        119 => 'Tablo\\CheckOutput',
        120 => 'Tablo\\CheckProgress',
        121 => 'Tablo\\CheckRunReason',
        122 => 'Tablo\\CheckScalar',
        123 => 'Tablo\\CheckSettlementOutput',
        124 => 'Tablo\\CheckSettlementState',
        125 => 'Tablo\\CheckSiteFragment',
        126 => 'Tablo\\CheckSiteObservation',
        127 => 'Tablo\\CheckSiteOutput',
        128 => 'Tablo\\CheckSitePass',
        129 => 'Tablo\\CheckState',
        130 => 'Tablo\\ClientAddress',
        131 => 'Tablo\\ContainerHealth',
        132 => 'Tablo\\CurlFraming',
        133 => 'Tablo\\CurlGetFailure',
        134 => 'Tablo\\CurlGetOptions',
        135 => 'Tablo\\CurlGetTransport',
        136 => 'Tablo\\CurlWebhookOptions',
        137 => 'Tablo\\CurlWebhookTransport',
        138 => 'Tablo\\DashboardPage',
        139 => 'Tablo\\Database',
        140 => 'Tablo\\DatabaseConfiguration',
        141 => 'Tablo\\DatabaseConnectionFactory',
        142 => 'Tablo\\DatabaseEnvironment',
        143 => 'Tablo\\DatabaseFailure',
        144 => 'Tablo\\DatabaseInteger',
        145 => 'Tablo\\DatabaseMaintenanceCommand',
        146 => 'Tablo\\DatabaseSession',
        147 => 'Tablo\\DatabaseTransfer',
        148 => 'Tablo\\DatabaseTransferArchive',
        149 => 'Tablo\\DatabaseTransferImport',
        150 => 'Tablo\\DatabaseTransferManifest',
        151 => 'Tablo\\DatabaseTransferPreflight',
        152 => 'Tablo\\DatabaseTransferRows',
        153 => 'Tablo\\DatabaseTransferSequence',
        154 => 'Tablo\\DatabaseTransferTarget',
        155 => 'Tablo\\DatabaseTransferVerification',
        156 => 'Tablo\\DeploymentFields',
        157 => 'Tablo\\EndpointAuthority',
        158 => 'Tablo\\EndpointChain',
        159 => 'Tablo\\EndpointCheckAccess',
        160 => 'Tablo\\EndpointCredential',
        161 => 'Tablo\\EndpointHealthCheck',
        162 => 'Tablo\\EndpointHealthFailure',
        163 => 'Tablo\\EndpointHealthJson',
        164 => 'Tablo\\EndpointHealthStatus',
        165 => 'Tablo\\EndpointHost',
        166 => 'Tablo\\EndpointRedirect',
        167 => 'Tablo\\EndpointRequestHeaders',
        168 => 'Tablo\\EndpointResponse',
        169 => 'Tablo\\EndpointTarget',
        170 => 'Tablo\\EndpointTransition',
        171 => 'Tablo\\EndpointVersionCheck',
        172 => 'Tablo\\ExistingConnection',
        173 => 'Tablo\\ExistingDatabaseUri',
        174 => 'Tablo\\ExternalKeyAuthentication',
        175 => 'Tablo\\FormCheckbox',
        176 => 'Tablo\\FreshnessPresentation',
        177 => 'Tablo\\GetAddressAdmission',
        178 => 'Tablo\\GetTarget',
        179 => 'Tablo\\GitBranchItem',
        180 => 'Tablo\\GitBranchItems',
        181 => 'Tablo\\GitCheck',
        182 => 'Tablo\\GitCheckResult',
        183 => 'Tablo\\GitCooldownLedger',
        184 => 'Tablo\\GitCredentialCurrent',
        185 => 'Tablo\\GitCredentialLiveProof',
        186 => 'Tablo\\GitCredentialResolver',
        187 => 'Tablo\\GitCredentialSnapshot',
        188 => 'Tablo\\GitFieldAssignment',
        189 => 'Tablo\\GitFieldCheck',
        190 => 'Tablo\\GitFieldOutput',
        191 => 'Tablo\\GitFieldValidation',
        192 => 'Tablo\\GitHubBranches',
        193 => 'Tablo\\GitHubClock',
        194 => 'Tablo\\GitHubConnection',
        195 => 'Tablo\\GitHubCooldownRepository',
        196 => 'Tablo\\GitHubCount',
        197 => 'Tablo\\GitHubCredential',
        198 => 'Tablo\\GitHubFailure',
        199 => 'Tablo\\GitHubFrame',
        200 => 'Tablo\\GitHubMemo',
        201 => 'Tablo\\GitHubMetric',
        202 => 'Tablo\\GitHubProvider',
        203 => 'Tablo\\GitHubRateLimit',
        204 => 'Tablo\\GitHubRequest',
        205 => 'Tablo\\GitHubRequestPolicy',
        206 => 'Tablo\\GitHubResponse',
        207 => 'Tablo\\GitMetricValue',
        208 => 'Tablo\\GitMissingPolicy',
        209 => 'Tablo\\GitOutput',
        210 => 'Tablo\\GitOutputInvariant',
        211 => 'Tablo\\GitProviders',
        212 => 'Tablo\\GitRateEvidence',
        213 => 'Tablo\\GitRepositoryName',
        214 => 'Tablo\\GitRepositoryPath',
        215 => 'Tablo\\GitResolvedCredential',
        216 => 'Tablo\\GitResponseHeaders',
        217 => 'Tablo\\GitSecondaryEvidence',
        218 => 'Tablo\\GitTokenRepository',
        219 => 'Tablo\\HealthNotification',
        220 => 'Tablo\\HealthOutput',
        221 => 'Tablo\\HealthPresentation',
        222 => 'Tablo\\HistoryCodeDomain',
        223 => 'Tablo\\HistoryCursor',
        224 => 'Tablo\\HistoryCursorEncoding',
        225 => 'Tablo\\HistoryGitProjection',
        226 => 'Tablo\\HistoryHealthProjection',
        227 => 'Tablo\\HistoryPageCeiling',
        228 => 'Tablo\\HistoryPruner',
        229 => 'Tablo\\HistoryRetention',
        230 => 'Tablo\\HistoryScalar',
        231 => 'Tablo\\HistorySchema',
        232 => 'Tablo\\HistorySite',
        233 => 'Tablo\\HistoryTime',
        234 => 'Tablo\\HistoryVersionProjection',
        235 => 'Tablo\\HistoryVersionText',
        236 => 'Tablo\\HstsConfigurationError',
        237 => 'Tablo\\HstsPolicy',
        238 => 'Tablo\\HttpClient',
        239 => 'Tablo\\HttpDeadline',
        240 => 'Tablo\\HttpFailure',
        241 => 'Tablo\\HttpLocationHeader',
        242 => 'Tablo\\HttpResponseBuffer',
        243 => 'Tablo\\HttpResponseHeaders',
        244 => 'Tablo\\HttpScalar',
        245 => 'Tablo\\HttpSizeBudget',
        246 => 'Tablo\\IdleTimeoutInput',
        247 => 'Tablo\\IdleTimeoutSettings',
        248 => 'Tablo\\IncidentCursor',
        249 => 'Tablo\\IncidentObservation',
        250 => 'Tablo\\IncidentPage',
        251 => 'Tablo\\IncidentPageCeiling',
        252 => 'Tablo\\IncidentPresenter',
        253 => 'Tablo\\IncidentProjector',
        254 => 'Tablo\\IncidentQuery',
        255 => 'Tablo\\IncidentRepository',
        256 => 'Tablo\\IncidentSchema',
        257 => 'Tablo\\IncidentTransition',
        258 => 'Tablo\\InstallationException',
        259 => 'Tablo\\InstalledAdministrator',
        260 => 'Tablo\\InvalidHistorySnapshot',
        261 => 'Tablo\\IpAddress',
        262 => 'Tablo\\JsonComparison',
        263 => 'Tablo\\JsonEncoding',
        264 => 'Tablo\\JsonField',
        265 => 'Tablo\\JsonNumeric',
        266 => 'Tablo\\JsonPath',
        267 => 'Tablo\\JsonPathScalar',
        268 => 'Tablo\\JsonPathValue',
        269 => 'Tablo\\JsonQuotedKey',
        270 => 'Tablo\\JsonRecord',
        271 => 'Tablo\\JsonScalarText',
        272 => 'Tablo\\KeyCreation',
        273 => 'Tablo\\KeyFileInitialization',
        274 => 'Tablo\\KeyFilePath',
        275 => 'Tablo\\KeyFileRead',
        276 => 'Tablo\\KeyLocation',
        277 => 'Tablo\\KeyRequirement',
        278 => 'Tablo\\LagNotification',
        279 => 'Tablo\\ManualCheckPage',
        280 => 'Tablo\\MonotonicTime',
        281 => 'Tablo\\NativeWarning',
        282 => 'Tablo\\NotificationAcknowledgement',
        283 => 'Tablo\\NotificationChange',
        284 => 'Tablo\\NotificationCheckpointData',
        285 => 'Tablo\\NotificationCheckpointRebase',
        286 => 'Tablo\\NotificationCheckpointState',
        287 => 'Tablo\\NotificationCipher',
        288 => 'Tablo\\NotificationComparison',
        289 => 'Tablo\\NotificationConfirmation',
        290 => 'Tablo\\NotificationConfirmationTime',
        291 => 'Tablo\\NotificationDelivery',
        292 => 'Tablo\\NotificationDeliveryAdmission',
        293 => 'Tablo\\NotificationEnqueue',
        294 => 'Tablo\\NotificationFlags',
        295 => 'Tablo\\NotificationFormInput',
        296 => 'Tablo\\NotificationFormValue',
        297 => 'Tablo\\NotificationLock',
        298 => 'Tablo\\NotificationMaintenance',
        299 => 'Tablo\\NotificationMaintenanceRow',
        300 => 'Tablo\\NotificationMaintenanceStatus',
        301 => 'Tablo\\NotificationMetadataRefresh',
        302 => 'Tablo\\NotificationOutbox',
        303 => 'Tablo\\NotificationPendingMetadata',
        304 => 'Tablo\\NotificationPrivatePayload',
        305 => 'Tablo\\NotificationProjectionContext',
        306 => 'Tablo\\NotificationProjectionStore',
        307 => 'Tablo\\NotificationProjector',
        308 => 'Tablo\\NotificationResult',
        309 => 'Tablo\\NotificationResultFields',
        310 => 'Tablo\\NotificationRetryStatus',
        311 => 'Tablo\\NotificationSchema',
        312 => 'Tablo\\NotificationSettings',
        313 => 'Tablo\\NotificationSettingsStore',
        314 => 'Tablo\\NotificationStatus',
        315 => 'Tablo\\OutputTimestamp',
        316 => 'Tablo\\OwnedWebhookStop',
        317 => 'Tablo\\PageResponse',
        318 => 'Tablo\\PasswordConflict',
        319 => 'Tablo\\PasswordHashPolicy',
        320 => 'Tablo\\PasswordHashRuntime',
        321 => 'Tablo\\PasswordRuntimeFailure',
        322 => 'Tablo\\PasswordService',
        323 => 'Tablo\\PdoMysqlOptions',
        324 => 'Tablo\\PeriodicWorker',
        325 => 'Tablo\\PresentationTime',
        326 => 'Tablo\\Presenter',
        327 => 'Tablo\\ProtectedPasswordInput',
        328 => 'Tablo\\ProxyConfiguration',
        329 => 'Tablo\\PublicAddress',
        330 => 'Tablo\\PublicAddressRanges',
        331 => 'Tablo\\PublicDns',
        332 => 'Tablo\\ReadApi',
        333 => 'Tablo\\ReadApiConnection',
        334 => 'Tablo\\ReadApiRepository',
        335 => 'Tablo\\ReadApiRequest',
        336 => 'Tablo\\ReadApiResponse',
        337 => 'Tablo\\ReadApiSchemaAdmission',
        338 => 'Tablo\\ReadApiToken',
        339 => 'Tablo\\ReadApiTokenPages',
        340 => 'Tablo\\ReadApiTokenRepository',
        341 => 'Tablo\\ReadApiTokenSchema',
        342 => 'Tablo\\RecoveryNotification',
        343 => 'Tablo\\RegexCapture',
        344 => 'Tablo\\RegexReplacement',
        345 => 'Tablo\\RejectedSiteForm',
        346 => 'Tablo\\ReleasePresentation',
        347 => 'Tablo\\RepositoryProvider',
        348 => 'Tablo\\RequiredFile',
        349 => 'Tablo\\ResourceStreams',
        350 => 'Tablo\\RuntimeIni',
        351 => 'Tablo\\SavedGitTokenInput',
        352 => 'Tablo\\SavedGitTokenUpdate',
        353 => 'Tablo\\SchemaCheckShape',
        354 => 'Tablo\\SchemaColumnShape',
        355 => 'Tablo\\SchemaCompleteness',
        356 => 'Tablo\\SchemaForeignShape',
        357 => 'Tablo\\SchemaIndexShape',
        358 => 'Tablo\\SchemaLayout',
        359 => 'Tablo\\SchemaLayoutUpgrade',
        360 => 'Tablo\\SchemaLineage',
        361 => 'Tablo\\SchemaReference',
        362 => 'Tablo\\SchemaShape',
        363 => 'Tablo\\SchemaUpgrade',
        364 => 'Tablo\\SchemaVersion',
        365 => 'Tablo\\SelectedSiteToken',
        366 => 'Tablo\\ServerBootstrap',
        367 => 'Tablo\\ServerBootstrapReceipt',
        368 => 'Tablo\\ServerCheckAdmission',
        369 => 'Tablo\\ServerColumnAdmission',
        370 => 'Tablo\\ServerCredentials',
        371 => 'Tablo\\ServerDialect',
        372 => 'Tablo\\ServerExpression',
        373 => 'Tablo\\ServerExpressionAtom',
        374 => 'Tablo\\ServerExpressionOperator',
        375 => 'Tablo\\ServerExpressionTokens',
        376 => 'Tablo\\ServerForeignAdmission',
        377 => 'Tablo\\ServerIndexAdmission',
        378 => 'Tablo\\ServerKeyIdentity',
        379 => 'Tablo\\ServerLock',
        380 => 'Tablo\\ServerLockOwnership',
        381 => 'Tablo\\ServerMetadataAdmission',
        382 => 'Tablo\\ServerMetadataIdentity',
        383 => 'Tablo\\ServerMetadataInventory',
        384 => 'Tablo\\ServerMetadataPrivileges',
        385 => 'Tablo\\ServerMigration',
        386 => 'Tablo\\ServerMigrationJournal',
        387 => 'Tablo\\ServerMigrationLock',
        388 => 'Tablo\\ServerMigrationState',
        389 => 'Tablo\\ServerMigrationStep',
        390 => 'Tablo\\ServerPdoConnection',
        391 => 'Tablo\\ServerSchema',
        392 => 'Tablo\\ServerSchemaDescriptor',
        393 => 'Tablo\\ServerSchemaState',
        394 => 'Tablo\\ServerSessionAdmission',
        395 => 'Tablo\\ServerSessionSettings',
        396 => 'Tablo\\ServerSiteCheck',
        397 => 'Tablo\\ServerSiteSave',
        398 => 'Tablo\\ServerTableAdmission',
        399 => 'Tablo\\ServerTls',
        400 => 'Tablo\\ServerTokenSave',
        401 => 'Tablo\\ServerTransport',
        402 => 'Tablo\\ServerWriterAdmission',
        403 => 'Tablo\\SessionActivity',
        404 => 'Tablo\\SessionAdmission',
        405 => 'Tablo\\SessionAuthentication',
        406 => 'Tablo\\SessionExpiry',
        407 => 'Tablo\\SessionInitialization',
        408 => 'Tablo\\SessionResponse',
        409 => 'Tablo\\SessionResumption',
        410 => 'Tablo\\SessionSetupState',
        411 => 'Tablo\\SessionSnapshot',
        412 => 'Tablo\\SettingsPages',
        413 => 'Tablo\\SettingsRepository',
        414 => 'Tablo\\SettlementKind',
        415 => 'Tablo\\SharedKeyFailure',
        416 => 'Tablo\\SingletonAdministrator',
        417 => 'Tablo\\SiteBranchesPage',
        418 => 'Tablo\\SiteChecker',
        419 => 'Tablo\\SiteCheckInput',
        420 => 'Tablo\\SiteCheckWriter',
        421 => 'Tablo\\SiteCredentialAccess',
        422 => 'Tablo\\SiteCredentialIdentity',
        423 => 'Tablo\\SiteCredentialInput',
        424 => 'Tablo\\SiteCredentialRevision',
        425 => 'Tablo\\SiteDisplayInput',
        426 => 'Tablo\\SiteEndpointPaths',
        427 => 'Tablo\\SiteFormErrors',
        428 => 'Tablo\\SiteFormPage',
        429 => 'Tablo\\SiteFormPrivacy',
        430 => 'Tablo\\SiteIdentityInput',
        431 => 'Tablo\\SiteInput',
        432 => 'Tablo\\SiteJsonCondition',
        433 => 'Tablo\\SiteJsonPaths',
        434 => 'Tablo\\SiteKeyState',
        435 => 'Tablo\\SitePages',
        436 => 'Tablo\\SiteProjection',
        437 => 'Tablo\\SiteRepository',
        438 => 'Tablo\\SiteResultSettlement',
        439 => 'Tablo\\SiteRow',
        440 => 'Tablo\\SiteSaveValidation',
        441 => 'Tablo\\SiteSnapshotId',
        442 => 'Tablo\\SiteTextInput',
        443 => 'Tablo\\SiteTokenResolver',
        444 => 'Tablo\\SiteUrlInput',
        445 => 'Tablo\\SqlBindingType',
        446 => 'Tablo\\SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow',
        447 => 'Tablo\\SqlCheckHistoryHealthErrorCodeHealthHttpStatusOnlineRow',
        448 => 'Tablo\\SqlColumn',
        449 => 'Tablo\\SqlDefinitionConstraints',
        450 => 'Tablo\\SqlFileNameSeqRow',
        451 => 'Tablo\\SqlGithubCooldownsEligibleAtResourceScopeRow',
        452 => 'Tablo\\SqlGithubCooldownsResourceScopeRow',
        453 => 'Tablo\\SqlGitTokensCreatedAtEncryptedTokenIdRow',
        454 => 'Tablo\\SqlGitTokensCreatedAtIdNameRow',
        455 => 'Tablo\\SqlGitTokensEncryptedTokenProviderRow',
        456 => 'Tablo\\SqlGitTokensEncryptedTokenRow',
        457 => 'Tablo\\SqlIncidentCheckpointsRow',
        458 => 'Tablo\\SqlIncidentsClockInvalidConfigRevisionEndReasonRow',
        459 => 'Tablo\\SqlIncidentsSiteIdRow',
        460 => 'Tablo\\SqlJoinedRow',
        461 => 'Tablo\\SqlLexemes',
        462 => 'Tablo\\SqlLoginLimitsFailuresKeyRow',
        463 => 'Tablo\\SqlLoginLimitsFailuresKeyWindowStartRow',
        464 => 'Tablo\\SqlNotificationCheckpointsConfigRevisionLastHistoryIdSiteIdRow',
        465 => 'Tablo\\SqlNotificationCheckpointsSiteIdStateRow',
        466 => 'Tablo\\SqlNotificationSettingsActivationIdBearerCipherBlockedRow',
        467 => 'Tablo\\SqlNotificationSettingsActivationIdEnabledEndpointPresentRow',
        468 => 'Tablo\\SqlNotificationSettingsBearerCipherEnabledEndpointCipherRow',
        469 => 'Tablo\\SqlNotificationSettingsBearerPresentBlockedEnabledRow',
        470 => 'Tablo\\SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow',
        471 => 'Tablo\\SqlNotificationSlotsAcknowledgedAtAttemptsCoalescedRow',
        472 => 'Tablo\\SqlNotificationSlotsAttemptsLastCodeStatusRow',
        473 => 'Tablo\\SqlNotificationSlotsAttemptsSourceIdRow',
        474 => 'Tablo\\SqlRows',
        475 => 'Tablo\\SqlSitesBranchCheckedAtComparisonModeRow',
        476 => 'Tablo\\SqlSitesCheckedAtConfigRevisionHealthErrorCodeRow',
        477 => 'Tablo\\SqlSitesCheckedAtHealthErrorCodeHealthHttpStatusRow',
        478 => 'Tablo\\SqlSitesDeployedVersionHealthErrorCodeHealthHttpStatusRow',
        479 => 'Tablo\\SqlSitesDeployedVersionLastErrorOnlineRow',
        480 => 'Tablo\\SqlSitesGithubTokenRow',
        481 => 'Tablo\\SqlSitesHealthErrorCodeHealthHttpStatusRow',
        482 => 'Tablo\\SqlSitesIdRow',
        483 => 'Tablo\\SqlStatement',
        484 => 'Tablo\\SqlSyntax',
        485 => 'Tablo\\SqlUsersCreatedAtIdPasswordHashRow',
        486 => 'Tablo\\SqlUsersIdPasswordHashRow',
        487 => 'Tablo\\SqlValue',
        488 => 'Tablo\\SqlWorkerProgressRow',
        489 => 'Tablo\\Tests\\Support\\BarrierSignal',
        490 => 'Tablo\\Tests\\Support\\CheckSchema',
        491 => 'Tablo\\Tests\\Support\\CommandOptions',
        492 => 'Tablo\\Tests\\Support\\CompositionMigrationPdo',
        493 => 'Tablo\\Tests\\Support\\CookiePolicy',
        494 => 'Tablo\\Tests\\Support\\CurlAvailability',
        495 => 'Tablo\\Tests\\Support\\DashboardModesFixture',
        496 => 'Tablo\\Tests\\Support\\DashboardPortObserver',
        497 => 'Tablo\\Tests\\Support\\DatabaseAssertions',
        498 => 'Tablo\\Tests\\Support\\DatabaseConnectSchedule',
        499 => 'Tablo\\Tests\\Support\\DatabaseProcessObservation',
        500 => 'Tablo\\Tests\\Support\\DatabaseScheduleOwners',
        501 => 'Tablo\\Tests\\Support\\EndpointCheckFixture',
        502 => 'Tablo\\Tests\\Support\\EndpointFixture',
        503 => 'Tablo\\Tests\\Support\\EndpointPrivacyChild',
        504 => 'Tablo\\Tests\\Support\\EndpointPrivacyRecords',
        505 => 'Tablo\\Tests\\Support\\EngineBoundaryProbe',
        506 => 'Tablo\\Tests\\Support\\EngineProcess',
        507 => 'Tablo\\Tests\\Support\\FakeHttp',
        508 => 'Tablo\\Tests\\Support\\FakeProvider',
        509 => 'Tablo\\Tests\\Support\\FixtureBarrier',
        510 => 'Tablo\\Tests\\Support\\FixtureCounter',
        511 => 'Tablo\\Tests\\Support\\FixtureCredentials',
        512 => 'Tablo\\Tests\\Support\\FixtureFailureEvidence',
        513 => 'Tablo\\Tests\\Support\\FixtureFiles',
        514 => 'Tablo\\Tests\\Support\\FixtureJson',
        515 => 'Tablo\\Tests\\Support\\FixtureNative',
        516 => 'Tablo\\Tests\\Support\\FixturePipes',
        517 => 'Tablo\\Tests\\Support\\FixtureRejection',
        518 => 'Tablo\\Tests\\Support\\FixtureRemoval',
        519 => 'Tablo\\Tests\\Support\\FixtureRequest',
        520 => 'Tablo\\Tests\\Support\\FixtureSpawn',
        521 => 'Tablo\\Tests\\Support\\FixtureValue',
        522 => 'Tablo\\Tests\\Support\\FixtureWireRows',
        523 => 'Tablo\\Tests\\Support\\HealthCheckDiagnostics',
        524 => 'Tablo\\Tests\\Support\\HealthCheckFixture',
        525 => 'Tablo\\Tests\\Support\\HeldResponseProbe',
        526 => 'Tablo\\Tests\\Support\\HstsFixture',
        527 => 'Tablo\\Tests\\Support\\HttpClient',
        528 => 'Tablo\\Tests\\Support\\IncidentFixtures',
        529 => 'Tablo\\Tests\\Support\\JsonMutation',
        530 => 'Tablo\\Tests\\Support\\LegacyDatabaseSchedule',
        531 => 'Tablo\\Tests\\Support\\MeasuredGitHubHttp',
        532 => 'Tablo\\Tests\\Support\\MigrationChild',
        533 => 'Tablo\\Tests\\Support\\MigrationEvidence',
        534 => 'Tablo\\Tests\\Support\\MigrationPdo',
        535 => 'Tablo\\Tests\\Support\\MigrationProcess',
        536 => 'Tablo\\Tests\\Support\\MigrationShutdown',
        537 => 'Tablo\\Tests\\Support\\NotificationAssertions',
        538 => 'Tablo\\Tests\\Support\\NotificationFixture',
        539 => 'Tablo\\Tests\\Support\\PasswordHelperCapture',
        540 => 'Tablo\\Tests\\Support\\PasswordHelperCase',
        541 => 'Tablo\\Tests\\Support\\PasswordHelperDatabaseExport',
        542 => 'Tablo\\Tests\\Support\\PasswordHelperDatabaseFixture',
        543 => 'Tablo\\Tests\\Support\\PasswordHelperDatabaseTarget',
        544 => 'Tablo\\Tests\\Support\\PasswordHelperEvidence',
        545 => 'Tablo\\Tests\\Support\\PasswordHelperEvidenceFiles',
        546 => 'Tablo\\Tests\\Support\\PasswordHelperEvidenceStart',
        547 => 'Tablo\\Tests\\Support\\PasswordHelperFailureState',
        548 => 'Tablo\\Tests\\Support\\PasswordHelperFixtureObservation',
        549 => 'Tablo\\Tests\\Support\\PasswordHelperFixtureReadiness',
        550 => 'Tablo\\Tests\\Support\\PasswordProcess',
        551 => 'Tablo\\Tests\\Support\\PasswordRacePhase',
        552 => 'Tablo\\Tests\\Support\\RawHttpServer',
        553 => 'Tablo\\Tests\\Support\\ReadApiAssertions',
        554 => 'Tablo\\Tests\\Support\\ReadApiFixture',
        555 => 'Tablo\\Tests\\Support\\RedirectAssertions',
        556 => 'Tablo\\Tests\\Support\\RedirectServer',
        557 => 'Tablo\\Tests\\Support\\ScheduleObservation',
        558 => 'Tablo\\Tests\\Support\\ScheduleSourceCopy',
        559 => 'Tablo\\Tests\\Support\\SchemaBounds',
        560 => 'Tablo\\Tests\\Support\\SchemaFixture',
        561 => 'Tablo\\Tests\\Support\\SchemaItems',
        562 => 'Tablo\\Tests\\Support\\SchemaLogic',
        563 => 'Tablo\\Tests\\Support\\SchemaObject',
        564 => 'Tablo\\Tests\\Support\\SchemaObservationPdo',
        565 => 'Tablo\\Tests\\Support\\SchemaReference',
        566 => 'Tablo\\Tests\\Support\\SchemaScalar',
        567 => 'Tablo\\Tests\\Support\\SchemaType',
        568 => 'Tablo\\Tests\\Support\\ServerClosure',
        569 => 'Tablo\\Tests\\Support\\ServerCommand',
        570 => 'Tablo\\Tests\\Support\\ServerPort',
        571 => 'Tablo\\Tests\\Support\\SessionEvidence',
        572 => 'Tablo\\Tests\\Support\\SessionFixture',
        573 => 'Tablo\\Tests\\Support\\SharedDeadlineProbe',
        574 => 'Tablo\\Tests\\Support\\StderrCapture',
        575 => 'Tablo\\Tests\\Support\\Subprocess',
        576 => 'Tablo\\Tests\\Support\\SubprocessClosure',
        577 => 'Tablo\\Tests\\Support\\SubprocessCompletion',
        578 => 'Tablo\\Tests\\Support\\SubprocessDiagnostic',
        579 => 'Tablo\\Tests\\Support\\SubprocessFailure',
        580 => 'Tablo\\Tests\\Support\\SubprocessInput',
        581 => 'Tablo\\Tests\\Support\\SubprocessLaunch',
        582 => 'Tablo\\Tests\\Support\\SubprocessObservation',
        583 => 'Tablo\\Tests\\Support\\SubprocessReceipt',
        584 => 'Tablo\\Tests\\Support\\SubprocessReceiptData',
        585 => 'Tablo\\Tests\\Support\\SubprocessReceiptEvents',
        586 => 'Tablo\\Tests\\Support\\SubprocessReceiptStreams',
        587 => 'Tablo\\Tests\\Support\\SubprocessSelection',
        588 => 'Tablo\\Tests\\Support\\SubprocessState',
        589 => 'Tablo\\Tests\\Support\\TemporaryDirectory',
        590 => 'Tablo\\Tests\\Support\\TestServer',
        591 => 'Tablo\\Tests\\Support\\TraceInspector',
        592 => 'Tablo\\Tests\\Support\\TraceObjectInspection',
        593 => 'Tablo\\Tests\\Support\\TraceTestIsolation',
        594 => 'Tablo\\Tests\\Support\\TraceTraversal',
        595 => 'Tablo\\Tests\\Support\\TraceValueInspection',
        596 => 'Tablo\\Tests\\Support\\TransferPublicationFault',
        597 => 'Tablo\\Tests\\Support\\TransferSqliteFixture',
        598 => 'Tablo\\Tests\\Support\\TrustedProxy',
        599 => 'Tablo\\Tests\\Support\\UnitFixtures',
        600 => 'Tablo\\Tests\\Support\\WalHolderPolicy',
        601 => 'Tablo\\Tests\\Support\\WebFixture',
        602 => 'Tablo\\Tests\\Support\\WebhookCertificate',
        603 => 'Tablo\\Tests\\Support\\WebhookReceiver',
        604 => 'Tablo\\Tests\\Support\\WebRequestOptions',
        605 => 'Tablo\\Tests\\Support\\WorkerHttp',
        606 => 'Tablo\\Tests\\Support\\WorkerObservation',
        607 => 'Tablo\\Tests\\Support\\WorkerProcess',
        608 => 'Tablo\\Tests\\Support\\WorkerStreams',
        609 => 'Tablo\\TokenInput',
        610 => 'Tablo\\TokenPages',
        611 => 'Tablo\\TokenVault',
        612 => 'Tablo\\TrustedProxyChain',
        613 => 'Tablo\\UnavailableNotification',
        614 => 'Tablo\\UnavailableRepositoryProvider',
        615 => 'Tablo\\UriPath',
        616 => 'Tablo\\UriReference',
        617 => 'Tablo\\UriRelativePath',
        618 => 'Tablo\\UriSyntax',
        619 => 'Tablo\\ValidationException',
        620 => 'Tablo\\VaultKeyFile',
        621 => 'Tablo\\VaultLocation',
        622 => 'Tablo\\VersionOutput',
        623 => 'Tablo\\VersionPresentation',
        624 => 'Tablo\\VersionResponse',
        625 => 'Tablo\\WalAttempt',
        626 => 'Tablo\\WalAttemptResult',
        627 => 'Tablo\\WalNegotiation',
        628 => 'Tablo\\WalRetryAdmission',
        629 => 'Tablo\\Web',
        630 => 'Tablo\\WebAction',
        631 => 'Tablo\\WebhookAddress',
        632 => 'Tablo\\WebhookAddressAdmission',
        633 => 'Tablo\\WebhookChildInput',
        634 => 'Tablo\\WebhookChildLaunch',
        635 => 'Tablo\\WebhookChildResult',
        636 => 'Tablo\\WebhookChildResultShape',
        637 => 'Tablo\\WebhookChildWait',
        638 => 'Tablo\\WebhookCustody',
        639 => 'Tablo\\WebhookElapsed',
        640 => 'Tablo\\WebhookFailure',
        641 => 'Tablo\\WebhookFailureResult',
        642 => 'Tablo\\WebhookFrame',
        643 => 'Tablo\\WebhookHostSyntax',
        644 => 'Tablo\\WebhookPayload',
        645 => 'Tablo\\WebhookProcess',
        646 => 'Tablo\\WebhookPublicAddress',
        647 => 'Tablo\\WebhookResponseBudget',
        648 => 'Tablo\\WebhookStoppedResult',
        649 => 'Tablo\\WebhookSupervisor',
        650 => 'Tablo\\WebPage',
        651 => 'Tablo\\WebPostAdmission',
        652 => 'Tablo\\WebRouteAdmission',
        653 => 'Tablo\\WebRoutes',
        654 => 'Tablo\\WebSession',
        655 => 'Tablo\\WorkerFieldOrder',
        656 => 'Tablo\\WorkerHooks',
        657 => 'Tablo\\WorkerLock',
        658 => 'Tablo\\WorkerPass',
        659 => 'Tablo\\WorkerRunCleanup',
        660 => 'Tablo\\WorkerRuntime',
        661 => 'Tablo\\WorkerServiceCredit',
        662 => 'Tablo\\WorkerSiteCheck',
        663 => 'Tablo\\WorkerSitePriority',
        664 => 'Tablo\\WorkerStateRepository',
        665 => 'Tablo\\WriteTransaction',
    ];
    /** @var State|null */
    private static ?array $state = null;

    public static function setup(): void { self::$state = cold_transfer_diagnostic_setup(self::CLASS_NAMES); }
    public static function load(string $class): void { if (self::$state !== null) { cold_transfer_diagnostic_load(self::$state, $class); } }
    public static function enter(int $operation): void { if (self::$state !== null) { cold_transfer_diagnostic_enter(self::$state, $operation); } }
    public static function leave(): void { if (self::$state !== null) { cold_transfer_diagnostic_leave(self::$state); } }
    public static function factoryStart(): void { if (self::$state !== null) { cold_transfer_diagnostic_factory_start(self::$state); } }
    public static function factoryStep(int $step): void { if (self::$state !== null) { cold_transfer_diagnostic_factory_step(self::$state, $step); } }
    public static function factoryFinish(): void { if (self::$state !== null) { cold_transfer_diagnostic_factory_finish(self::$state); } }
    public static function capture(#[\SensitiveParameter] mixed $value): void { if (self::$state !== null) { cold_transfer_diagnostic_capture(self::$state, $value); } }
    public static function shutdown(): void { if (self::$state !== null && !self::$state['written']) { cold_transfer_diagnostic_write(self::$state); } }
}

/**
 * @param array<int, string> $names
 * @return \Tablo\ColdTransferDiagnostic::State|null */
function cold_transfer_diagnostic_setup(array $names): ?array
{
    $mode = getenv('TABLO_TEST_COLD_R77'); $plan = getenv('TABLO_TEST_CELL_PLAN'); $output = null;
    if (!in_array($mode, ['observe', 'inactive'], strict: true) || !is_string($plan)) { return null; }
    try {
        $directory = cold_transfer_diagnostic_directory($plan);
        if ($directory === null) { return null; }
        $output = fopen($directory . '/cold-r77.json', mode: 'x+b');
        if ($output === false) { return null; }
        $state = cold_transfer_diagnostic_state($mode, $output, $names);
        cold_transfer_diagnostic_register($state);
        return $state;
    } catch (\Throwable) {
        if (is_resource($output)) { try { fclose($output); } catch (\Throwable) { $output = null; } }
        return null;
    }
}

function cold_transfer_diagnostic_directory(string $plan): ?string
{
    $normalized = str_replace(search: '\\', replace: '/', subject: $plan); $resolved = realpath($plan); $stat = lstat($plan);
    if (!str_ends_with($normalized, '/evidence/started-cell.json') || $resolved === false || $stat === false
        || str_replace(search: '\\', replace: '/', subject: $resolved) !== $normalized || ($stat['mode'] & 0o170_000) !== 0o100_000) { return null; }
    $directory = dirname($plan); $cursor = $directory;
    while (true) {
        $part = lstat($cursor);
        if ($part === false || ($part['mode'] & 0o170_000) !== 0o040_000) { return null; }
        $parent = dirname($cursor); if ($parent === $cursor) { return $directory; } $cursor = $parent;
    }
}

/**
 * @param resource $output
 * @param array<int, string> $names
 * @return \Tablo\ColdTransferDiagnostic::State */
function cold_transfer_diagnostic_state(string $mode, mixed $output, array $names): array
{
    $state = ['mode' => $mode, 'loader' => null, 'output' => $output, 'classIds' => array_flip($names),
        'phaseIds' => [10, 11, 20, 30, 40, 41, 51, 52, 53, 54, 55, 56, 57, 58], 'phases' => [], 'stack' => [], 'loads' => [],
        'depth' => 0, 'loadDepth' => 0, 'phase' => 0, 'kind' => 3, 'file' => 0, 'exports' => 0, 'verifies' => 0, 'imports' => 0,
        'sqliteImports' => 0, 'factoryCheckpoint' => 0, 'armed' => false, 'broken' => false, 'overflow' => false, 'written' => false,
        'final' => false, 'started' => -1, 'winnerPeak' => -1, 'winnerUsed' => -1, 'winnerPhase' => 0, 'winnerKind' => 0,
        'winnerFile' => 0, 'includeBeforeUsed' => -1, 'includeAfterUsed' => -1, 'includeBeforePeak' => -1, 'baseline' => -1, 'peak' => -1, 'working' => -1];
    foreach ($state['phaseIds'] as $id) { array_push($state['phases'], $id, 0, $mode === 'observe' ? 0 : -1, -1, -1, -1); }
    for ($index = 0; $index < 32; $index++) { $state['stack'][] = [0, 0, 0, 0, -1]; $state['loads'][] = [0, 0, -1, -1]; }
    return $state;
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_register(array &$state): void
{
    foreach (spl_autoload_functions() as $callback) {
        if (!is_array($callback) || count($callback) !== 2 || !($callback[0] instanceof \Composer\Autoload\ClassLoader) || $callback[1] !== 'loadClass'
            || get_class($callback[0]) !== 'Composer\\Autoload\\ClassLoader') { continue; }
        if ($state['loader'] !== null) { $state['broken'] = true; }
        $state['loader'] = $callback[0];
    }
    if ($state['loader'] === null) { $state['broken'] = true; }
    if (!$state['broken']) { spl_autoload_register([ColdTransferDiagnostic::class, 'load'], throw: true, prepend: true); }
    register_shutdown_function([ColdTransferDiagnostic::class, 'shutdown']);
}
/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_load(array &$state, string $class): void
{
    $id = $state['classIds'][$class] ?? 0;
    $loader = $state['loader'];
    if ($id === 0 || $loader === null) { return; }
    if (!cold_transfer_diagnostic_arm($state, $id)) { $loader->loadClass($class); return; }
    cold_transfer_diagnostic_sample($state);
    $slot = $state['loadDepth']; $frame = $state['loads'][$slot] ?? null;
    if ($slot < 0 || $slot >= 32 || $frame === null) { cold_transfer_diagnostic_overflow($state); $loader->loadClass($class); return; }
    $state['loadDepth'] = $slot + 1;
    $frame[0] = $state['kind']; $frame[1] = $state['file'];
    $frame[2] = memory_get_usage(false); $frame[3] = memory_get_peak_usage(false); $state['loads'][$slot] = $frame;
    $state['kind'] = 2; $state['file'] = $id;
    try { $loader->loadClass($class); }
    finally {
        cold_transfer_diagnostic_sample($state, $frame[2], $frame[3]);
        $state['kind'] = $frame[0]; $state['file'] = $frame[1]; $state['loadDepth'] = $slot;
    }
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_arm(array &$state, int $id): bool
{
    if ($state['mode'] === 'observe' && !$state['armed'] && !$state['broken'] && $id === 1) {
        $state['armed'] = true; $state['started'] = cold_transfer_diagnostic_clock();
        if ($state['started'] < 0) { $state['broken'] = true; }
    }
    return $state['armed'] && !$state['final'];
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_enter(array &$state, int $operation): void
{
    if ($state['mode'] === '' || $state['final']) { return; }
    $row = match ($operation) { 1 => $state['exports']++ === 0 ? 0 : 1, 2 => 2, 3 => 3, 4 => $state['verifies']++ === 0 ? 4 : 5, default => 0 };
    if ($operation === 2) { $state['imports']++; } if ($operation === 3) { $state['sqliteImports']++; }
    cold_transfer_diagnostic_push($state, $row);
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_leave(array &$state): void
{
    if ($state['mode'] === '' || $state['final'] || $state['depth'] === 0) { return; }
    $slot = $state['depth'] - 1; $frame = $state['stack'][$slot] ?? null;
    if ($frame === null) { cold_transfer_diagnostic_overflow($state); return; }
    cold_transfer_diagnostic_end_row($state, $frame[3], $frame[4]);
    $state['depth'] = $slot; $state['phase'] = $frame[0]; $state['kind'] = $frame[1]; $state['file'] = $frame[2];
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_factory_start(array &$state): void
{ if ($state['mode'] !== '' && !$state['final']) { $state['factoryCheckpoint'] = 1; cold_transfer_diagnostic_push($state, row: 6); } }

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_factory_step(array &$state, int $step): void
{
    if ($state['mode'] === '' || $state['final'] || $state['depth'] === 0) { return; }
    $slot = $state['depth'] - 1; $frame = $state['stack'][$slot] ?? null;
    if ($step < 2 || $step > 8 || $frame === null) { cold_transfer_diagnostic_overflow($state); return; }
    cold_transfer_diagnostic_end_row($state, $frame[3], $frame[4]);
    $state['factoryCheckpoint'] = $step; $state['phase'] = 50 + $step;
    $frame[3] = $step + 5; $frame[4] = cold_transfer_diagnostic_start_row($state, $step + 5); $state['stack'][$slot] = $frame;
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_factory_finish(array &$state): void
{ cold_transfer_diagnostic_factory_step($state, step: 8); cold_transfer_diagnostic_leave($state); }

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_push(array &$state, int $row): void
{
    cold_transfer_diagnostic_sample($state);
    $slot = $state['depth']; $frame = $state['stack'][$slot] ?? null; $phase = $state['phaseIds'][$row] ?? null;
    if ($slot < 0 || $slot >= 32 || $frame === null || $phase === null) { cold_transfer_diagnostic_overflow($state); return; }
    $state['depth'] = $slot + 1;
    $frame[0] = $state['phase']; $frame[1] = $state['kind']; $frame[2] = $state['file'];
    $frame[3] = $row; $state['phase'] = $phase; $state['kind'] = 1; $state['file'] = 0;
    $frame[4] = cold_transfer_diagnostic_start_row($state, $row); $state['stack'][$slot] = $frame;
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_start_row(array &$state, int $row): int
{
    $offset = cold_transfer_diagnostic_row_offset($state, $row);
    if ($offset === null) { return -1; }
    $count = $state['phases'][$offset + 1] ?? null;
    if ($count === null) { cold_transfer_diagnostic_overflow($state); return -1; }
    $state['phases'][$offset + 1] = $count + 1;
    if (!$state['armed'] || $state['final']) { return -1; }
    $state['phases'][$offset + 3] = memory_get_usage(false); $clock = cold_transfer_diagnostic_clock();
    if ($clock < 0) { $state['broken'] = true; $state['phases'][$offset + 2] = -1; }
    return $clock;
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_end_row(array &$state, int $row, int $started): void
{
    if (!$state['armed'] || $state['final'] || $started < 0) { return; }
    cold_transfer_diagnostic_sample($state); $offset = cold_transfer_diagnostic_row_offset($state, $row);
    if ($offset === null) { return; }
    $total = $state['phases'][$offset + 2] ?? null;
    if ($total === null) { cold_transfer_diagnostic_overflow($state); return; }
    $clock = cold_transfer_diagnostic_clock();
    $valid = $clock >= $started && $total >= 0;
    $state['broken'] = $state['broken'] || !$valid;
    $state['phases'][$offset + 2] = $valid ? $total + $clock - $started : -1;
    $state['phases'][$offset + 4] = memory_get_usage(false); $state['phases'][$offset + 5] = memory_get_peak_usage(false);
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_row_offset(array &$state, int $row): ?int
{
    if ($row < 0 || $row >= 14) { cold_transfer_diagnostic_overflow($state); return null; }
    $offset = $row * 6;
    for ($index = 0; $index < 6; $index++) {
        if (array_key_exists($offset + $index, $state['phases'])) { continue; }
        cold_transfer_diagnostic_overflow($state); return null;
    }
    return $offset;
}

function cold_transfer_diagnostic_clock(): int
{
    $clock = hrtime(as_number: true);
    return is_int($clock) && $clock >= 0 ? $clock : -1;
}

function cold_transfer_diagnostic_elapsed(int $started): int
{
    if ($started < 0) { return -1; }
    $clock = cold_transfer_diagnostic_clock();
    return $clock < $started ? -1 : $clock - $started;
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_sample(array &$state, int $includeUsed = -1, int $includePeak = -1): void
{
    if (!$state['armed'] || $state['final']) { return; }
    $used = memory_get_usage(false); $peak = memory_get_peak_usage(false);
    if ($peak <= $state['winnerPeak']) { return; }
    if ($state['kind'] === 2 && $includeUsed < 0 && $state['loadDepth'] > 0) {
        $frame = $state['loads'][$state['loadDepth'] - 1] ?? null;
        if ($frame === null) { cold_transfer_diagnostic_overflow($state); return; }
        $includeUsed = $frame[2]; $includePeak = $frame[3];
    }
    $state['winnerPeak'] = $peak; $state['winnerUsed'] = $used; $state['winnerPhase'] = $state['phase'];
    $state['winnerKind'] = $state['kind']; $state['winnerFile'] = $state['file'];
    $state['includeBeforeUsed'] = $state['kind'] === 2 ? $includeUsed : -1;
    $state['includeAfterUsed'] = $state['kind'] === 2 ? $used : -1;
    $state['includeBeforePeak'] = $state['kind'] === 2 ? $includePeak : -1;
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_overflow(array &$state): void
{ $state['overflow'] = true; $state['broken'] = true; $state['armed'] = false; }

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_capture(array &$state, #[\SensitiveParameter] mixed $value): void
{
    if ($state['mode'] === '' || $state['written'] || $state['verifies'] !== 2 || $state['exports'] !== 2
        || $state['imports'] !== 1 || $state['sqliteImports'] !== 1 || $state['depth'] !== 0) { return; }
    $captured = cold_transfer_diagnostic_result($value);
    if ($captured === null) { return; }
    $state['baseline'] = $captured['baseline']; $state['peak'] = $captured['peak']; $state['working'] = $captured['working'];
    $state['final'] = true; cold_transfer_diagnostic_write($state);
}

/** @return array{baseline: int, peak: int, working: int}|null */
function cold_transfer_diagnostic_result(#[\SensitiveParameter] mixed $value): ?array
{
    if (!is_array($value) || array_keys($value) !== ['status', 'tables', 'payloadBytes', 'baselinePhpBytes', 'peakPhpBytes', 'peakPhpWorkingBytes',
        'nativeBufferedSource', 'allTypedDigestsPreserved', 'metricScope']
        || ($value['status'] ?? null) !== 'passed' || ($value['tables'] ?? null) !== 15 || ($value['payloadBytes'] ?? null) !== 11_520_000
        || ($value['nativeBufferedSource'] ?? null) !== false || ($value['allTypedDigestsPreserved'] ?? null) !== true
        || ($value['metricScope'] ?? null) !== 'PHP used-memory peak from after autoload before any fixture/admission/helper warmup through complete roundtrip; external native allocator RSS is not this metric') { return null; }
    return cold_transfer_diagnostic_result_peak($value);
}

/** @param array $value
 * @return array{baseline: int, peak: int, working: int}|null */
function cold_transfer_diagnostic_result_peak(#[\SensitiveParameter] array $value): ?array
{
    if (!is_int($value['baselinePhpBytes'] ?? null) || !is_int($value['peakPhpBytes'] ?? null)
        || !is_int($value['peakPhpWorkingBytes'] ?? null)) { return null; }
    $baseline = $value['baselinePhpBytes']; $peak = $value['peakPhpBytes']; $working = $value['peakPhpWorkingBytes'];
    if ($baseline < 0 || $peak < $baseline
        || $working !== $peak - $baseline) { return null; }
    return ['baseline' => $baseline, 'peak' => $peak, 'working' => $working];
}

/** @param \Tablo\ColdTransferDiagnostic::State $state */
function cold_transfer_diagnostic_write(array &$state): void
{
    $state['written'] = true;
    if (!is_resource($state['output'])) { return; }
    try {
        $bytes = json_encode(value: ['schema' => 'tablo/cold-r77/v1', 'mode' => $state['mode'], 'status' => $state['final'] ? 'captured' : 'incomplete',
            'instrumentedOnly' => true, 'observerContaminated' => true, 'armed' => $state['armed'],
            'finalCapture' => $state['final'], 'capturedPeakKnown' => $state['final'],
            'baselinePhpBytes' => $state['baseline'], 'peakPhpBytes' => $state['peak'], 'peakPhpWorkingBytes' => $state['working'],
            'tailUnknown' => !$state['final'] || !$state['armed'] || $state['broken'] || $state['peak'] > $state['winnerPeak'],
            'winnerKind' => $state['winnerKind'], 'winnerPhase' => $state['winnerPhase'], 'winnerFile' => $state['winnerFile'],
            'winnerPeakPhpBytes' => $state['winnerPeak'], 'winnerUsedPhpBytes' => $state['winnerUsed'],
            'includeBeforeUsedPhpBytes' => $state['includeBeforeUsed'], 'includeAfterUsedPhpBytes' => $state['includeAfterUsed'],
            'includeBeforePeakPhpBytes' => $state['includeBeforePeak'], 'elapsedNs' => cold_transfer_diagnostic_elapsed($state['started']),
            'depthOverflow' => $state['overflow'], 'factoryCheckpoint' => $state['factoryCheckpoint'], 'phases' => $state['phases']], flags: JSON_THROW_ON_ERROR);
        if ($bytes === false) { $state['broken'] = true; return; }
        if (strlen($bytes) <= 16_384) { fwrite($state['output'], $bytes); fflush($state['output']); }
    } catch (\Throwable) { $state['broken'] = true; }
    finally { try { fclose($state['output']); } catch (\Throwable) { $state['broken'] = true; } $state['output'] = null; }
}


param(
    [ValidateSet('missing-healthy', 'missing-disposed', 'prefix-healthy', 'prefix-disposed', 'complete', 'complete-extra', 'delayed-entry', 'no-entry', 'entry-before-marker', 'entry-no-marker', 'marker-no-entry', 'restricted-bootstrap')]
    [string] $Control,
    [string] $Journal,
    [ValidatePattern('^case-[a-f0-9]{24}$')]
    [string] $CaseId
)

# Prove the real pre-helper path without ambient cmdlet discovery or real tools.
if ($Control -eq 'restricted-bootstrap') {
    $ErrorActionPreference = 'Stop'
    $PSModuleAutoLoadingPreference = 'None'
    $restrictedRoot = [IO.Path]::GetDirectoryName($Journal)
    $env:PSModulePath = [IO.Path]::Combine($restrictedRoot, 'empty-module-path')
    $null = [IO.Directory]::CreateDirectory($env:PSModulePath)
    function Join-Path { throw 'Unexpected implicit fixture path command.' }
    function New-Object { throw 'Unexpected implicit fixture constructor command.' }
    function ConvertTo-Json { throw 'Unexpected implicit fixture serializer command.' }
    & ([IO.Path]::Combine($PSScriptRoot, 'password-helper-input.ps1')) `
        -Helper ([IO.Path]::Combine($restrictedRoot, 'helper-must-not-run.ps1')) `
        -Php ([IO.Path]::Combine($restrictedRoot, 'php-must-not-run.exe')) `
        -HostEncoding default -Scenario unicode -Mode Native `
        -ObservationPath ([IO.Path]::Combine($restrictedRoot, 'native-observation.jsonl')) `
        -FixtureObservationPath $Journal -CaseId $CaseId -CasePhase fixture-control -FixtureControl pre-helper-stall
    throw 'Restricted pre-helper fixture returned unexpectedly.'
}

# Fixed body-entry evidence only; diagnostic failure must preserve the fixture outcome.
try { [Console]::Out.WriteLine('HELPER_FIXTURE_BODY=admitted') } catch { }

$ErrorActionPreference = 'Stop'
$FixtureObservationPath = $Journal
$HostEncoding = 'default'; $Scenario = 'unicode'; $CasePhase = 'primary'; $Mode = 'Native'; $FixtureControl = 'none'
$ObservationPath = ''; $fixtureObservationPresence = @{}; $fixtureObservationEncoding = $null
. ([IO.Path]::Combine($PSScriptRoot, 'password-helper-fixture-observation.ps1'))

# Component controls invoke the real writer, never the helper, PHP, or a database.
if ($Control -in @('entry-before-marker', 'entry-no-marker', 'marker-no-entry')) {
    $CasePhase = 'fixture-control'; $FixtureControl = 'pre-helper-stall'
    if ($Control -eq 'marker-no-entry') { [Console]::Out.WriteLine('HELPER_OBSERVER_CONTROL=entry') }
    else {
        Write-FixtureObservation 'fixture-entry'
        [Console]::Out.WriteLine('HELPER_OBSERVER_CONTROL=journal-ready')
        if ($Control -eq 'entry-before-marker') {
            $releaseMarker = Join-Path (Split-Path -Parent $Journal) 'release-marker'
            while (-not (Test-Path -LiteralPath $releaseMarker)) { Start-Sleep -Milliseconds 20 }
            Start-Sleep -Milliseconds 600
            [Console]::Out.WriteLine('HELPER_OBSERVER_CONTROL=entry')
        }
    }
    while ($true) { Start-Sleep -Milliseconds 100 }
}

if ($Control -in @('delayed-entry', 'no-entry')) {
    $CasePhase = 'fixture-control'; $FixtureControl = 'pre-helper-stall'
    if ($Control -eq 'delayed-entry') {
        Start-Sleep -Seconds 9
        Write-FixtureObservation 'fixture-entry'
        [Console]::Out.WriteLine('HELPER_OBSERVER_CONTROL=entry')
    }
    while ($true) { Start-Sleep -Milliseconds 100 }
}

if ($Control -in @('complete', 'complete-extra')) {
    foreach ($stage in @('fixture-entry', 'before-encoding-initialization', 'after-encoding-initialization', 'before-resolver-setup', 'after-resolver-setup', 'before-callback-setup', 'after-callback-setup', 'before-helper-call', 'after-helper-call')) {
        Write-FixtureObservation $stage
    }
    if ($Control -eq 'complete-extra') {
        # Deliberate structurally incomplete record after the real writer's complete journal.
        [IO.File]::AppendAllText($Journal, '{}' + [Environment]::NewLine, (New-Object Text.UTF8Encoding($false, $true)))
    }
} else {
    $failureStage = 'fixture-entry'
    if ($Control.StartsWith('prefix-')) {
        Write-FixtureObservation 'fixture-entry'
        $failureStage = 'before-encoding-initialization'
    }
    $FixtureObservationPath = Join-Path (Split-Path -Parent $Journal) 'absent-owned-observer-parent/journal.jsonl'
    if (Test-Path -LiteralPath (Split-Path -Parent $FixtureObservationPath)) { throw 'Observer control precondition failed.' }
    $savedError = [Console]::Error
    $diagnostic = New-Object IO.StringWriter
    if ($Control.EndsWith('-disposed')) { $diagnostic.Dispose() }
    try {
        [Console]::SetError($diagnostic)
        Write-FixtureObservation $failureStage
    } finally { [Console]::SetError($savedError) }
    # Forward only the real writer's fixed signal after restoring the transport.
    [Console]::Error.Write($diagnostic.ToString())
    $diagnostic.Dispose()
}

[Console]::Out.WriteLine('HELPER_OBSERVER_CONTROL_RESULT=' + (@{
    failedLatch = $fixtureObservationState.failed
    noHelperExecuted = $true
    wrapperPid = $PID
    powerShell = $PSVersionTable.PSVersion.ToString()
    dotNet = [Environment]::Version.ToString()
} | ConvertTo-Json -Compress))
[Console]::Out.WriteLine('HELPER_OBSERVER_CONTROL=caller-continued')
exit 23

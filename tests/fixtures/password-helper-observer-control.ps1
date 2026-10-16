param(
    [ValidateSet('missing-healthy', 'missing-disposed', 'prefix-healthy', 'prefix-disposed', 'complete', 'complete-extra', 'delayed-entry', 'no-entry', 'entry-before-marker', 'entry-no-marker', 'marker-no-entry')]
    [string] $Control,
    [string] $Journal,
    [ValidatePattern('^case-[a-f0-9]{24}$')]
    [string] $CaseId
)

$ErrorActionPreference = 'Stop'
$FixtureObservationPath = $Journal
$HostEncoding = 'default'; $Scenario = 'unicode'; $CasePhase = 'primary'; $Mode = 'Native'; $FixtureControl = 'none'
$ObservationPath = ''; $fixtureObservationPresence = @{}; $fixtureObservationEncoding = $null
. (Join-Path $PSScriptRoot 'password-helper-fixture-observation.ps1')

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

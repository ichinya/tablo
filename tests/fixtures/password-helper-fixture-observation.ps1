# Dot-sourced only by the registered synthetic fixture. No credential/input values.
$fixtureObservationState = @{ bytes = 0; failed = $false; sequence = 0 }
function Write-FixtureObservation {
    param(
        [ValidateSet('fixture-entry', 'before-encoding-initialization', 'after-encoding-initialization', 'before-resolver-setup', 'after-resolver-setup', 'before-callback-setup', 'after-callback-setup', 'before-helper-call', 'after-helper-call')]
        [string] $Stage
    )
    if ($FixtureObservationPath -eq '' -or $fixtureObservationState.failed) { return }
    try {
        $fixtureRecord = @{
            scope = 'fixture'
            stage = $Stage
            sequence = $fixtureObservationState.sequence + 1
            utc = [DateTime]::UtcNow.ToString('o')
            monotonicTicks = [Diagnostics.Stopwatch]::GetTimestamp()
            monotonicFrequency = [Diagnostics.Stopwatch]::Frequency
            wrapperPid = $PID
            case = @{ id = $CaseId; encoding = $HostEncoding; scenario = $Scenario; phase = $CasePhase; mode = $Mode; control = $FixtureControl }
            powerShell = $PSVersionTable.PSVersion.ToString()
            dotNet = [Environment]::Version.ToString()
            hostName = $Host.Name
            parameterPresence = $fixtureObservationPresence
            encoding = $fixtureObservationEncoding
            nativeCallback = $(if ($ObservationPath -eq '') { 'not-configured' } else { 'configured; emission separate' })
        }
        $fixtureBytes = (New-Object Text.UTF8Encoding($false, $true)).GetBytes(($fixtureRecord | ConvertTo-Json -Depth 5 -Compress) + [Environment]::NewLine)
        if ($fixtureBytes.Length -gt 4096 -or $fixtureObservationState.bytes + $fixtureBytes.Length -gt 32768) { throw 'Fixture observation bound exceeded.' }
        $fixtureFile = $null
        try {
            $fixtureFileMode = $(if ($fixtureObservationState.bytes -eq 0) { [IO.FileMode]::CreateNew } else { [IO.FileMode]::Open })
            $fixtureFile = New-Object IO.FileStream($FixtureObservationPath, $fixtureFileMode, [IO.FileAccess]::Write, [IO.FileShare]::Read)
            if ($fixtureFile.Length -ne $fixtureObservationState.bytes) { throw 'Fixture observation append collision.' }
            $fixtureFile.Position = $fixtureFile.Length
            $fixtureFile.Write($fixtureBytes, 0, $fixtureBytes.Length)
            $fixtureFile.Flush()
        } finally { if ($null -ne $fixtureFile) { $fixtureFile.Dispose() } }
        $fixtureObservationState.bytes += $fixtureBytes.Length
        $fixtureObservationState.sequence++
    } catch {
        $fixtureObservationState.failed = $true
        # Fixed collector signal only; PHP reports FAIL after original settlement.
        try { [Console]::Error.WriteLine('HELPER_FIXTURE_OBSERVER_FAILURE=append-failed') }
        catch { } # Diagnostic channels may both fail; never replace the original outcome.
    }
}

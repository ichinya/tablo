param(
    [string] $Helper,
    [string] $Php,
    [ValidateSet('default', 'bom', 'bomless', 'cp866')]
    [string] $HostEncoding = 'default',
    [ValidateSet('unicode', 'min', 'max', 'short', 'long', 'startFailure', 'cancel', 'encodingFailure')]
    [string] $Scenario = 'unicode',
    [ValidateSet('Native', 'Docker', 'DockerStopped')]
    [string] $Mode = 'Native',
    [string] $ObservationPath = '',
    [string] $FixtureObservationPath = '',
    [ValidatePattern('^(case-[a-f0-9]{24})?$')]
    [string] $CaseId = '',
    [ValidateSet('primary', 'repeat', 'fixture-control')]
    [string] $CasePhase = 'primary',
    [ValidateSet('none', 'pre-helper-stall')]
    [string] $FixtureControl = 'none'
)

# Fixed body-entry evidence only; diagnostic failure must preserve the fixture outcome.
try { [Console]::Out.WriteLine('HELPER_FIXTURE_BODY=admitted') } catch { }

$ErrorActionPreference = 'Stop'
$fixtureObservationEncoding = $null
$fixtureObservationPresence = @{}
foreach ($fixtureParameter in @('Helper', 'Php', 'HostEncoding', 'Scenario', 'Mode', 'ObservationPath', 'FixtureObservationPath', 'CaseId', 'CasePhase', 'FixtureControl')) {
    $fixtureObservationPresence[$fixtureParameter] = $PSBoundParameters.ContainsKey($fixtureParameter)
}
. (Join-Path $PSScriptRoot 'password-helper-fixture-observation.ps1')
Write-FixtureObservation 'fixture-entry'
# Deliberately live, pre-helper synthetic control; only its new test uses a short deadline.
if ($FixtureControl -eq 'pre-helper-stall') {
    [Console]::Out.WriteLine('HELPER_FIXTURE_CONTROL=pre-helper-stall')
    while ($true) { Start-Sleep -Milliseconds 100 }
}
Write-FixtureObservation 'before-encoding-initialization'
if ($HostEncoding -eq 'bom') { [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($true, $true) }
if ($HostEncoding -eq 'bomless') { [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false, $true) }
if ($HostEncoding -eq 'cp866') { [Console]::InputEncoding = [Text.Encoding]::GetEncoding(866) }
$fixtureBefore = [Console]::InputEncoding
$fixtureObservationEncoding = @{ codePage = $fixtureBefore.CodePage; preambleBytes = $fixtureBefore.GetPreamble().Length }
Write-FixtureObservation 'after-encoding-initialization'
$fixtureState = @{ prompts = 0 }

# Only the protected prompt is substituted; the shipped helper and PHP CLI run.
# Generate synthetic input in this process, never in argv, env or public output.
function Read-Host {
    param([string] $Prompt, [switch] $AsSecureString)
    if (!$AsSecureString) { throw 'Protected prompt required.' }
    $fixtureState.prompts++
    if ($Scenario -eq 'cancel' -and $fixtureState.prompts -eq 2) { throw 'Synthetic prompt cancellation.' }
    $fixtureSecret = New-Object System.Security.SecureString
    $fixtureLength = switch ($Scenario) { 'min' { 12 } 'max' { 1024 } 'short' { 11 } 'long' { 1025 } default { 0 } }
    if ($fixtureLength -gt 0) {
        for ($fixtureIndex = 0; $fixtureIndex -lt $fixtureLength; $fixtureIndex++) { $fixtureSecret.AppendChar([char]120) }
    } elseif ($Scenario -eq 'encodingFailure') {
        $fixtureSecret.AppendChar([char]0xd800)
    } else {
        foreach ($fixtureCode in @(32,32,0x0436,0x4e2d,0xd83d,0xde42,45,117,110,105,99,111,100,101,45,32,32)) {
            $fixtureSecret.AppendChar([char]$fixtureCode)
        }
    }
    return $fixtureSecret
}

Write-FixtureObservation 'before-resolver-setup'
if ($Scenario -eq 'startFailure') {
    function Get-Command {
        param([string] $Name, [string] $CommandType)
        # A resolved application whose executable is absent exercises Process.Start.
        return [pscustomobject]@{ Source = (Join-Path (Split-Path -Parent $Helper) 'absent-fixture-executable.exe') }
    }
} elseif ($Mode -eq 'Native') {
    function Get-Command {
        param([string] $Name, [string] $CommandType)
        if ($Name -ne 'php' -or $CommandType -ne 'Application') { throw 'Unexpected executable resolution.' }
        return [pscustomobject]@{ Source = $Php }
    }
}
Write-FixtureObservation 'after-resolver-setup'

Write-FixtureObservation 'before-callback-setup'
if ($ObservationPath -ne '') {
    $fixtureObserverPath = $ObservationPath
    $fixtureObserver = {
        param([hashtable] $Record)
        $fixtureLine = ($Record | ConvertTo-Json -Depth 5 -Compress) + [Environment]::NewLine
        [IO.File]::AppendAllText($fixtureObserverPath, $fixtureLine, (New-Object Text.UTF8Encoding($false, $true)))
    }.GetNewClosure()
    Write-FixtureObservation 'after-callback-setup'
    Write-FixtureObservation 'before-helper-call'
    & $Helper -Mode $Mode -Observation $fixtureObserver
} else {
    Write-FixtureObservation 'after-callback-setup'
    Write-FixtureObservation 'before-helper-call'
    & $Helper -Mode $Mode
}
$fixtureExit = $LASTEXITCODE
Write-FixtureObservation 'after-helper-call'
$fixtureAfter = [Console]::InputEncoding
$fixtureMetadata = @{
    helperExit = $fixtureExit
    encodingRestored = $fixtureBefore.Equals($fixtureAfter)
    codePageRestored = ($fixtureBefore.CodePage -eq $fixtureAfter.CodePage)
    preambleRestored = ([BitConverter]::ToString($fixtureBefore.GetPreamble()) -eq [BitConverter]::ToString($fixtureAfter.GetPreamble()))
    beforeCodePage = $fixtureObservationEncoding.codePage
    beforePreambleBytes = $fixtureObservationEncoding.preambleBytes
    prompts = $fixtureState.prompts
    powerShell = $PSVersionTable.PSVersion.ToString()
    dotNet = [Environment]::Version.ToString()
    hasStandardInputEncoding = ($null -ne [Diagnostics.ProcessStartInfo].GetProperty('StandardInputEncoding'))
}
[Console]::Out.WriteLine('HELPER_METADATA=' + ($fixtureMetadata | ConvertTo-Json -Compress))
if ($ObservationPath -ne '') {
    try {
        & $fixtureObserver @{
            stage = 'fixture-preexit'
            utc = [DateTime]::UtcNow.ToString('o')
            monotonicTicks = [Diagnostics.Stopwatch]::GetTimestamp()
            monotonicFrequency = [Diagnostics.Stopwatch]::Frequency
            wrapperPid = $PID
            helperExit = $fixtureExit
            closure = 'UNKNOWN; fixture is still running'
        }
    } catch { [Console]::Error.WriteLine('HELPER_OBSERVER_FAILURE=' + $_.Exception.GetType().FullName) }
}
exit $fixtureExit

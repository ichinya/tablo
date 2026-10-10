param(
    [string] $Helper,
    [string] $Php,
    [ValidateSet('default', 'bom', 'bomless', 'cp866')]
    [string] $HostEncoding = 'default',
    [ValidateSet('unicode', 'min', 'max', 'short', 'long', 'startFailure', 'cancel', 'encodingFailure')]
    [string] $Scenario = 'unicode',
    [ValidateSet('Native', 'Docker', 'DockerStopped')]
    [string] $Mode = 'Native'
)

$ErrorActionPreference = 'Stop'
if ($HostEncoding -eq 'bom') { [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($true, $true) }
if ($HostEncoding -eq 'bomless') { [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false, $true) }
if ($HostEncoding -eq 'cp866') { [Console]::InputEncoding = [Text.Encoding]::GetEncoding(866) }
$fixtureBefore = [Console]::InputEncoding
$fixtureState = @{ prompts = 0 }

# Only the protected prompt is substituted; the shipped helper and PHP CLI run.
# Generate synthetic input in this process, never in argv, env or public output.
function Read-Host {
    param([string] $Prompt, [switch] $AsSecureString)
    if (!$AsSecureString) { throw 'Protected prompt required.' }
    $fixtureState.prompts++
    if ($Scenario -eq 'cancel' -and $fixtureState.prompts -eq 2) { throw 'Synthetic prompt cancellation.' }
    $fixtureSecret = New-Object System.Security.SecureString
    $fixtureLength = switch ($Scenario) { 'min' { 12 } 'max' { 72 } 'short' { 11 } 'long' { 73 } default { 0 } }
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

& $Helper -Mode $Mode
$fixtureExit = $LASTEXITCODE
$fixtureAfter = [Console]::InputEncoding
$fixtureMetadata = @{
    helperExit = $fixtureExit
    encodingRestored = $fixtureBefore.Equals($fixtureAfter)
    codePageRestored = ($fixtureBefore.CodePage -eq $fixtureAfter.CodePage)
    preambleRestored = ([BitConverter]::ToString($fixtureBefore.GetPreamble()) -eq [BitConverter]::ToString($fixtureAfter.GetPreamble()))
    beforeCodePage = $fixtureBefore.CodePage
    beforePreambleBytes = $fixtureBefore.GetPreamble().Length
    prompts = $fixtureState.prompts
    powerShell = $PSVersionTable.PSVersion.ToString()
    dotNet = [Environment]::Version.ToString()
    hasStandardInputEncoding = ($null -ne [Diagnostics.ProcessStartInfo].GetProperty('StandardInputEncoding'))
}
[Console]::Out.WriteLine('HELPER_METADATA=' + ($fixtureMetadata | ConvertTo-Json -Compress))
exit $fixtureExit

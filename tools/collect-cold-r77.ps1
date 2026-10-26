param(
    [Parameter(Mandatory)][string]$InputPath,
    [Parameter(Mandatory)][string]$OutputPath,
    [Parameter(Mandatory)][ValidatePattern('^[0-9]+$')][string]$RunId,
    [Parameter(Mandatory)][ValidatePattern('^[0-9a-f]{40}$')][string]$Head,
    [Parameter(Mandatory)][ValidatePattern('^[0-9a-f]{32}$')][string]$Nonce,
    [Parameter(Mandatory)][ValidateSet('mysql','mariadb')][string]$Engine,
    [Parameter(Mandatory)][ValidateSet('8.2','8.5')][string]$Php
)
$ErrorActionPreference = 'Stop'
# Temporary numeric-only diagnostic projection. Never an acceptance or closure receipt.
function Assert-RegularPath([string]$path) {
    $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or $item.LinkType) { throw 'Regular diagnostic file required' }
    $parent = $item.Directory
    while ($null -ne $parent) {
        if ($parent.LinkType) { throw 'Linked diagnostic ancestor refused' }
        $parent = $parent.Parent
    }
    return $item
}
$item = Assert-RegularPath $InputPath
if ($item.Length -lt 1 -or $item.Length -gt 16384) { throw 'Diagnostic size refused' }
$bytes = [IO.File]::ReadAllBytes($item.FullName)
if ($bytes.Length -ne $item.Length) { throw 'Diagnostic changed during read' }
$text = [Text.UTF8Encoding]::new($false, $true).GetString($bytes)
$value = $text | ConvertFrom-Json -AsHashtable -Depth 8
$booleans = @('instrumentedOnly','observerContaminated','armed','finalCapture','capturedPeakKnown','tailUnknown','depthOverflow')
$integers = @('baselinePhpBytes','peakPhpBytes','peakPhpWorkingBytes','winnerKind','winnerPhase','winnerFile','winnerPeakPhpBytes','winnerUsedPhpBytes','includeBeforeUsedPhpBytes','includeAfterUsedPhpBytes','includeBeforePeakPhpBytes','elapsedNs','factoryCheckpoint')
$keys = @('schema','mode','status','phases') + $booleans + $integers
if ($value -isnot [System.Collections.IDictionary] -or $value.Count -ne $keys.Count) { throw 'Diagnostic object shape refused' }
foreach ($key in $keys) { if (!$value.Contains($key)) { throw 'Diagnostic field missing' } }
if ($value.schema -isnot [string] -or $value.mode -isnot [string] -or $value.status -isnot [string]) { throw 'Scalar diagnostic vocabulary required' }
if ($value.schema -cne 'tablo/cold-r77/v1' -or $value.mode -cnotin @('observe','inactive') -or $value.status -cnotin @('captured','incomplete')) { throw 'Diagnostic vocabulary refused' }
foreach ($key in $booleans) { if ($value[$key] -isnot [bool]) { throw 'Diagnostic boolean refused' } }
if (!$value.instrumentedOnly -or !$value.observerContaminated) { throw 'Diagnostic scope refused' }
function Assert-FiniteInteger($number) {
    if (($number -isnot [long] -and $number -isnot [int]) -or $number -lt -1 -or $number -gt 9007199254740991) { throw 'Diagnostic integer refused' }
}
foreach ($key in $integers) { Assert-FiniteInteger $value[$key] }
if ($value.winnerKind -lt 0 -or $value.winnerKind -gt 3 -or $value.factoryCheckpoint -lt 0 -or $value.factoryCheckpoint -gt 8) { throw 'Diagnostic identifier refused' }
$phaseIds = @(10,11,20,30,40,41,51,52,53,54,55,56,57,58)
if ($value.phases -isnot [array] -or $value.phases.Count -ne 84) { throw 'Fixed diagnostic phase shape refused' }
for ($row = 0; $row -lt $phaseIds.Count; $row++) {
    if ($value.phases[$row * 6] -ne $phaseIds[$row]) { throw 'Diagnostic phase order refused' }
    for ($column = 0; $column -lt 6; $column++) { Assert-FiniteInteger $value.phases[$row * 6 + $column] }
}
if ($value.capturedPeakKnown) {
    if (!$value.finalCapture -or $value.status -cne 'captured' -or $value.baselinePhpBytes -lt 0 -or $value.peakPhpBytes -lt $value.baselinePhpBytes -or $value.peakPhpWorkingBytes -ne ($value.peakPhpBytes - $value.baselinePhpBytes)) { throw 'Captured diagnostic peak inconsistent' }
} elseif ($value.finalCapture -or $value.status -cne 'incomplete' -or $value.baselinePhpBytes -ne -1 -or $value.peakPhpBytes -ne -1 -or $value.peakPhpWorkingBytes -ne -1) { throw 'Unknown diagnostic peak inconsistent' }
$safe = [ordered]@{}
foreach ($key in $keys) { $safe[$key] = $value[$key] }
$result = [ordered]@{ schema='tablo/cold-r77-hosted/v1'; run=$RunId; head=$Head; nonce=$Nonce; engine=$Engine; php=$Php; issueAccepted=$false; sourceSha256=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant(); diagnostic=$safe }
$output = [IO.File]::Open($OutputPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
try { $encoded=[Text.UTF8Encoding]::new($false).GetBytes(($result | ConvertTo-Json -Depth 10)); $output.Write($encoded,0,$encoded.Length) }
finally { $output.Dispose() }

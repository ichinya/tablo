param(
    [Parameter(Mandatory)][string]$InputPath,
    [Parameter(Mandatory)][string]$OutputPath,
    [Parameter(Mandatory)][ValidatePattern('^[0-9]+$')][string]$RunId,
    [Parameter(Mandatory)][ValidatePattern('^[0-9a-f]{40}$')][string]$Head,
    [Parameter(Mandatory)][ValidatePattern('^[0-9a-f]{32}$')][string]$Nonce,
    [Parameter(Mandatory)][ValidateSet('mysql','mariadb')][string]$Engine,
    [Parameter(Mandatory)][ValidateSet('8.2','8.5')][string]$Php,
    [Parameter(Mandatory)][ValidateSet('observe','inactive')][string]$Mode
)
$ErrorActionPreference = 'Stop'
# Temporary numeric-only localization, never an original-test acceptance receipt.
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
if ($item.Length -lt 1 -or $item.Length -gt 2048) { throw 'Diagnostic size refused' }
$bytes = [IO.File]::ReadAllBytes($item.FullName)
if ($bytes.Length -ne $item.Length) { throw 'Diagnostic changed during read' }
$text = [Text.UTF8Encoding]::new($false, $true).GetString($bytes)
$booleans = @('instrumentedOnly','observerContaminated','issueAccepted','winnerPeakKnown','tailUnknown','broken')
$integers = @('mode','phpVersionId','engineId','baselinePhpBytes','peakPhpBytes','peakPhpWorkingBytes',
    'winnerPeakPhpBytes','winnerUsedPhpBytes','winnerPhase','winnerFactory')
$keys = @('schema') + $booleans + $integers
$document = [System.Text.Json.JsonDocument]::Parse($text)
try {
    if ($document.RootElement.ValueKind -ne [System.Text.Json.JsonValueKind]::Object) { throw 'Diagnostic object required' }
    $names = @($document.RootElement.EnumerateObject() | ForEach-Object Name)
    if ($names.Count -ne $keys.Count -or @($names | Select-Object -Unique).Count -ne $keys.Count) { throw 'Duplicate or missing diagnostic field' }
    foreach ($name in $names) { if ($name -cnotin $keys) { throw 'Unexpected diagnostic field' } }
} finally { $document.Dispose() }
$value = $text | ConvertFrom-Json -AsHashtable -Depth 4
if ($value.schema -isnot [string] -or $value.schema -cne 'tablo/cold-r81-coarse/v1') { throw 'Diagnostic vocabulary refused' }
foreach ($key in $booleans) { if ($value[$key] -isnot [bool]) { throw 'Diagnostic boolean refused' } }
foreach ($key in $integers) {
    $number = $value[$key]
    if (($number -isnot [long] -and $number -isnot [int]) -or $number -lt 0 -or $number -gt 268435456) { throw 'Diagnostic integer refused' }
}
if (!$value.instrumentedOnly -or !$value.observerContaminated -or $value.issueAccepted) { throw 'Diagnostic scope refused' }
if ($Mode -cnotin @('observe','inactive') -or $Engine -cnotin @('mysql','mariadb')) { throw 'Exact diagnostic binding required' }
$expectedMode = if ($Mode -ceq 'observe') { 1 } else { 0 }
$expectedEngine = if ($Engine -ceq 'mysql') { 1 } else { 2 }
$phpFloor = if ($Php -ceq '8.2') { 80200 } else { 80500 }
if ($value.mode -ne $expectedMode -or $value.engineId -ne $expectedEngine -or $value.phpVersionId -lt $phpFloor -or $value.phpVersionId -ge $phpFloor + 100) { throw 'Diagnostic cell binding refused' }
if ($value.peakPhpBytes -lt $value.baselinePhpBytes -or $value.peakPhpWorkingBytes -ne $value.peakPhpBytes - $value.baselinePhpBytes -or $value.winnerPeakPhpBytes -gt $value.peakPhpBytes -or $value.winnerUsedPhpBytes -gt $value.winnerPeakPhpBytes) { throw 'Diagnostic peak equation refused' }
if ($value.winnerPhase -notin @(0,10,11,20,30,40,41,51,52,53,54,55,56,57) -or $value.winnerFactory -gt 3) { throw 'Diagnostic interval refused' }
if ($value.tailUnknown -eq $value.winnerPeakKnown) { throw 'Diagnostic known/unknown union refused' }
if ($value.winnerPeakKnown -and ($value.mode -ne 1 -or $value.broken -or $value.winnerPeakPhpBytes -ne $value.peakPhpBytes)) { throw 'Diagnostic localization refused' }
if ($value.mode -eq 0 -and ($value.winnerPeakKnown -or $value.broken -or $value.winnerPeakPhpBytes -ne 0 -or $value.winnerUsedPhpBytes -ne 0 -or $value.winnerPhase -ne 0 -or $value.winnerFactory -ne 0)) { throw 'Inactive diagnostic state refused' }
$safe = [ordered]@{}
foreach ($key in $keys) { $safe[$key] = $value[$key] }
$result = [ordered]@{ schema='tablo/cold-r81-hosted/v1'; run=$RunId; head=$Head; nonce=$Nonce; engine=$Engine; php=$Php; issueAccepted=$false;
    sourceSha256=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant(); diagnostic=$safe }
$output = [IO.File]::Open($OutputPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
try { $encoded=[Text.UTF8Encoding]::new($false).GetBytes(($result | ConvertTo-Json -Depth 5)); $output.Write($encoded,0,$encoded.Length) }
finally { $output.Dispose() }

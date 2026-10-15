param(
    [Parameter(Mandatory=$true)][ValidateRange(1,65535)][int]$Port,
    [Parameter(Mandatory=$true)][string]$Journal
)
$ErrorActionPreference = 'Stop'
$portWatch = [System.Diagnostics.Stopwatch]::StartNew()
function Write-ObserverStage([string]$Stage) {
    $line = [pscustomobject]@{pid=$PID; port=$Port; stage=$Stage; elapsed_ms=$portWatch.Elapsed.TotalMilliseconds} | ConvertTo-Json -Compress
    [System.IO.File]::AppendAllText($Journal, $line + [Environment]::NewLine, [System.Text.UTF8Encoding]::new($false))
}
Write-ObserverStage 'entry'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$portObserver = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
$portState = 'unknown'; $portSocketError = $null; $portNativeError = $null
try {
    $portObserver.ExclusiveAddressUse = $true
    if ($portObserver.ExclusiveAddressUse -ne $true) { throw 'Exclusive observer unavailable' }
    Write-ObserverStage 'binding'
    try { $portObserver.Start(); $portState = 'available' }
    catch {
        $portCause = $_.Exception
        while ($null -ne $portCause -and $portCause -isnot [System.Net.Sockets.SocketException]) { $portCause = $portCause.InnerException }
        if ($portCause -isnot [System.Net.Sockets.SocketException]) { throw }
        $portSocketError = $portCause.SocketErrorCode.ToString(); $portNativeError = $portCause.NativeErrorCode
        if ($portSocketError -notin @('AddressAlreadyInUse','AccessDenied')) { throw }
        $portState = 'occupied'
    }
} finally { $portObserver.Stop(); Write-ObserverStage 'released'; $portWatch.Stop() }
$result = [pscustomobject]@{pid=$PID; port=$Port; exclusive=$true; state=$portState; socket_error=$portSocketError; native_error=$portNativeError; elapsed_ms=$portWatch.Elapsed.TotalMilliseconds} | ConvertTo-Json -Compress
[Console]::Out.WriteLine($result)
[Console]::Out.Flush()
Write-ObserverStage 'complete'

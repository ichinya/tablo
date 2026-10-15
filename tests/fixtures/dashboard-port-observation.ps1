param([Parameter(Mandatory=$true)][ValidateRange(1,65535)][int]$Port)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$portObserver = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
$portWatch = [System.Diagnostics.Stopwatch]::StartNew()
$portState = 'unknown'; $portSocketError = $null; $portNativeError = $null
try {
    $portObserver.ExclusiveAddressUse = $true
    if ($portObserver.ExclusiveAddressUse -ne $true) { throw 'Exclusive observer unavailable' }
    try { $portObserver.Start(); $portState = 'available' }
    catch {
        $portCause = $_.Exception
        while ($null -ne $portCause -and $portCause -isnot [System.Net.Sockets.SocketException]) { $portCause = $portCause.InnerException }
        if ($portCause -isnot [System.Net.Sockets.SocketException]) { throw }
        $portSocketError = $portCause.SocketErrorCode.ToString(); $portNativeError = $portCause.NativeErrorCode
        if ($portSocketError -notin @('AddressAlreadyInUse','AccessDenied')) { throw }
        $portState = 'occupied'
    }
} finally { $portObserver.Stop(); $portWatch.Stop() }
[pscustomobject]@{pid=$PID; port=$Port; exclusive=$true; state=$portState; socket_error=$portSocketError; native_error=$portNativeError; elapsed_ms=$portWatch.Elapsed.TotalMilliseconds} | ConvertTo-Json -Compress

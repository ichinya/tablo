param(
    [ValidateSet('Native', 'Docker', 'DockerStopped')]
    [string] $Mode = 'Native',
    [scriptblock] $Observation = $null
)

$ErrorActionPreference = 'Stop'
$resetExit = 1
$resetProcess = $null
$resetStarted = $false
$resetPassword = $null
$resetConfirmation = $null
$resetObservedIdentity = $null

# A caller must explicitly supply the synthetic observer. No secrets or arbitrary
# exception text enter it, and observation cannot interrupt real child settlement.
function Write-HelperObservation {
    param([string] $Stage, [switch] $Disposed)
    if ($null -eq $Observation -or $Mode -ne 'Native') { return }
    try {
        $resetObservedExit = $null
        $resetObservedHasExited = $null
        if ($resetStarted -and !$Disposed) {
            if ($null -eq $resetObservedIdentity) {
                $script:resetObservedIdentity = @{
                    pid = $resetProcess.Id
                    parentPid = $PID
                    startUtc = $resetProcess.StartTime.ToUniversalTime().ToString('o')
                    handle = $resetProcess.Handle.ToInt64()
                }
            }
            $resetObservedHasExited = $resetProcess.HasExited
            if ($resetObservedHasExited) { $resetObservedExit = $resetProcess.ExitCode }
        }
        & $Observation @{
            stage = $Stage
            utc = [DateTime]::UtcNow.ToString('o')
            monotonicTicks = [Diagnostics.Stopwatch]::GetTimestamp()
            monotonicFrequency = [Diagnostics.Stopwatch]::Frequency
            wrapperPid = $PID
            identity = $resetObservedIdentity
            executable = $resetStart.FileName
            cwd = $resetStart.WorkingDirectory
            arguments = 'bin/admin-password.php --password-stdin'
            hasExited = $resetObservedHasExited
            exitCode = $resetObservedExit
            disposed = [bool]$Disposed
        } | Out-Null
    } catch {
        [Console]::Error.WriteLine('HELPER_OBSERVER_FAILURE=' + $_.Exception.GetType().FullName)
    }
}

function Write-SecureLine {
    param([System.Security.SecureString] $Secret, [System.IO.Stream] $Stream)
    $resetBstr = [IntPtr]::Zero
    $resetChars = $null
    $resetBytes = $null
    try {
        $resetBstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secret)
        $resetChars = New-Object char[] ($Secret.Length + 1)
        for ($resetIndex = 0; $resetIndex -lt $Secret.Length; $resetIndex++) {
            $resetChars[$resetIndex] = [char]([System.Runtime.InteropServices.Marshal]::ReadInt16($resetBstr, 2 * $resetIndex) -band 0xffff)
        }
        $resetChars[$Secret.Length] = [char]10
        $resetEncoding = New-Object System.Text.UTF8Encoding($false, $true)
        $resetBytes = $resetEncoding.GetBytes($resetChars)
        $Stream.Write($resetBytes, 0, $resetBytes.Length)
    } finally {
        if ($null -ne $resetBytes) { [Array]::Clear($resetBytes, 0, $resetBytes.Length) }
        if ($null -ne $resetChars) { [Array]::Clear($resetChars, 0, $resetChars.Length) }
        if ($resetBstr -ne [IntPtr]::Zero) { [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($resetBstr) }
    }
}

try {
    $resetRoot = Split-Path -Parent $PSScriptRoot
    $resetStart = New-Object System.Diagnostics.ProcessStartInfo
    $resetStart.WorkingDirectory = $resetRoot
    $resetStart.UseShellExecute = $false
    $resetStart.CreateNoWindow = $true
    $resetStart.RedirectStandardInput = $true
    if ($Mode -eq 'Native') {
        $resetStart.FileName = @(Get-Command php -CommandType Application)[0].Source
        $resetStart.Arguments = 'bin/admin-password.php --password-stdin'
    } else {
        $resetStart.FileName = @(Get-Command docker -CommandType Application)[0].Source
        if ($Mode -eq 'Docker') {
            $resetStart.Arguments = 'compose exec -T --user www-data tablo php bin/admin-password.php --password-stdin'
        } else {
            $resetStart.Arguments = 'compose run --rm --no-deps -T --user www-data --entrypoint php tablo bin/admin-password.php --password-stdin'
        }
    }
    Write-HelperObservation 'before-protected-prompts'
    $resetPassword = Read-Host 'New password' -AsSecureString
    $resetConfirmation = Read-Host 'Confirm password' -AsSecureString
    $resetProcess = New-Object System.Diagnostics.Process
    $resetProcess.StartInfo = $resetStart
    # .NET Framework creates the redirected writer from Console.InputEncoding.
    # Disable its preamble before Start, even when writing directly to BaseStream.
    $resetInputEncoding = [Console]::InputEncoding
    try {
        [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false, $true)
        Write-HelperObservation 'before-start'
        if (!$resetProcess.Start()) { throw 'Cannot start local command.' }
        $resetStarted = $true
        Write-HelperObservation 'after-start'
        $resetInput = $resetProcess.StandardInput
    } finally { [Console]::InputEncoding = $resetInputEncoding }
    Write-HelperObservation 'before-input-write'
    Write-SecureLine $resetPassword $resetInput.BaseStream
    Write-SecureLine $resetConfirmation $resetInput.BaseStream
    Write-HelperObservation 'after-input-write'
    Write-HelperObservation 'before-input-flush'
    $resetInput.BaseStream.Flush()
    Write-HelperObservation 'after-input-flush'
    Write-HelperObservation 'before-input-close'
    $resetInput.Close()
    Write-HelperObservation 'after-input-close'
    Write-HelperObservation 'before-wait'
    $resetProcess.WaitForExit()
    Write-HelperObservation 'after-wait'
    $resetExit = $resetProcess.ExitCode
    Write-HelperObservation 'exit-code-read'
} catch {
    Write-HelperObservation 'interrupted'
    [Console]::Error.WriteLine('Local password command interrupted or unavailable. Verify login before retrying.')
} finally {
    if ($null -ne $resetProcess) {
        try {
            if ($resetStarted -and !$resetProcess.HasExited) {
                Write-HelperObservation 'before-cleanup-input-close'
                $resetProcess.StandardInput.Close()
                Write-HelperObservation 'after-cleanup-input-close'
                Write-HelperObservation 'before-cleanup-wait'
                if (!$resetProcess.WaitForExit(2000)) {
                    Write-HelperObservation 'before-cleanup-kill'
                    $resetProcess.Kill()
                    Write-HelperObservation 'after-cleanup-kill'
                    $resetProcess.WaitForExit()
                }
                Write-HelperObservation 'after-cleanup-wait'
            }
        } finally {
            Write-HelperObservation 'before-dispose'
            $resetProcess.Dispose()
            Write-HelperObservation 'after-dispose' -Disposed
        }
    }
    if ($null -ne $resetPassword) { $resetPassword.Dispose() }
    if ($null -ne $resetConfirmation) { $resetConfirmation.Dispose() }
}
Write-HelperObservation 'helper-preexit' -Disposed
exit $resetExit

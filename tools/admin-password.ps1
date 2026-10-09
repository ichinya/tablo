param(
    [ValidateSet('Native', 'Docker', 'DockerStopped')]
    [string] $Mode = 'Native'
)

$ErrorActionPreference = 'Stop'
$resetExit = 1
$resetProcess = $null
$resetStarted = $false
$resetPassword = $null
$resetConfirmation = $null

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
    $resetPassword = Read-Host 'New password' -AsSecureString
    $resetConfirmation = Read-Host 'Confirm password' -AsSecureString
    $resetProcess = New-Object System.Diagnostics.Process
    $resetProcess.StartInfo = $resetStart
    if (!$resetProcess.Start()) { throw 'Cannot start local command.' }
    $resetStarted = $true
    Write-SecureLine $resetPassword $resetProcess.StandardInput.BaseStream
    Write-SecureLine $resetConfirmation $resetProcess.StandardInput.BaseStream
    $resetProcess.StandardInput.BaseStream.Flush()
    $resetProcess.StandardInput.Close()
    $resetProcess.WaitForExit()
    $resetExit = $resetProcess.ExitCode
} catch {
    [Console]::Error.WriteLine('Local password command interrupted or unavailable. Verify login before retrying.')
} finally {
    if ($null -ne $resetProcess) {
        try {
            if ($resetStarted -and !$resetProcess.HasExited) {
                $resetProcess.StandardInput.Close()
                if (!$resetProcess.WaitForExit(2000)) {
                    $resetProcess.Kill()
                    $resetProcess.WaitForExit()
                }
            }
        } finally { $resetProcess.Dispose() }
    }
    if ($null -ne $resetPassword) { $resetPassword.Dispose() }
    if ($null -ne $resetConfirmation) { $resetConfirmation.Dispose() }
}
exit $resetExit

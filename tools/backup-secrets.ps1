<#
.SYNOPSIS
  Backs up the files git does NOT keep (config.local.php and admin-panel/manual-payments/)
  into a password-protected, AES-256 encrypted 7-Zip archive.

.DESCRIPTION
  - Asks for the password twice (hidden while typing, never saved), then test-opens the archive.
  - File names inside the archive are encrypted too (-mhe=on), so nobody can even see what's in it.
  - The archive goes OUTSIDE the git repo (default: ..\Backup\secrets next to SourceCode), named
    faucet-secrets-YYYY-MM-DD_HHmm.7z, so it can never be committed by accident.
  - This script contains no secrets and is safe to keep in git. Do NOT upload the tools folder to the website.

  Keep the password in your password manager. Without it the archive cannot be opened.

.PARAMETER Destination
  Folder for the archive. Default: <project>\Backup\secrets

.PARAMETER Keep
  Keep only the newest N archives in the destination (older ones are deleted). 0 = keep all (default).

.EXAMPLE
  .\tools\backup-secrets.ps1

.EXAMPLE
  .\tools\backup-secrets.ps1 -Destination "E:\USB-Backup" -Keep 10

.NOTES
  Restore: open the .7z in 7-Zip, enter the password, copy config.local.php to the SourceCode folder
  and the manual-payments folder into SourceCode\admin-panel\.
  If PowerShell refuses to run scripts, run it once like this:
    powershell -ExecutionPolicy Bypass -File .\tools\backup-secrets.ps1
#>
[CmdletBinding()]
param(
    [string]$Destination,
    [int]$Keep = 0
)

$ErrorActionPreference = 'Stop'

# --- Paths -------------------------------------------------------------------------------------
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path        # ...\SourceCode
if (-not $Destination) {
    $Destination = Join-Path (Split-Path $repoRoot -Parent) 'Backup\secrets'   # ...\thesatoshifaucet.com\Backup\secrets
}

# What to back up (relative to SourceCode). Add more lines here if you add more git-ignored secrets.
$items = @(
    'config.local.php',
    'admin-panel\manual-payments'
)

# --- Find 7-Zip --------------------------------------------------------------------------------
$sevenZip = $null
foreach ($p in @("$env:ProgramFiles\7-Zip\7z.exe", "${env:ProgramFiles(x86)}\7-Zip\7z.exe")) {
    if ($p -and (Test-Path $p)) { $sevenZip = $p; break }
}
if (-not $sevenZip) {
    $cmd = Get-Command 7z.exe -ErrorAction SilentlyContinue
    if ($cmd) { $sevenZip = $cmd.Source }
}
if (-not $sevenZip) {
    Write-Host "7-Zip not found. Install it from https://www.7-zip.org/ and run this again." -ForegroundColor Red
    exit 1
}

# --- Check the items exist ---------------------------------------------------------------------
$present = @()
foreach ($i in $items) {
    if (Test-Path (Join-Path $repoRoot $i)) { $present += $i }
    else { Write-Host "Warning: not found, skipping: $i" -ForegroundColor Yellow }
}
if ($present.Count -eq 0) {
    Write-Host "Nothing to back up." -ForegroundColor Red
    exit 1
}

# Never write the archive inside the repo (it could get committed or uploaded).
$destFull = [System.IO.Path]::GetFullPath($Destination)
if ($destFull.TrimEnd('\').StartsWith($repoRoot.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or
    $destFull.TrimEnd('\') -ieq $repoRoot.TrimEnd('\')) {
    Write-Host "Destination is inside the source folder. Pick a folder outside SourceCode." -ForegroundColor Red
    exit 1
}
New-Item -ItemType Directory -Force -Path $destFull | Out-Null

$stamp   = Get-Date -Format 'yyyy-MM-dd_HHmm'
$archive = Join-Path $destFull "faucet-secrets-$stamp.7z"

Write-Host ""
Write-Host "Backing up:" -ForegroundColor Cyan
$present | ForEach-Object { Write-Host "  $_" }
Write-Host "To: $archive" -ForegroundColor Cyan
Write-Host ""

# --- Password (asked twice so a typo can't lock you out; never saved) -------------------------
function Read-PlainPassword([string]$prompt) {
    $sec  = Read-Host -Prompt $prompt -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}
$pw = Read-PlainPassword 'Archive password'
if ($pw.Length -lt 8) {
    Write-Host "Use at least 8 characters. Nothing was saved." -ForegroundColor Red
    exit 1
}
if ($pw -ne (Read-PlainPassword 'Type it again')) {
    Write-Host "The two passwords don't match. Nothing was saved." -ForegroundColor Red
    exit 1
}
if ($pw -match '"') {
    Write-Host 'Please use a password without the " character. Nothing was saved.' -ForegroundColor Red
    exit 1
}

# --- Create the archive ------------------------------------------------------------------------
# a = add, -t7z = 7z format, -mx=9 = max compression, -mhe=on = encrypt file names,
# -xr! = skip logs and editor junk, -bso0/-bsp0 = quiet.
Push-Location $repoRoot
try {
    $args7z = @('a', '-t7z', '-mx=9', '-mhe=on', "-p$pw", '-bso0', '-bsp0',
                '-xr!*.log', '-xr!*.tmp', '-xr!*~', $archive) + $present
    & $sevenZip @args7z
    $code = $LASTEXITCODE

    # Prove it opens with that password before calling it a success.
    if ($code -eq 0) {
        & $sevenZip t "-p$pw" '-bso0' '-bsp0' $archive
        $code = $LASTEXITCODE
    }
} finally {
    Pop-Location
    $pw = $null
}

if ($code -ne 0 -or -not (Test-Path $archive)) {
    Write-Host ""
    Write-Host "Backup FAILED (7-Zip exit code $code). Nothing was saved." -ForegroundColor Red
    if (Test-Path $archive) { Remove-Item $archive -Force -Confirm:$false }
    exit 1
}

$sizeKb = [math]::Round((Get-Item $archive).Length / 1KB, 1)
Write-Host ""
Write-Host "Backup OK: $archive ($sizeKb KB)" -ForegroundColor Green

# --- Optional: keep only the newest N archives ------------------------------------------------
if ($Keep -gt 0) {
    $old = Get-ChildItem -Path $destFull -Filter 'faucet-secrets-*.7z' |
           Sort-Object LastWriteTime -Descending |
           Select-Object -Skip $Keep
    foreach ($f in $old) {
        Remove-Item $f.FullName -Force -Confirm:$false
        Write-Host "Removed old backup: $($f.Name)"
    }
}

Write-Host "Tip: also copy it somewhere off this laptop (USB stick, another cloud) now and then." -ForegroundColor DarkGray

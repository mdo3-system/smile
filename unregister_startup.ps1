# ==============================================================================
# Building CAD/PDF Parser Service Unregister
# ==============================================================================
# 1. Remove from registry
try {
    Remove-ItemProperty -Path "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run" -Name "CAD_Parser_AutoStart" -ErrorAction SilentlyContinue
    Write-Host "  [OK] Removed auto-start from Windows registry." -ForegroundColor Green
} catch {}

# 2. Remove shortcut if present
$startupDir = [Environment]::GetFolderPath('Startup')
$targetLnk = Join-Path $startupDir "CAD_Parser_AutoStart.lnk"
if (Test-Path $targetLnk) {
    Remove-Item $targetLnk -Force -ErrorAction SilentlyContinue
    Write-Host "  [OK] Removed shortcut from Startup folder." -ForegroundColor Green
}

# 3. Stop running process
$conn = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
if ($conn) {
    Stop-Process -Id $conn.OwningProcess -Force -ErrorAction SilentlyContinue
    Write-Host "  [OK] Stopped running service process." -ForegroundColor Green
} else {
    Write-Host "  [INFO] Service is already stopped." -ForegroundColor Gray
}

# ==============================================================================
# Building CAD/PDF Parser Service Stopper
# ==============================================================================
$conn = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
if ($conn) {
    $pidToKill = $conn.OwningProcess
    Write-Host "  Found running process (PID: $pidToKill)" -ForegroundColor Cyan
    Stop-Process -Id $pidToKill -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
    Write-Host "  [OK] Service stopped successfully." -ForegroundColor Green
} else {
    Write-Host "  [INFO] Service is already stopped." -ForegroundColor Yellow
}

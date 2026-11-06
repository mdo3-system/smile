
# ==============================================================================
# Building CAD/PDF Parser Service Stopper
# ==============================================================================
$conn = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
if ($conn) {
    $pidToKill = $conn.OwningProcess
    Write-Host "  稼働中のプロセスを検出しました (PID: $pidToKill)" -ForegroundColor Cyan
    Stop-Process -Id $pidToKill -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
    Write-Host "  [OK] 図書整合性解析サービスを安全に停止しました。" -ForegroundColor Green
} else {
    Write-Host "  [INFO] サービスは現在起動していません (停止中)。" -ForegroundColor Yellow
}

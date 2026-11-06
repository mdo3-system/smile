# ==============================================================================
# Building CAD/PDF Parser Service Status Checker
# ==============================================================================
$conn = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
if ($conn) {
    Write-Host "  [RUNNING] Service is running in the background." -ForegroundColor Green
    Write-Host "  Port: 5005 (PID: $($conn.OwningProcess))" -ForegroundColor Cyan
    try {
        $res = Invoke-RestMethod -Uri "http://127.0.0.1:5005/api/health" -TimeoutSec 3
        Write-Host "  --------------------------------------------------" -ForegroundColor Gray
        Write-Host "  Status       : $($res.status)" -ForegroundColor White
        if ($res.gemini_available) {
            Write-Host "  Gemini API   : Enabled ($($res.gemini_model_preferred))" -ForegroundColor Green
        } else {
            Write-Host "  Gemini API   : Not configured" -ForegroundColor Yellow
        }
        Write-Host "  JWW Parser   : $($res.jww_native_parser)" -ForegroundColor White
        Write-Host "  DXF/PDF Tool : OK" -ForegroundColor White
        Write-Host "  --------------------------------------------------" -ForegroundColor Gray
        Write-Host "  * Auto-starts on PC reboot. No console window needed." -ForegroundColor Yellow
    } catch {
        Write-Host "  [WARNING] Failed to query /api/health endpoint." -ForegroundColor Yellow
    }
} else {
    Write-Host "  [STOPPED] Service is currently NOT running." -ForegroundColor Red
    Write-Host "  Run register_startup.bat to enable auto-start and launch now." -ForegroundColor Gray
}

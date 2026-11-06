# ==============================================================================
# Building CAD/PDF Parser Service Startup Registrar
# Registers auto-start on Windows logon (HKCU Run registry) and launches immediately.
# ==============================================================================
$ErrorActionPreference = 'SilentlyContinue'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $dir) { $dir = (Get-Location).Path }
Set-Location $dir

Write-Host "====================================================" -ForegroundColor Cyan
Write-Host " Building CAD/PDF Parser Auto Service Setup" -ForegroundColor Cyan
Write-Host " Auto-start on Windows logon (Background Service)" -ForegroundColor Cyan
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host ""

# 1. Check Python dependencies
Write-Host "[1/3] Checking Python dependencies..." -ForegroundColor Gray
py -3 -m pip install flask flask-cors ezdxf pypdf pdfplumber pywin32 google-generativeai *>$null
if ($LASTEXITCODE -ne 0) {
    python -m pip install flask flask-cors ezdxf pypdf pdfplumber pywin32 google-generativeai *>$null
}
Write-Host "      -> Complete (flask, flask-cors, ezdxf, pypdf, pywin32, google-generativeai)" -ForegroundColor Green

# 2. Register auto-start in HKCU Run registry
Write-Host ""
Write-Host "[2/3] Registering auto-start on Windows logon..." -ForegroundColor Gray
$ps1Path = Join-Path $dir "run_cad_parser_background.ps1"
$regCmd = "powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$ps1Path`""

try {
    Set-ItemProperty -Path "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run" -Name "CAD_Parser_AutoStart" -Value $regCmd
    Write-Host "      -> SUCCESS: Auto-start registered in Windows." -ForegroundColor Green
    Write-Host "         Registry: HKCU\Software\Microsoft\Windows\CurrentVersion\Run\CAD_Parser_AutoStart" -ForegroundColor DarkGray
    Write-Host "         (Also visible in Task Manager -> Startup Apps)" -ForegroundColor DarkGray
} catch {
    Write-Host "      -> [WARNING] Registry registration failed: $_" -ForegroundColor Yellow
}

# 3. Launch background service immediately
Write-Host ""
Write-Host "[3/3] Launching parser service in background (Port 5005)..." -ForegroundColor Gray
& powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File "$ps1Path"

# Wait for port to listen (up to 6 seconds)
$started = $false
for ($i = 0; $i -lt 6; $i++) {
    Start-Sleep -Seconds 1
    $conn = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
    if ($conn) {
        $started = $true
        break
    }
}

Write-Host ""
Write-Host "----------------------------------------------------" -ForegroundColor Gray
if ($started) {
    Write-Host "  [OK] Service is now running in the background!" -ForegroundColor Green
    Write-Host "  Active URL: http://127.0.0.1:5005" -ForegroundColor Cyan
    Write-Host "  Process ID (PID): $($conn.OwningProcess)" -ForegroundColor White

    try {
        $res = Invoke-RestMethod -Uri "http://127.0.0.1:5005/api/health" -TimeoutSec 3
        if ($res.gemini_available) {
            Write-Host "  Gemini API : Enabled ($($res.gemini_model_preferred))" -ForegroundColor Green
        } else {
            Write-Host "  Gemini API : Not configured (Optional)" -ForegroundColor Yellow
        }
        Write-Host "  JWW Parser : $($res.jww_native_parser)" -ForegroundColor White
    } catch {}

    Write-Host ""
    Write-Host "  * Automatically starts on PC reboot (completely hidden, no console window)." -ForegroundColor Yellow
    Write-Host "  * You can now use the CAD/PDF comparison feature in your web portal anytime." -ForegroundColor White
} else {
    Write-Host "  [INFO] Service startup command executed." -ForegroundColor Yellow
    Write-Host "  It will be ready to connect in a few seconds." -ForegroundColor Gray
}
Write-Host "----------------------------------------------------" -ForegroundColor Gray
Write-Host ""

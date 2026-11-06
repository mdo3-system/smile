
# ==============================================================================
# Building CAD/PDF Parser Service Startup Registrar
# Registers auto-start on Windows logon (HKCU Run registry) and launches immediately.
# ==============================================================================
$ErrorActionPreference = 'SilentlyContinue'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $dir) { $dir = (Get-Location).Path }
Set-Location $dir

Write-Host "====================================================" -ForegroundColor Cyan
Write-Host " 図書整合性解析サービス (CAD/JWW/PDF)" -ForegroundColor Cyan
Write-Host " PC起動時バックグラウンド自動常駐セットアップ" -ForegroundColor Cyan
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host ""

# 1. Python依存ライブラリの確認
Write-Host "[1/3] Python依存ライブラリを確認中..." -ForegroundColor Gray
py -3 -m pip install flask flask-cors ezdxf pypdf pdfplumber pywin32 google-generativeai *>$null
if ($LASTEXITCODE -ne 0) {
    python -m pip install flask flask-cors ezdxf pypdf pdfplumber pywin32 google-generativeai *>$null
}
Write-Host "      -> 完了 (flask, flask-cors, ezdxf, pypdf, pywin32, google-generativeai)" -ForegroundColor Green

# 2. Windows ログオン時の自動起動登録 (HKCU Run レジストリ)
Write-Host ""
Write-Host "[2/3] Windowsログオン時の自動起動を登録中..." -ForegroundColor Gray
$ps1Path = Join-Path $dir "run_cad_parser_background.ps1"
$regCmd = "powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$ps1Path`""

try {
    Set-ItemProperty -Path "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run" -Name "CAD_Parser_AutoStart" -Value $regCmd
    Write-Host "      -> 登録成功: Windowsログオン時の自動起動を設定しました。" -ForegroundColor Green
    Write-Host "         キー: HKCU\Software\Microsoft\Windows\CurrentVersion\Run\CAD_Parser_AutoStart" -ForegroundColor DarkGray
    Write-Host "         （タスクマネージャーの『スタートアップ アプリ』にも表示されます）" -ForegroundColor DarkGray
} catch {
    Write-Host "      -> [警告] レジストリ登録時にエラーが発生しました: $_" -ForegroundColor Yellow
}

# 3. 直ちにバックグラウンド起動
Write-Host ""
Write-Host "[3/3] 解析サービスをバックグラウンドで起動中 (ポート 5005)..." -ForegroundColor Gray
& powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File "$ps1Path"

# 起動待ち (最大6秒)
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
    Write-Host "  [OK] サービスは正常にバックグラウンドで稼働を開始しました！" -ForegroundColor Green
    Write-Host "  稼働URL: http://127.0.0.1:5005" -ForegroundColor Cyan
    Write-Host "  プロセスID (PID): $($conn.OwningProcess)" -ForegroundColor White
    
    try {
        $res = Invoke-RestMethod -Uri "http://127.0.0.1:5005/api/health" -TimeoutSec 3
        Write-Host "  Gemini API : $(if ($res.gemini_available) { '利用可能 (有効 - ' + $res.gemini_model_preferred + ')' } else { '未設定' })" -ForegroundColor $(if ($res.gemini_available) { 'Green' } else { 'Yellow' })
        Write-Host "  JWW解析    : $($res.jww_native_parser)" -ForegroundColor White
    } catch {}

    Write-Host ""
    Write-Host "  ★ PC再起動後も自動で裏で起動・常駐します（黒い画面は出ません）。" -ForegroundColor Yellow
    Write-Host "  ★ ポータル画面（図書整合性確認コンポーネント）をいつでもご利用いただけます。" -ForegroundColor White
} else {
    Write-Host "  [確認] 起動処理を実行しました。数秒後にブラウザから接続可能になります。" -ForegroundColor Yellow
}
Write-Host "----------------------------------------------------" -ForegroundColor Gray
Write-Host ""

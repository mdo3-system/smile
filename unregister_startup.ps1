
# ==============================================================================
# Building CAD/PDF Parser Service Unregister
# ==============================================================================
# 1. レジストリからの解除
try {
    Remove-ItemProperty -Path "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run" -Name "CAD_Parser_AutoStart" -ErrorAction SilentlyContinue
    Write-Host "  [OK] レジストリから自動起動設定を削除しました。" -ForegroundColor Green
} catch {}

# 2. スタートアップフォルダからの解除 (念のため)
$startupDir = [Environment]::GetFolderPath('Startup')
$targetLnk = Join-Path $startupDir "CAD_Parser_AutoStart.lnk"
if (Test-Path $targetLnk) {
    Remove-Item $targetLnk -Force -ErrorAction SilentlyContinue
    Write-Host "  [OK] スタートアップフォルダからショートカットを削除しました。" -ForegroundColor Green
}

# 3. プロセス停止
$conn = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
if ($conn) {
    Stop-Process -Id $conn.OwningProcess -Force -ErrorAction SilentlyContinue
    Write-Host "  [OK] 稼働中のサービスを停止しました。" -ForegroundColor Green
} else {
    Write-Host "  [INFO] サービスは既に停止しています。" -ForegroundColor Gray
}

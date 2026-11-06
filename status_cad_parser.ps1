
# ==============================================================================
# Building CAD/PDF Parser Service Status Checker
# ==============================================================================
$conn = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
if ($conn) {
    Write-Host "  [稼働中] サービスはバックグラウンドで正常に動作しています。" -ForegroundColor Green
    Write-Host "  ポート: 5005 (PID: $($conn.OwningProcess))" -ForegroundColor Cyan
    try {
        $res = Invoke-RestMethod -Uri "http://127.0.0.1:5005/api/health" -TimeoutSec 3
        Write-Host "  --------------------------------------------------" -ForegroundColor Gray
        Write-Host "  APIステータス : $($res.status)" -ForegroundColor White
        Write-Host "  Gemini API    : $(if ($res.gemini_available) { '利用可能 (有効)' } else { '未設定' })" -ForegroundColor $(if ($res.gemini_available) { 'Green' } else { 'Yellow' })
        Write-Host "  利用モデル    : $($res.gemini_model_preferred)" -ForegroundColor Cyan
        Write-Host "  JWWパーサー   : $($res.jww_native_parser)" -ForegroundColor White
        Write-Host "  DXF/PDF機能   : 有効" -ForegroundColor White
        Write-Host "  --------------------------------------------------" -ForegroundColor Gray
        Write-Host "  ※ PC再起動後も自動で常駐します。黒い画面を開く必要はありません。" -ForegroundColor Yellow
    } catch {
        Write-Host "  [警告] ヘルスチェックAPIへの応答取得中にエラーが発生しました。" -ForegroundColor Yellow
    }
} else {
    Write-Host "  [停止中] サービスは現在起動していません。" -ForegroundColor Red
    Write-Host "  起動するには register_startup.bat または run_cad_parser_background.vbs を実行してください。" -ForegroundColor Gray
}

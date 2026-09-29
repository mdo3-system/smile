@echo off
chcp 65001 > nul
cd /d "%~dp0"
title 建築図書 自動照合ローカルサービス (Port: 5005)

echo ====================================================
echo  建築図書 (CAD/JWW/PDF) 自動照合サービスを起動しています...
echo  URL: http://localhost:5005 / http://127.0.0.1:5005
echo  ブラウザ側の管理者ダッシュボードと自動連携します。
echo  終了する場合はこのウィンドウを閉じるか Ctrl+C を押してください。
echo ====================================================

py -3 "%~dp0local_cad_parser.py"
if errorlevel 1 (
    echo.
    echo [INFO] py コマンドでの起動を試行中...
    python "%~dp0local_cad_parser.py"
)

if errorlevel 1 (
    echo.
    echo [ERROR] 起動に失敗しました。Pythonがインストールされているか確認してください。
    pause
)

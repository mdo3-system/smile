@echo off
chcp 65001 > nul
title 建築図書 自動照合ローカルサービス (Port: 5005)
echo ====================================================
echo  建築図書 (CAD/JWW/PDF) 自動照合サービスを起動しています...
echo  ブラウザ側の管理者ダッシュボードと自動連携します。
echo  終了する場合はこのウィンドウを閉じるか Ctrl+C を押してください。
echo ====================================================

py local_cad_parser.py
if errorlevel 1 (
    echo.
    echo [ERROR] 起動に失敗しました。Pythonがインストールされているか確認してください。
    pause
)

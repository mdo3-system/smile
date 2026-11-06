@echo off
chcp 65001 > nul
title 図書整合性解析サービス - 稼働ステータス確認

echo ====================================================
echo  図書整合性解析サービス - 稼働状態確認
echo ====================================================
echo.

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0status_cad_parser.ps1"

echo.
pause

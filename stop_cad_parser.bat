@echo off
chcp 65001 > nul
title 図書整合性解析サービス - 停止

echo ====================================================
echo  図書整合性解析サービス (ポート 5005) の停止
echo ====================================================
echo.

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop_cad_parser.ps1"

echo.
pause

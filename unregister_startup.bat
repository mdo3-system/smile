@echo off
chcp 65001 > nul
title 図書整合性解析サービス - 自動常駐の解除

echo ====================================================
echo  図書整合性解析サービス - 自動常駐の解除
echo ====================================================
echo.

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0unregister_startup.ps1"

echo.
pause

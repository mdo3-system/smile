@echo off
cd /d "%~dp0"
title 図書整合性解析サービス - PC起動時自動常駐セットアップ
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0register_startup.ps1"
pause

@echo off
cd /d "%~dp0"
title CAD Parser Service - Unregister Auto-Start
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0unregister_startup.ps1"
pause

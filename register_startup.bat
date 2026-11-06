@echo off
cd /d "%~dp0"
title CAD Parser Service - Windows Auto-Start Setup
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0register_startup.ps1"
pause

@echo off
cd /d "%~dp0"
title CAD Parser Service - Stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop_cad_parser.ps1"
pause

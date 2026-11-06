@echo off
cd /d "%~dp0"
title CAD Parser Service - Status Check
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0status_cad_parser.ps1"
pause

@echo off
cd /d "%~dp0"
title Building CAD/PDF Parser Service (Port 5005)

echo ====================================================
echo  Building Drawing (CAD/JWW/PDF) Auto Parser Service
echo ====================================================
echo.
echo [1/2] Checking and installing required Python packages...

py -3 -m pip install flask flask-cors ezdxf pypdf pdfplumber > nul 2>&1
if errorlevel 1 (
    python -m pip install flask flask-cors ezdxf pypdf pdfplumber
)

echo.
echo [2/2] Starting server at http://127.0.0.1:5005 ...
echo ----------------------------------------------------
echo  Active URL: http://127.0.0.1:5005
echo  Keep this window OPEN while using the web dashboard.
echo  To exit, close this window or press Ctrl+C.
echo ----------------------------------------------------
echo.

py -3 "%~dp0local_cad_parser.py"
if errorlevel 1 (
    python "%~dp0local_cad_parser.py"
)

echo.
echo ====================================================
echo [ERROR] Server stopped. Check error messages above.
echo ====================================================
pause

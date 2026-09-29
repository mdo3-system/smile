@echo off
chcp 65001 > nul
cd /d "%~dp0"
title 建築図書 自動照合ローカルサービス (Port: 5005)

echo ====================================================
echo  建築図書 (CAD/JWW/PDF) 自動照合サービス
echo ====================================================
echo.
echo [1/2] 必要なPythonライブラリを確認・インストールしています...

py -3 -m pip install flask flask-cors ezdxf pypdf pdfplumber > nul 2>&1
if errorlevel 1 (
    python -m pip install flask flask-cors ezdxf pypdf pdfplumber
)

echo [2/2] サービスを起動しています (Port: 5005)...
echo.
echo ----------------------------------------------------
echo  稼働URL: http://127.0.0.1:5005
echo  ※この黒いウィンドウを開いたまま、ブラウザで照合を行ってください。
echo  ※終了する場合はウィンドウを閉じるか Ctrl+C を押してください。
echo ----------------------------------------------------
echo.

py -3 "%~dp0local_cad_parser.py"
if errorlevel 1 (
    python "%~dp0local_cad_parser.py"
)

echo.
echo ====================================================
echo [エラー] サービスが停止しました。
echo 上記のエラーメッセージをご確認ください。
echo ====================================================
pause

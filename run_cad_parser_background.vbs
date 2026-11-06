' ==============================================================================
' Building CAD/PDF Parser Background Service Runner
' PCログオン時またはスタートアップ時に、コンソールウィンドウを一切出さずに
' local_cad_parser.py を完全バックグラウンド（常駐）で起動します。
' ==============================================================================
Option Explicit

Dim fso, shell, currentDir, cmd

Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

currentDir = fso.GetParentFolderName(WScript.ScriptFullName)

' PowerShellを完全非表示（WindowStyle Hidden, vbHide = 0）で起動
cmd = "powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File """ & currentDir & "\run_cad_parser_background.ps1"""
shell.Run cmd, 0, False

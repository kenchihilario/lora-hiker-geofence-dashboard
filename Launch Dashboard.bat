@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0server-control.ps1" -Action start -ProjectRoot "%~dp0."
if errorlevel 1 pause

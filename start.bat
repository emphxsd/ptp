@echo off
rem Запуск сервиса в Docker (подробности — в start.ps1 и README.md)
chcp 65001 >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start.ps1"

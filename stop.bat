@echo off
rem Остановка контейнеров; база и документы остаются в томах Docker
chcp 65001 >nul
cd /d "%~dp0"
docker compose --profile llm stop
echo Остановлено. Данные сохранены — следующий запуск: start.bat
pause

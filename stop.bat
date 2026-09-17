@echo off
rem Макросыч — остановка программы, запущенной «Макросыч.exe» или start.bat.

chcp 65001 >nul
setlocal

set "PORT=8787"
set "STOPPED="

rem --- Сервер: процесс php.exe, который слушает порт приложения ---
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":%PORT%" ^| findstr "LISTENING"') do (
    taskkill /PID %%p /T /F >nul 2>&1
    set "STOPPED=1"
)

rem --- Значок в области уведомлений, если программа запущена через Макросыч.exe ---
taskkill /IM "Макросыч.exe" /F >nul 2>&1

if defined STOPPED (
    echo Макросыч остановлен.
) else (
    echo Макросыч не запущен.
)

echo.
pause
endlocal

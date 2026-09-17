@echo off
rem Макросыч — запуск с консольным окном.
rem
rem Нужен там, где нельзя запускать «Макросыч.exe»: в компаниях, где политика
rem безопасности запрещает неподписанные программы.
rem
rem Окно сворачивается в панель задач само — закрывать его не нужно, но закрытие
rem останавливает программу. Остановить можно и через stop.bat.

chcp 65001 >nul

if not "%~1"=="min" (
    start "" /min "%~f0" min
    exit /b
)

title Макросыч
cd /d "%~dp0"

set "PORT=8787"

rem --- Уже запущено? Тогда просто открываем интерфейс ---
netstat -ano | findstr ":%PORT%" | findstr "LISTENING" >nul 2>&1
if not errorlevel 1 (
    start "" "http://127.0.0.1:%PORT%/"
    exit /b
)

rem --- Поиск PHP: сначала портативная сборка внутри программы, затем OSPanel ---
set "PHP=%~dp0runtime\php\php.exe"
if not exist "%PHP%" set "PHP=C:\OSPanel\modules\PHP-8.3\php.exe"

if not exist "%PHP%" (
    echo.
    echo Не найден PHP.
    echo Положите переносимую сборку в: runtime\php\php.exe
    echo Скачать: https://windows.php.net/download/  ^(VS16 x64 Non Thread Safe^)
    echo.
    pause
    exit /b 1
)

rem --- Сертификаты для https: путь от каталога программы ---
set "CURL_CA_BUNDLE=%~dp0runtime\php\extras\ssl\cacert.pem"

set "VER="
if exist "%~dp0VERSION" set /p VER=<"%~dp0VERSION"

echo.
echo   Макросыч %VER% — локальный сервер
echo   Адрес:  http://127.0.0.1:%PORT%/
echo   PHP:    %PHP%
echo   Данные: %~dp0data
echo.
echo   Окно можно свернуть — программа продолжит работать.
echo   Остановка: stop.bat.
echo.

start "" "http://127.0.0.1:%PORT%/"

rem Настройки PHP лежат рядом с php.exe (runtime\php\php.ini) и подхватываются сами
"%PHP%" -S 127.0.0.1:%PORT% -t "%~dp0app\Web" "%~dp0app\Web\router.php"

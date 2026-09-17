@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0.."

rem Сборка лаунчера Макросыча встроенным в Windows компилятором C#.
rem Ничего устанавливать не нужно: csc.exe входит в состав .NET Framework.

set "CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if not exist "%CSC%" set "CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe"
if not exist "%CSC%" (
    echo Не найден компилятор C# из состава Windows: %CSC%
    echo Установите .NET Framework 4 или соберите лаунчер на другой машине.
    pause
    exit /b 1
)

rem Значок лежит рядом и в сборке не нуждается; пересобрать — launcher\make_icon.php
if not exist "launcher\makrosych.ico" (
    if exist "runtime\php\php.exe" runtime\php\php.exe launcher\make_icon.php
)

echo Сборка лаунчера...
"%CSC%" /nologo /target:winexe /platform:anycpu /optimize+ ^
    /out:"Макросыч.exe" ^
    /win32icon:"launcher\makrosych.ico" ^
    /reference:System.dll ^
    /reference:System.Drawing.dll ^
    /reference:System.Windows.Forms.dll ^
    "launcher\Makrosych.cs"

if errorlevel 1 (
    echo.
    echo Сборка не удалась.
    pause
    exit /b 1
)

echo.
echo Готово: Макросыч.exe

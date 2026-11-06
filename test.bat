@echo off
setlocal
cd /d "%~dp0"

set "PHP=%~dp0.tools\php\php.exe"
if not exist "%PHP%" (
    echo [ERROR] Portable PHP not found: .tools\php\php.exe
    exit /b 1
)

"%PHP%" artisan test

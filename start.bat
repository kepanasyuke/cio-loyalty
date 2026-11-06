@echo off
setlocal
cd /d "%~dp0"

set "PHP=%~dp0.tools\php\php.exe"
if not exist "%PHP%" (
    echo [ERROR] Portable PHP not found: .tools\php\php.exe
    echo Download it again or install PHP 8.3+ globally.
    exit /b 1
)

if not exist "%~dp0.env" (
    echo [INFO] .env not found, copying from .env.example
    copy /y "%~dp0.env.example" "%~dp0.env" >nul
    "%PHP%" artisan key:generate
)

if not exist "%~dp0database\database.sqlite" (
    echo [INFO] Creating SQLite database
    type nul > "%~dp0database\database.sqlite"
    "%PHP%" artisan migrate --seed --force
)

echo [INFO] Starting server on http://127.0.0.1:8000
start "" http://127.0.0.1:8000/admin/login

pushd "%~dp0public"
"%PHP%" -S 127.0.0.1:8000 "..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
popd

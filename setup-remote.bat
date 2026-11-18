@echo off
REM ETEC remote deployment script for Windows hosting
REM Run this after uploading to your Windows hosting account
REM Usage: setup-remote.bat

setlocal enabledelayedexpansion

echo.
echo 🚀 ETEC Remote Setup (Windows)
echo ==============================
echo.

if not exist ".env" (
    echo ❌ .env not found. Please cp .env.example .env and fill in your hosting DB credentials.
    pause
    exit /b 1
)

echo 1️⃣  Installing Composer dependencies...
php composer.phar install --no-dev --optimize-autoloader
if errorlevel 1 goto error
echo ✅ Composer done
echo.

echo 2️⃣  Generating app key...
php artisan key:generate
if errorlevel 1 goto error
echo ✅ App key generated
echo.

echo 3️⃣  Caching configuration...
php artisan config:cache
php artisan route:cache
if errorlevel 1 goto error
echo ✅ Config cached
echo.

echo 4️⃣  Running database migrations...
php artisan migrate --seed
if errorlevel 1 goto error
echo ✅ Migrations complete
echo.

echo 🎉 ETEC is ready!
echo.
echo Default accounts:
echo   Super Admin: superadmin@etec.lk / password
echo   Admin: admin@etec.lk / password
echo   Student: student@etec.lk / password
echo   (Change all passwords immediately!)
echo.
pause
goto :eof

:error
echo ❌ Setup failed. Check the error above.
pause
exit /b 1

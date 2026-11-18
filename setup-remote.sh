#!/bin/bash
# ETEC remote deployment script
# Run this on your hosting server after git clone/upload
# Usage: bash setup-remote.sh

set -e

echo "🚀 ETEC Remote Setup"
echo "===================="

if [ ! -f ".env" ]; then
    echo "❌ .env not found. Please cp .env.example .env and fill in your hosting DB credentials."
    exit 1
fi

echo "1️⃣  Installing Composer dependencies..."
php composer.phar install --no-dev --optimize-autoloader || composer install --no-dev --optimize-autoloader
echo "✅ Composer done"

echo "2️⃣  Generating app key..."
php artisan key:generate
echo "✅ App key generated"

echo "3️⃣  Caching configuration..."
php artisan config:cache
php artisan route:cache
echo "✅ Config cached"

echo "4️⃣  Running database migrations..."
php artisan migrate --seed
echo "✅ Migrations complete"

echo "5️⃣  Setting file permissions..."
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage
echo "✅ Permissions set"

echo ""
echo "🎉 ETEC is ready!"
echo "Default accounts:"
echo "  Super Admin: superadmin@etec.lk / password"
echo "  Admin: admin@etec.lk / password"
echo "  Student: student@etec.lk / password"
echo "  (Change all passwords immediately!)"

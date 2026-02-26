#!/bin/bash
set -e

echo "Firefly III custom entrypoint"
echo "Running as '$(whoami)'"

cd "$FIREFLY_III_PATH"

echo "Waiting for database connection..."
php artisan firefly-iii:verify-database-connection

echo "Running migrations and upgrades..."
php artisan firefly-iii:upgrade-database --force

echo "Clearing caches..."
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:clear

echo "Firefly III is ready."

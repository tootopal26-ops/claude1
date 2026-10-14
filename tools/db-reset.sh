#!/usr/bin/env bash
# Dev helper: drop + recreate the dev database, migrate and seed. NEVER run against production.
set -euo pipefail
cd "$(dirname "$0")/.."
DB="${1:-smb_dev}"
case "$DB" in smb_dev|smb_test) ;; *) echo "refusing to reset '$DB' (only smb_dev / smb_test)"; exit 1;; esac
mariadb -uroot -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
DB_NAME="$DB" php bin/migrate.php
DB_NAME="$DB" php bin/seed.php >/dev/null
echo "reset $DB ok"

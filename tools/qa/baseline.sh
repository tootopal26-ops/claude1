#!/usr/bin/env bash
# QA helper: put the LOCAL dev database back into a known state (fresh schema, seeded catalogue, owner admin,
# mock gateway + SMM provider, ~75 days of demo data, a second admin "qa-admin"). NEVER run against production.
set -euo pipefail
cd "$(dirname "$0")/../.."
bash tools/db-reset.sh smb_dev >/dev/null
php bin/seed.php --admin-username=owner --admin-email=owner@example.test --admin-password='Sm8!Qx-Valley#2026' >/dev/null
php tools/dev-setup.php demo >/dev/null
php tools/qa/qa-admin-account.php >/dev/null
rm -rf public/uploads/branding public/uploads/platforms public/uploads/services storage/cache/* 2>/dev/null || true
echo "baseline ready"

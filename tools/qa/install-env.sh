#!/usr/bin/env bash
# QA helper: prepare (start|stop) a pristine copy of the project in /tmp/qa/inst served on :8090 with an EMPTY database,
# to test the web installer exactly like a fresh cPanel upload (no .env, no lock file, no tables).
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=/tmp/qa/inst
case "${1:-start}" in
  start)
    bash "$0" stop >/dev/null 2>&1 || true
    mariadb -uroot -e "DROP DATABASE IF EXISTS smb_install_test; CREATE DATABASE smb_install_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON smb_install_test.* TO 'smb'@'localhost'; GRANT ALL PRIVILEGES ON smb_install_test.* TO 'smb'@'127.0.0.1'; FLUSH PRIVILEGES;"
    rm -rf "$ROOT"; mkdir -p "$ROOT"
    rsync -a --exclude node_modules --exclude .git --exclude .env --exclude 'storage/logs/*' --exclude 'storage/cache/*' --exclude 'storage/*.lock' --exclude 'storage/install-token.txt' --exclude 'public/uploads/*/' ./ "$ROOT/"
    mkdir -p "$ROOT/storage/logs" "$ROOT/storage/cache" "$ROOT/public/uploads"
    ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 nohup php -S 127.0.0.1:8090 -t public tools/router.php >/tmp/qa/inst-server.log 2>&1 & echo $! >/tmp/qa/inst.pid )
    sleep 1; echo "install env ready on http://127.0.0.1:8090"
    ;;
  stop)
    pkill -f 'php -S 127.0.0.1:8090' 2>/dev/null || true   # parent + all workers
    rm -f /tmp/qa/inst.pid; sleep 0.4
    ;;
esac

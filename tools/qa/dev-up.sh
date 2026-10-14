#!/usr/bin/env bash
# QA helper (local dev only): make sure everything the QA suites need is running — MariaDB, the PHP dev server on :8080 and the two
# mock servers (payment gateway :4010, SMM panel :4020). Safe to run repeatedly.
set -u
cd "$(dirname "$0")/../.."
up() { curl -s -o /dev/null --max-time 2 "$1"; }

if ! mysqladmin ping >/dev/null 2>&1; then
  rm -f /run/mysqld/mysqld.sock /run/mysqld/mysqld.pid 2>/dev/null; mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null
  (nohup mariadbd-safe --user=mysql >/tmp/mariadb-safe.log 2>&1 &)
  for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
fi
mysqladmin ping >/dev/null 2>&1 && echo "mariadb  : up" || { echo "mariadb  : FAILED (see /tmp/mariadb-safe.log)"; exit 1; }

mkdir -p /tmp/smb-logs
up http://127.0.0.1:8080/health || (PHP_CLI_SERVER_WORKERS=6 nohup php -S 127.0.0.1:8080 -t public tools/router.php >/tmp/smb-logs/php.log 2>&1 &)
up http://127.0.0.1:4010/__state || (nohup node tools/mock-alpha-upi/server.js >/tmp/smb-logs/alpha.log 2>&1 &)
up http://127.0.0.1:4020/__state || (nohup node tools/mock-smm/server.js >/tmp/smb-logs/smm.log 2>&1 &)
sleep 1.5
for u in "site     :http://127.0.0.1:8080/health" "gateway  :http://127.0.0.1:4010/__state" "smm panel:http://127.0.0.1:4020/__state"; do
  name="${u%%:http*}"; url="http${u#*:http}"
  up "$url" && echo "$name: up" || { echo "$name: FAILED ($url)"; exit 1; }
done

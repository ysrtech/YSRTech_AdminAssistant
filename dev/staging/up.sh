#!/usr/bin/env bash
# Bring an existing staging install back after the container restarts:
# start MariaDB on its kept data and serve the core again. Builds nothing.
set -euo pipefail
MODULE_DIR=$(cd "$(dirname "$0")/../.." && pwd)
CORE_DIR=${OPENMAGE_CORE_DIR:-"$(dirname "$MODULE_DIR")/openmage-core"}
EC_DIR=${EMAILCAMPAIGNS_DIR:-"$(dirname "$MODULE_DIR")/YSRTech_EmailCampaigns"}
PORT=${PORT:-8080}
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
mysqladmin ping >/dev/null 2>&1 || { mariadbd-safe >/tmp/mysqld.log 2>&1 & }
for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
if ! pgrep -f "php -S 127.0.0.1:$PORT" >/dev/null; then
    (cd "$CORE_DIR" && php -S "127.0.0.1:$PORT" -t "$CORE_DIR" "$EC_DIR/dev/staging/router.php" >/tmp/staging-server.log 2>&1 &)
fi
sleep 1
echo "staging: http://127.0.0.1:$PORT/index.php/admin ($(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/index.php/admin"))"

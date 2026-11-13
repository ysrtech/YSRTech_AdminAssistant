#!/usr/bin/env bash
#
# A throwaway OpenMage with this module (and Email Campaigns) installed,
# seeded with orders worth asking about, the assistant switched on, the
# report query set up with a read-only database user, and the scripted
# stand-in for the AI provider. Nothing is sent to any provider.
#
#   ./dev/staging/setup.sh
#
# Builds on Email Campaigns' staging setup (sibling checkout expected, or set
# EMAILCAMPAIGNS_DIR), which installs MariaDB, clones OpenMage, installs it
# and serves it on 127.0.0.1:${PORT:-8080}.
set -euo pipefail
MODULE_DIR=$(cd "$(dirname "$0")/../.." && pwd)
CORE_DIR=${OPENMAGE_CORE_DIR:-"$(dirname "$MODULE_DIR")/openmage-core"}
EC_DIR=${EMAILCAMPAIGNS_DIR:-"$(dirname "$MODULE_DIR")/YSRTech_EmailCampaigns"}
export OPENMAGE_CORE_DIR="$CORE_DIR"

if [ ! -f "$CORE_DIR/app/etc/local.xml" ]; then
    [ -x "$EC_DIR/dev/staging/setup.sh" ] || { echo "Email Campaigns checkout not found at $EC_DIR"; exit 1; }
    "$EC_DIR/dev/staging/setup.sh"
    (cd "$CORE_DIR" && php "$EC_DIR/dev/staging/seed-catalog.php" && php "$EC_DIR/dev/staging/seed-volume.php")
fi

"$MODULE_DIR/dev/staging/link.sh" --standin

echo "==> Seeding orders for the assistant"
(cd "$CORE_DIR" && php "$MODULE_DIR/dev/staging/seed-assistant.php")

echo "==> Switching the assistant on, with a read-only report user"
DB_NAME=$(grep -o '<dbname><!\[CDATA\[[^]]*' "$CORE_DIR/app/etc/local.xml" | sed 's/.*CDATA\[//')
RO_PASS="ro-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
(cd "$CORE_DIR" && php -r "
require 'app/Mage.php'; Mage::app('admin');
\$sql = Mage::getModel('ysrtech_adminassistant/report_query')->grantStatements('store_ai_readonly', 'localhost');
echo str_replace('choose-a-long-password', '$RO_PASS', \$sql);
") > /tmp/ysraa-grants.sql
mysql -e "DROP USER IF EXISTS 'store_ai_readonly'@'localhost';"
mysql "$DB_NAME" < /tmp/ysraa-grants.sql
rm -f /tmp/ysraa-grants.sql
(cd "$CORE_DIR" && php -r "
require 'app/Mage.php'; Mage::app('admin');
\$c = Mage::getModel('core/config');
\$c->saveConfig('ysrtech_adminassistant/general/enabled', 1);
\$c->saveConfig('ysrtech_adminassistant/report/enabled', 1);
\$c->saveConfig('ysrtech_adminassistant/report/username', 'store_ai_readonly');
\$c->saveConfig('ysrtech_adminassistant/report/password', Mage::helper('core')->encrypt('$RO_PASS'));
Mage::app()->getCacheInstance()->flush();
")

echo
echo "Admin       : http://127.0.0.1:${PORT:-8080}/index.php/admin  (admin / StagingAdmin12345)"
echo "Audit log   : http://127.0.0.1:${PORT:-8080}/index.php/admin/ysrtech_assistantaudit/index"
echo "Settings    : http://127.0.0.1:${PORT:-8080}/index.php/admin/system_config/edit/section/ysrtech_adminassistant"
echo "The AI provider is the scripted stand-in. To use a real key instead: ./dev/staging/link.sh (without --standin),"
echo "then enter the key under the settings above."

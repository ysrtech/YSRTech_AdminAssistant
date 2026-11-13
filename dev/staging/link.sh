#!/usr/bin/env bash
# Link this module (and Email Campaigns, when it is a sibling checkout) into
# the staging OpenMage core, one module at a time, and clear the cache.
#
#   ./dev/staging/link.sh            # the module
#   ./dev/staging/link.sh --standin  # plus the scripted stand-in for the AI
#                                    # provider (staging only; see standin/)
#
# Email Campaigns' own setup links app/code/community/YSRTech as a single
# directory link into its checkout; that is replaced here by a real
# directory holding one link per module, so both modules load.
set -euo pipefail
MODULE_DIR=$(cd "$(dirname "$0")/../.." && pwd)
CORE_DIR=${OPENMAGE_CORE_DIR:-"$(dirname "$MODULE_DIR")/openmage-core"}
EC_DIR=${EMAILCAMPAIGNS_DIR:-"$(dirname "$MODULE_DIR")/YSRTech_EmailCampaigns"}

[ -f "$CORE_DIR/app/Mage.php" ] || { echo "No OpenMage core at $CORE_DIR"; exit 1; }

as_dir() { # replace a directory symlink with a real directory
    if [ -L "$1" ]; then rm "$1"; fi
    mkdir -p "$1"
}

as_dir "$CORE_DIR/app/code/community/YSRTech"
ln -sfn "$MODULE_DIR/app/code/community/YSRTech/AdminAssistant" "$CORE_DIR/app/code/community/YSRTech/AdminAssistant"
ln -sfn "$MODULE_DIR/app/etc/modules/YSRTech_AdminAssistant.xml" "$CORE_DIR/app/etc/modules/YSRTech_AdminAssistant.xml"

as_dir "$CORE_DIR/skin/adminhtml/default/default/ysrtech"
ln -sfn "$MODULE_DIR/skin/adminhtml/default/default/ysrtech/adminassistant" "$CORE_DIR/skin/adminhtml/default/default/ysrtech/adminassistant"

if [ -d "$EC_DIR/app/code/community/YSRTech/EmailCampaigns" ]; then
    ln -sfn "$EC_DIR/app/code/community/YSRTech/EmailCampaigns" "$CORE_DIR/app/code/community/YSRTech/EmailCampaigns"
    ln -sfn "$EC_DIR/skin/adminhtml/default/default/ysrtech/emailcampaigns" "$CORE_DIR/skin/adminhtml/default/default/ysrtech/emailcampaigns"
fi

find "$CORE_DIR/app/design" -type l -lname "$MODULE_DIR/*" -delete
while IFS= read -r design_file; do
    rel=${design_file#"$MODULE_DIR/app/design/"}
    mkdir -p "$(dirname "$CORE_DIR/app/design/$rel")"
    ln -sfn "$design_file" "$CORE_DIR/app/design/$rel"
done < <(find "$MODULE_DIR/app/design" -type f)

STANDIN="$MODULE_DIR/dev/staging/standin"
if [ "${1:-}" = "--standin" ]; then
    mkdir -p "$CORE_DIR/app/code/local/YSRTech"
    ln -sfn "$STANDIN/app/code/local/YSRTech/AdminAssistantStandin" "$CORE_DIR/app/code/local/YSRTech/AdminAssistantStandin"
    ln -sfn "$STANDIN/app/etc/modules/YSRTech_AdminAssistantStandin.xml" "$CORE_DIR/app/etc/modules/YSRTech_AdminAssistantStandin.xml"
    echo "stand-in AI provider linked (staging only)"
else
    rm -f "$CORE_DIR/app/etc/modules/YSRTech_AdminAssistantStandin.xml" "$CORE_DIR/app/code/local/YSRTech/AdminAssistantStandin"
fi

rm -rf "$CORE_DIR/var/cache"
(cd "$CORE_DIR" && php -r "require 'app/Mage.php'; Mage::app('admin'); Mage_Core_Model_Resource_Setup::applyAllUpdates();")
echo "linked; cache cleared; install scripts applied"

#!/usr/bin/env bash
# Builds two zips from this folder into dist/:
#   wp-connections.zip                 GitHub build (attach to each GitHub release; includes the GitHub updater)
#   wp-connections-wordpressorg.zip    WordPress.org build (no GitHub updater, no "install from zip URL" tool)
# Usage: bash bin/build.sh
set -euo pipefail
cd "$(dirname "$0")/.."
VER=$(grep -m1 "^ \* Version:" wp-connections.php | awk '{print $3}')
STABLE=$(grep -m1 "^Stable tag:" readme.txt | awk '{print $3}')
DEF=$(grep -m1 "define( 'WPC_VERSION'" wp-connections.php | sed -E "s/.*'([0-9][^']*)'.*/\1/")
if [ "$VER" != "$STABLE" ] || [ "$VER" != "$DEF" ]; then
  echo "Version mismatch: header $VER, WPC_VERSION $DEF, readme Stable tag $STABLE" >&2; exit 1
fi
TMP=$(mktemp -d)
mkdir -p dist "$TMP/wp-connections"
rsync -a --exclude dist --exclude bin --exclude '.git*' --exclude '.DS_Store' --exclude '*.zip' --exclude 'assets/icons/README.md' --exclude README.md --exclude CHANGELOG.md ./ "$TMP/wp-connections/"
( cd "$TMP" && zip -qr "$TMP/github.zip" wp-connections )
cp "$TMP/github.zip" dist/wp-connections.zip
rm "$TMP/wp-connections/includes/class-wpc-updater.php"
( cd "$TMP" && zip -qr "$TMP/wporg.zip" wp-connections )
cp "$TMP/wporg.zip" dist/wp-connections-wordpressorg.zip
rm -rf "$TMP"
echo "Built $VER:"; ls -l dist/

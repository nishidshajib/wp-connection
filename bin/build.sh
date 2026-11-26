#!/usr/bin/env bash
# Builds two zips from this folder into dist/:
#   wp-connection.zip                 GitHub build (attach to each GitHub release; includes the GitHub updater)
#   wp-connection-wordpressorg.zip    WordPress.org build (no GitHub updater, no "install from zip URL" tool)
# Usage: bash bin/build.sh
set -euo pipefail
cd "$(dirname "$0")/.."
VER=$(grep -m1 "^ \* Version:" wp-connection.php | awk '{print $3}')
STABLE=$(grep -m1 "^Stable tag:" readme.txt | awk '{print $3}')
DEF=$(grep -m1 "define( 'WPC_VERSION'" wp-connection.php | sed -E "s/.*'([0-9][^']*)'.*/\1/")
if [ "$VER" != "$STABLE" ] || [ "$VER" != "$DEF" ]; then
  echo "Version mismatch: header $VER, WPC_VERSION $DEF, readme Stable tag $STABLE" >&2; exit 1
fi
TMP=$(mktemp -d)
mkdir -p dist "$TMP/wp-connection"
rsync -a --exclude dist --exclude bin --exclude '.git*' --exclude '.DS_Store' --exclude '*.zip' --exclude 'assets/icons/README.md' --exclude README.md --exclude CHANGELOG.md ./ "$TMP/wp-connection/"
( cd "$TMP" && zip -qr "$TMP/github.zip" wp-connection )
cp "$TMP/github.zip" dist/wp-connection.zip
rm "$TMP/wp-connection/includes/class-wpc-updater.php"
rm -f "$TMP/wp-connection/assets/icons/"*.svg  # Brand logos are not GPL: keep them out of the WordPress.org build.
( cd "$TMP" && zip -qr "$TMP/wporg.zip" wp-connection )
cp "$TMP/wporg.zip" dist/wp-connection-wordpressorg.zip
rm -rf "$TMP"
echo "Built $VER:"; ls -l dist/

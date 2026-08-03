#!/usr/bin/env bash
# Backtest the Reports pipeline against SureCart's real shipped model code.
#
# Downloads SureCart from WordPress.org on first run (the ~120 MB extracted
# source is gitignored), then runs every scenario in backtest.php: real
# Order/Product models, real RequestService URL building and auth, with only
# the wp_remote_request boundary stubbed to fixture JSON.
#
# The version is PINNED so the run is reproducible: the assertions encode
# 4.6.2's exact model behavior (Collection without __isset, stdClass nested
# envelopes, %5B%5D query rewriting), and an unpinned download would silently
# start verifying whatever WordPress.org publishes next. Override deliberately
# to probe a newer release for upstream changes:
#
#   SURECART_VERSION=4.7.0 tests/backtest/run.sh   # test a specific version
#   SURECART_VERSION=latest tests/backtest/run.sh  # probe current trunk
#
# Usage:  tests/backtest/run.sh
set -euo pipefail
cd "$(dirname "$0")"

PINNED_VERSION="4.6.2"
VERSION="${SURECART_VERSION:-$PINNED_VERSION}"

if [ "$VERSION" = "latest" ]; then
	ZIP_URL="https://downloads.wordpress.org/plugin/surecart.zip"
else
	ZIP_URL="https://downloads.wordpress.org/plugin/surecart.${VERSION}.zip"
fi

have_version=""
if [ -f surecart-src/surecart/surecart.php ]; then
	have_version="$(grep -m1 -oP 'Version:\s*\K[0-9.]+' surecart-src/surecart/surecart.php || true)"
fi

if [ -z "$have_version" ] || { [ "$VERSION" != "latest" ] && [ "$have_version" != "$VERSION" ]; }; then
	echo "Fetching SureCart ${VERSION} from WordPress.org..."
	rm -rf surecart-src surecart.zip
	curl -sSL -o surecart.zip "$ZIP_URL"
	mkdir -p surecart-src
	unzip -q -o surecart.zip -d surecart-src
	rm -f surecart.zip
fi

got_version="$(grep -m1 -oP 'Version:\s*\K[0-9.]+' surecart-src/surecart/surecart.php)"
echo "SureCart source version: ${got_version}"

# Fail loudly if the tree doesn't match the requested pin — passing against
# an unverified version is exactly what pinning exists to prevent.
if [ "$VERSION" != "latest" ] && [ "$got_version" != "$VERSION" ]; then
	echo "ERROR: requested SureCart ${VERSION} but the downloaded source is ${got_version}." >&2
	exit 1
fi
if [ "$got_version" != "$PINNED_VERSION" ]; then
	echo "NOTE: running against ${got_version}, not the pinned ${PINNED_VERSION} the assertions were written for."
fi

exec php backtest.php

#!/usr/bin/env bash
# Backtest the Reports pipeline against SureCart's real shipped model code.
#
# Downloads the current SureCart plugin from WordPress.org on first run (the
# ~120 MB extracted source is gitignored), then runs every scenario in
# backtest.php: real Order/Product models, real RequestService URL building
# and auth, with only the wp_remote_request boundary stubbed to fixture JSON.
#
# Usage:  tests/backtest/run.sh
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -d surecart-src/surecart ]; then
	echo "Fetching SureCart from WordPress.org (first run only)..."
	curl -sSL -o surecart.zip "https://downloads.wordpress.org/plugin/surecart.zip"
	mkdir -p surecart-src
	unzip -q -o surecart.zip -d surecart-src
	rm -f surecart.zip
	grep -m1 "Version:" surecart-src/surecart/surecart.php || true
fi

exec php backtest.php

#!/usr/bin/env bash
# Release check: builds the release zip, installs it on a fresh WordPress the
# way a user would, sets up the end-to-end test site and runs a full scan:
#
#   tests/release/run.sh
#
# The WordPress runs at http://localhost:8081, apart from the development
# one. Each run starts it afresh, and it stays up for a look. Remove it with:
#
#   docker compose -f tests/release/docker-compose.yml down -v

set -euo pipefail
cd "$(dirname "$0")/../.."

if [ ! -f vendor/autoload.php ]; then
	echo "Run composer install first: the test site's setup copies files from vendor/." >&2
	exit 1
fi

bin/build-release

compose() {
	docker compose -f tests/release/docker-compose.yml --progress quiet "$@"
}

wp() {
	compose run --rm -T cli wp "$@"
}

compose down -v
compose up -d --wait
until compose exec -T wordpress test -f wp-config.php; do
	sleep 1
done

wp core install --url=http://localhost:8081 --title="Upgrade Radar release check" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp plugin install /release/upgrade-radar.zip --activate --force
wp eval-file /repo/tests/e2e/setup.php

UPGRADE_RADAR_E2E_URL=http://localhost:8081 php tests/e2e/scan.php full

echo "The release zip passed a full scan."

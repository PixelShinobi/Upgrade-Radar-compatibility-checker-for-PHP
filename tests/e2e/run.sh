#!/usr/bin/env bash
# End-to-end test in the Docker WordPress from docker-compose.yml, where exec()
# and friends are disabled and requests stop after 30 seconds. Sets the site up
# with 35 plugins and a child theme (tests/e2e/setup.php), then runs scans
# through the admin page's own endpoints (tests/e2e/scan.php):
#
#   full        scan for PHP 8.4 with AJAX, check every verdict and the CSV
#   rival       another plugin loads PHP_CodeSniffer: the scan explains and pauses
#   resume      resume once that plugin is deactivated
#   cancel      cancel while a batch runs and start again: the batch mustn't
#               save over the new scan
#   background  the page closes after one batch, and WP-Cron finishes the scan
#
# Usage, after composer install (takes about ten minutes):
#
#   tests/e2e/run.sh
#
# It changes the site at http://localhost:8080, so run docker compose down -v
# afterwards to start fresh.

set -euo pipefail
cd "$(dirname "$0")/../.."

if [ ! -f vendor/autoload.php ]; then
	echo "Run composer install first." >&2
	exit 1
fi

wp() {
	docker compose --progress quiet run --rm -T cli wp "$@"
}

docker compose up -d --wait
until docker compose exec -T wordpress test -f wp-config.php; do
	sleep 1
done

wp core is-installed 2>/dev/null || wp core install --url=http://localhost:8080 --title="Upgrade Radar E2E" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
# No requests to WordPress.org, so the update data setup.php stores stays as it is.
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw
wp plugin activate upgrade-radar
wp eval-file wp-content/plugins/upgrade-radar/tests/e2e/setup.php
# From here on, no wp plugin list or wp theme list: they check for updates,
# which replaces that update data.

docker compose exec -T wordpress php -r 'echo "disable_functions: ", ini_get( "disable_functions" ), "\nmax_execution_time: ", ini_get( "max_execution_time" ), "\n";'

php tests/e2e/scan.php full
wp plugin activate ur-e2e-rival-scanner
php tests/e2e/scan.php rival
wp plugin deactivate ur-e2e-rival-scanner
php tests/e2e/scan.php resume
php tests/e2e/scan.php cancel
php tests/e2e/scan.php background

echo "All end-to-end scenarios passed."

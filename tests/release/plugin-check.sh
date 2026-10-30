#!/usr/bin/env bash
# Runs Plugin Check, the checker WordPress.org reviews plugins with, on the
# release zip that tests/release/run.sh installed, and fails if it reports
# anything, warnings included:
#
#   tests/release/run.sh && tests/release/plugin-check.sh
#
# Plugin Check comes from GitHub, at the tag below, into tools/plugin-check.
# Installing it makes WordPress forget its update data, so verdicts on the
# results page say "Update first" afterwards. Run it last.

set -euo pipefail
cd "$(dirname "$0")/../.."
version=2.1.0
dir=tools/plugin-check

if [ "$(cat "$dir/.installed" 2>/dev/null)" != "$version" ]; then
	rm -rf "$dir"
	git clone --quiet --depth 1 --branch "$version" https://github.com/WordPress/plugin-check.git "$dir"
	COMPOSER_ALLOW_SUPERUSER=1 composer install --working-dir="$dir" --no-dev --no-interaction --no-progress --quiet
	echo "$version" > "$dir/.installed"
fi

(cd tools && zip -qr ../build/plugin-check.zip plugin-check -x '*/.git/*' 'plugin-check/tests/*' 'plugin-check/node_modules/*')

compose() {
	docker compose -f tests/release/docker-compose.yml --progress quiet "$@"
}

compose run --rm -T cli wp plugin install /release/plugin-check.zip --activate --force
output=$(compose run --rm -T cli wp plugin check upgrade-radar --format=strict-table --fields=file,line,type,code,message 2>&1)
echo "$output"

if ! grep -q 'No errors found' <<< "$output"; then
	echo "Plugin Check reported the problems above." >&2
	exit 1
fi

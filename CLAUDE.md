# CLAUDE.md

Guidance for Claude Code sessions in this repo. Read `PLAN.md` first: it covers the product, our angle against competitors, v1 scope and milestones.

## What we're building

A WordPress plugin that scans a site's active plugins and theme and tells the owner whether they're ready for a newer PHP version (8.1–8.5), with one plain-English verdict per item (Ready / Update first / No compatible version yet / Needs a developer).

The plugin is **Upgrade Radar – compatibility checker for PHP**. Use `upgrade-radar` as the slug, text domain and main file name, with `UpgradeRadar` as the PHP namespace and `upgrade_radar_` as the prefix for functions, options and hooks. Names must never start with "PHP" or "WordPress" (WordPress.org Guideline 17). Keep the display name in one constant.

## Environment

- Cloud sessions have PHP 8.3 and Composer pre-installed, and packagist.org is reachable.
- Docker is available for a throwaway WordPress (official `wordpress` and `mariadb` images) for manual and integration tests.
- The plugin itself must run on PHP 7.4+ and current WordPress.
- Cloud session quirks: the Docker daemon may need starting (`dockerd > /tmp/dockerd.log 2>&1 &`), and Docker Hub can rate-limit pulls (`mirror.gcr.io/library/<image>` works). Run Composer with `COMPOSER_ALLOW_SUPERUSER=1` so the PHPCS installer plugin runs. GitHub zip downloads are blocked, so a fresh `composer install` falls back to git clones and takes a few minutes. downloads.wordpress.org is blocked too: for real plugin code, use the WordPress Docker image or clone plugins from GitHub.
- To prove PHP 7.4 support, run the tests in Docker: `docker run --rm -v "$PWD":/app -w /app php:7.4-cli vendor/bin/phpunit`.

## Layout (milestone 1 built the engine, milestone 2 the plugin, milestone 3 the release)

```
upgrade-radar.php         plugin bootstrap (plugin header, GPLv2-or-later); src/Plugin.php wires the hooks
src/Scanner/              WordPress-free engine wrapping PHP_CodeSniffer + PHPCompatibilityWP, and the verdict logic
src/Scanner/ruleset.xml   the PHPCS ruleset scans use, with the reasons for each exclusion
src/Scanner/Guards/       possible false alarms: guards around findings, evaluated for the target version
src/Admin/                Tools → Upgrade Radar: page, AJAX step, form actions, results, CSV export
src/Jobs/                 resumable background batching (WordPress side of ScanJob): store, lock, inventory
assets/                   the admin page's script and styles
uninstall.php             removes the plugin's options and scheduled event
src/Cli/                  WP-CLI command (optional in v1)
bin/upgrade-radar         standalone CLI for the engine, no WordPress needed
bin/build-release         builds build/upgrade-radar.zip: prefixed with PHP-Scoper, only what users need
bin/prefix-scanner.php    used by bin/build-release: prefixes the dependencies' names in src/Scanner, changing nothing else
tests/Unit/               PHPUnit tests for the engine and the verdicts
tests/fixtures/           breaks/phpXY.php (changes from PHP X.Y), clean/, edge/ and guards/ sample code
tests/e2e/                end-to-end test in the Docker WordPress (run.sh, setup.php, scan.php)
tests/release/            release check: the zip on a fresh WordPress (run.sh), and Plugin Check (plugin-check.sh)
tools/php-scoper/         PHP-Scoper for bin/build-release, apart from our dependencies (needs PHP 8.2)
scoper.inc.php            PHP-Scoper config for the release build: prefixes vendor/
readme.txt                WordPress.org readme; license.txt is the GPLv2 text
.wordpress-org/           WordPress.org assets: icon, banner (SVG source in source/) and screenshots
docker-compose.yml        local WordPress for manual tests (docker/php/ has its php.ini, docker/apache/ makes WP-Cron work)
```

Fixtures mark each expected finding at the end of its line with `// expect: error <sniff code>`, adding ` (false alarm)` for a possible false alarm. The tests compare those markers with what the scanner finds, for every target version. Our code follows the WordPress Coding Standards (tabs, snake_case methods, `array()`), with PSR-4 class files under `src/`. Plugin Check runs on everything in the release zip, the WordPress-free engine included, so `composer lint` only exempts `bin/` and `src/Scanner/Cli.php` (which the zip leaves out) from WordPress's escaping and file-function sniffs. Where a finding is wrong, a `phpcs:ignore` comment says why.

## Commands

- `composer install`
- `composer test`: PHPUnit
- `composer lint`: PHPCS with the WordPress Coding Standards, on our own code (`composer lint:fix` fixes what it can)
- `bin/upgrade-radar scan --php=8.3 <path>...`: scan plugin or theme code from the command line
- `docker compose up -d`: WordPress at http://localhost:8080
- `tests/e2e/run.sh`: end-to-end test in that WordPress, about ten minutes. After its setup, don't run `wp plugin list` or `wp theme list`: they check for updates, which wipes the update data the verdicts are tested against.
- `bin/build-release`: the release zip, `build/upgrade-radar.zip`. It must stay under 10 MB.
- `tests/release/run.sh`: installs the release zip on a fresh WordPress at http://localhost:8081 and runs a full scan. Then `tests/release/plugin-check.sh` runs Plugin Check on it.
- A release: set the version in `upgrade-radar.php`, `Plugin::VERSION` and readme.txt (stable tag and changelog), then run the Release workflow (`.github/workflows/release.yml`) on `main`, or push a tag such as `v1.0.1`. It checks the zip like CI and publishes it as the GitHub release `v<version>`. Cloud sessions can't push tags, so they start the workflow with the GitHub tools.

## Hard rules

- **No `exec()`, `shell_exec()`, `proc_open()`, `system()` or `passthru()`.** Run PHP_CodeSniffer in-process. Working on hosts that disable these functions is our main edge over Eli's scanner.
- **Scan in batches** (a few files or one plugin per request or cron tick) so a scan never hits `max_execution_time`. Scans must be resumable.
- **Read-only.** Never modify scanned files, and only read inside `wp-content/plugins` and the active theme's directory.
- **No outside HTTP calls in the free plugin.** Anything external later must be opt-in and documented in readme.txt (Guideline 7).
- **No locked or paid features in this plugin** (Guideline 5).
- **Pin the rulesets exactly:** `phpcompatibility/phpcompatibility-wp` `3.0.0-alpha2` with `phpcompatibility/php-compatibility` `10.0.0-alpha2`. They require `@dev` sub-dependencies, so composer.json needs explicit stability settings. They're alpha, so the UI says results may include false alarms.
- **Don't clash with other plugins that bundle PHP_CodeSniffer** (both rivals use it). Load it only during a scan, and evaluate prefixing vendor code (for example with Strauss or PHP-Scoper). Milestone 1 evaluated it: PHP-Scoper works (see PLAN.md and `scoper.inc.php`).
- Keep `src/Scanner/` free of WordPress functions so it can be unit-tested and reused by a future cloud service.
- On every admin action: capability check (`manage_options`), nonce, sanitized input, escaped output.
- GPL-compatible code only, with GPLv2-or-later headers.

## Definition of done for v1

- Scans a site with 30+ plugins without timing out under a 30-second `max_execution_time`.
- Flags the fixture breaks for PHP 8.1, 8.2, 8.3 and 8.4, and stays quiet on the clean fixtures.
- Works with `exec()` disabled via `disable_functions`.
- Plugin Check reports no errors, and readme.txt is complete.
- Work lands in small commits with tests. Update `PLAN.md` if the scope changes.

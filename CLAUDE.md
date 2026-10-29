# CLAUDE.md

Guidance for Claude Code sessions in this repo. Read `PLAN.md` first: it covers the product, our angle against competitors, v1 scope and milestones.

## What we're building

A WordPress plugin that scans a site's active plugins and theme and tells the owner whether they're ready for a newer PHP version (8.1–8.5), with one plain-English verdict per item (Ready / Update first / No compatible version yet / Needs a developer).

The plugin is **Upgrade Radar – compatibility checker for PHP**. Use `upgrade-radar` as the slug, text domain and main file name, with `UpgradeRadar` as the PHP namespace and `upgrade_radar_` as the prefix for functions, options and hooks. Names must never start with "PHP" or "WordPress" (WordPress.org Guideline 17). Keep the display name in one constant.

## Environment

- Cloud sessions have PHP 8.3 and Composer pre-installed, and packagist.org is reachable.
- Docker is available for a throwaway WordPress (official `wordpress` and `mariadb` images) for manual and integration tests.
- The plugin itself must run on PHP 7.4+ and current WordPress.

## Planned layout (create during milestones 1–2)

```
upgrade-radar.php         plugin bootstrap (plugin header, GPLv2-or-later)
src/Scanner/              WordPress-free engine wrapping PHP_CodeSniffer + PHPCompatibilityWP
src/Admin/                admin screen and results UI
src/Jobs/                 resumable background batching
src/Cli/                  WP-CLI command (optional in v1)
tests/Unit/               PHPUnit tests for the engine
tests/fixtures/           sample code with known PHP 8.x breaks, plus clean code
readme.txt                WordPress.org readme
docker-compose.yml        local WordPress for manual tests
```

## Commands (set these up in milestone 1)

- `composer install`
- `composer test`: PHPUnit
- `composer lint`: PHPCS with the WordPress Coding Standards, on our own code
- `docker compose up -d`: WordPress at http://localhost:8080

## Hard rules

- **No `exec()`, `shell_exec()`, `proc_open()`, `system()` or `passthru()`.** Run PHP_CodeSniffer in-process. Working on hosts that disable these functions is our main edge over Eli's scanner.
- **Scan in batches** (a few files or one plugin per request or cron tick) so a scan never hits `max_execution_time`. Scans must be resumable.
- **Read-only.** Never modify scanned files, and only read inside `wp-content/plugins` and the active theme's directory.
- **No outside HTTP calls in the free plugin.** Anything external later must be opt-in and documented in readme.txt (Guideline 7).
- **No locked or paid features in this plugin** (Guideline 5).
- **Pin the rulesets exactly:** `phpcompatibility/phpcompatibility-wp` `3.0.0-alpha2` with `phpcompatibility/php-compatibility` `10.0.0-alpha2`. They require `@dev` sub-dependencies, so composer.json needs explicit stability settings. They're alpha, so the UI says results may include false alarms.
- **Don't clash with other plugins that bundle PHP_CodeSniffer** (both rivals use it). Load it only during a scan, and evaluate prefixing vendor code (for example with Strauss or PHP-Scoper).
- Keep `src/Scanner/` free of WordPress functions so it can be unit-tested and reused by a future cloud service.
- On every admin action: capability check (`manage_options`), nonce, sanitized input, escaped output.
- GPL-compatible code only, with GPLv2-or-later headers.

## Definition of done for v1

- Scans a site with 30+ plugins without timing out under a 30-second `max_execution_time`.
- Flags the fixture breaks for PHP 8.1, 8.2, 8.3 and 8.4, and stays quiet on the clean fixtures.
- Works with `exec()` disabled via `disable_functions`.
- Plugin Check reports no errors, and readme.txt is complete.
- Work lands in small commits with tests. Update `PLAN.md` if the scope changes.

# Upgrade Radar – compatibility checker for PHP

A WordPress plugin that tells site owners whether their plugins and theme are ready for a newer PHP version, with one plain-English verdict per item.

**Status:** milestones 1 (the scanning engine and a command-line tool), 2 (the WordPress plugin) and 3 (the release build, readme and assets) are done, so the plugin is ready to submit to WordPress.org. v1 is targeted for the weekend of Oct 31 – Nov 1, 2026.

- [`PLAN.md`](PLAN.md): why this, our angle, v1 scope, milestones and the numbers that decide what's next
- [`CLAUDE.md`](CLAUDE.md): instructions for Claude Code sessions working in this repo

## Using the plugin

Install it in `wp-content/plugins/upgrade-radar` with its `vendor` directory (run `composer install --no-dev` in a git checkout), activate it, and open **Tools → Upgrade Radar**. Pick the PHP version you want to move to and start the scan. It checks the active plugins, the active theme and its parent theme, in short batches so it never runs into the server's time limit. You can leave the page: the scan carries on with WP-Cron, and the page picks up where it is when you come back.

Each plugin and theme gets one verdict for that PHP version:

- **Ready:** nothing breaks. Deprecation warnings are listed, but deprecated code still works.
- **Update first:** WordPress knows of a newer version, or hasn't checked the installed one for updates yet. Update, then scan again.
- **No compatible version yet:** it's the latest version and still breaks. Ask its developer, or look for a replacement.
- **Needs a developer:** WordPress can't check it for updates, so it's custom code or from outside WordPress.org, and it breaks.

Verdicts use the update data WordPress already has, without contacting any server. Each item's problems are listed by file and line, and **Download CSV** exports all of them. The checks come from alpha releases of PHPCompatibility, so some findings may be false alarms. Findings in code that never runs on the chosen version, such as code behind `if ( PHP_VERSION_ID < 80000 )`, are marked as possible false alarms and don't affect the verdict.

## Development

You need PHP 7.4 or later and Composer.

```bash
composer install
```

```bash
composer test
```

```bash
composer lint
```

`composer test` runs the PHPUnit tests. `composer lint` checks our own code with the WordPress Coding Standards and for PHP 7.4 compatibility, and `composer lint:fix` fixes what it can.

### Scanning from the command line

`bin/upgrade-radar` runs the scanner without WordPress, on plugin or theme directories or single PHP files:

```bash
bin/upgrade-radar scan --php=8.3 path/to/plugin path/to/theme
```

Add `--summary` for totals only, or `--format=json` for machine-readable output. The exit status is 1 when errors are found, 0 otherwise (warnings alone don't count), 2 for usage errors and 3 when the scanner can't run.

### A local WordPress

```bash
docker compose up -d
```

WordPress runs at http://localhost:8080 with this repo mounted read-only as `wp-content/plugins/upgrade-radar`. Its PHP disables `exec()` and friends and allows 30 seconds and 128 MB per request, like a locked-down host. `docker-compose.yml` shows how to install WordPress with WP-CLI, and `docker compose down -v` removes everything.

### End-to-end test

```bash
tests/e2e/run.sh
```

It sets up that WordPress with 35 plugins and a child theme, then runs scans through the admin page's endpoints and checks the verdicts, the CSV, the 30-second limit, a rival plugin that loads its own PHP_CodeSniffer, cancelling, and WP-Cron finishing a scan after the page closes. It takes about ten minutes and changes the site, so run `docker compose down -v` afterwards.

### Release build

```bash
bin/build-release
```

This builds `build/upgrade-radar.zip`, the zip to submit to WordPress.org. It holds only what users need. PHP_CodeSniffer, PHPCSUtils and PHPCompatibility are prefixed with `UpgradeRadarVendor\` by [PHP-Scoper](https://github.com/humbug/php-scoper), so they can't clash with another plugin's copy. Our own code goes in as it is in git, except for the names in `src/Scanner` that refer to those packages, which `bin/prefix-scanner.php` prefixes. The script checks the zip stays under WordPress.org's 10 MB limit. It needs PHP 8.2 or later for PHP-Scoper, plus Composer, git and zip.

```bash
tests/release/run.sh
```

```bash
tests/release/plugin-check.sh
```

`run.sh` installs the zip on a fresh WordPress at http://localhost:8081 and runs a full scan with it. `plugin-check.sh` then runs [Plugin Check](https://github.com/WordPress/plugin-check), the tool WordPress.org reviews plugins with, and fails on any finding, warnings included. CI runs both on pull requests. `docker compose -f tests/release/docker-compose.yml down -v` removes that WordPress.

Pushing a version tag, such as `v1.0.0`, runs `.github/workflows/release.yml`: it builds the zip, runs both checks on it, and publishes it as that tag's GitHub release. The tag has to match the version in `upgrade-radar.php` and readme.txt's stable tag.

The WordPress.org assets (icon, banner and screenshots) are in `.wordpress-org/`.

## How the scanner decides

The engine in `src/Scanner/` runs [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) with [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) in-process, without `exec()`, and never changes the files it reads.

- **The question it answers:** will code that runs on PHP 7.4 today keep working on the target version? It checks the range from 7.4 (WordPress's minimum) to the target, which catches breaks that a check against the target alone misses, such as PHP 4 style constructors.
- **Errors** are code that breaks on the target version. **Warnings** are code that's deprecated there and still works.
- **No new-feature checks.** PHPCompatibility also flags features that are newer than 7.4, but code that runs on a site today already handles those, so for an upgrade they're false alarms. `src/Scanner/ruleset.xml` lists everything excluded and why.
- **Annotations:** `phpcs:ignore` and `phpcs:disable` comments count only when they name a PHPCompatibility sniff. Authors use those to mark reviewed false alarms. Other annotations, and `phpcs:ignoreFile`, are usually there for other rule sets and don't hide breaks.
- **Possible false alarms:** a finding in code that only runs when a guard is false on the target version is listed, but doesn't count as an error or a warning. The guard can be a version check, such as `if ( PHP_VERSION_ID < 80000 )`, evaluated for every release of the target, or a check for the very function, class or constant the finding says PHP removed, such as `function_exists( 'each' )` around `each()`. Checks of anything else prove nothing, so declaration guards like `if ( ! function_exists( 'foo' ) ) { function foo() { … } }` never excuse the code inside them, and neither does any guard around syntax that no longer compiles.
- **Big files:** a file that would need more memory than the server allows is skipped and reported as not checked. Scans run in resumable batches, and a file that still crashes the PHP process is skipped on the next try.

The rules are pinned to PHPCompatibilityWP 3.0.0-alpha2 and PHPCompatibility 10.0.0-alpha2. These are alpha releases, so some findings may be false alarms. To reproduce a scan with plain PHP_CodeSniffer (which honors every annotation):

```bash
vendor/bin/phpcs -s --standard=src/Scanner/ruleset.xml --runtime-set testVersion 7.4-8.3 path/to/plugin
```

## License

GPLv2 or later.

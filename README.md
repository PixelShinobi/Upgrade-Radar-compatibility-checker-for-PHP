# Upgrade Radar – compatibility checker for PHP

A WordPress plugin that tells site owners whether their plugins and theme are ready for a newer PHP version, with one plain-English verdict per item.

**Status:** milestone 1 (the scanning engine and a command-line tool) is done. The WordPress plugin itself comes in milestone 2. v1 is targeted for the weekend of Oct 31 – Nov 1, 2026.

- [`PLAN.md`](PLAN.md): why this, our angle, v1 scope, milestones and the numbers that decide what's next
- [`CLAUDE.md`](CLAUDE.md): instructions for Claude Code sessions working in this repo

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

## How the scanner decides

The engine in `src/Scanner/` runs [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) with [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) in-process, without `exec()`, and never changes the files it reads.

- **The question it answers:** will code that runs on PHP 7.4 today keep working on the target version? It checks the range from 7.4 (WordPress's minimum) to the target, which catches breaks that a check against the target alone misses, such as PHP 4 style constructors.
- **Errors** are code that breaks on the target version. **Warnings** are code that's deprecated there and still works.
- **No new-feature checks.** PHPCompatibility also flags features that are newer than 7.4, but code that runs on a site today already handles those, so for an upgrade they're false alarms. `src/Scanner/ruleset.xml` lists everything excluded and why.
- **Annotations:** `phpcs:ignore` and `phpcs:disable` comments count only when they name a PHPCompatibility sniff. Authors use those to mark reviewed false alarms. Other annotations, and `phpcs:ignoreFile`, are usually there for other rule sets and don't hide breaks.
- **Big files:** a file that would need more memory than the server allows is skipped and reported as not checked. Scans run in resumable batches, and a file that still crashes the PHP process is skipped on the next try.

The rules are pinned to PHPCompatibilityWP 3.0.0-alpha2 and PHPCompatibility 10.0.0-alpha2. These are alpha releases, so some findings may be false alarms. To reproduce a scan with plain PHP_CodeSniffer (which honors every annotation):

```bash
vendor/bin/phpcs -s --standard=src/Scanner/ruleset.xml --runtime-set testVersion 7.4-8.3 path/to/plugin
```

## License

GPLv2 or later.

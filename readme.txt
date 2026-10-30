=== Upgrade Radar – compatibility checker for PHP ===
Contributors: pixelshinobi
Tags: php, php 8, compatibility, upgrade, scanner
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out if your plugins and theme are ready for PHP 8.1 to 8.5, with a plain-English verdict for each. Works on locked-down hosts.

== Description ==

Your host wants you on a newer PHP version, or the one you're on is about to lose security support (PHP 8.2's ends on December 31, 2026). Before you switch, Upgrade Radar reads the code of your active plugins and theme and tells you what to do about each one:

* **Ready**: nothing breaks on the PHP version you picked.
* **Update first**: a newer version is available, or WordPress hasn't checked yet. Update, then scan again.
* **No compatible version yet**: you have the latest version, and it still breaks. Ask its developer, or look for a replacement.
* **Needs a developer**: code WordPress can't check for updates, such as a custom plugin, that breaks.

Every problem is one click away, with its file and line, and you can download them all as a CSV file for your developer.

= Works on any host =

* **No exec() needed.** The scan runs inside WordPress. Many hosts turn off exec() and similar functions, and Upgrade Radar doesn't use them.
* **No timeouts.** It scans in short batches, one per request, so it stays well under your server's time limit, even on sites with dozens of plugins.
* **Leave the page if you like.** The scan carries on in the background with WP-Cron, and picks up where it left off.
* **Read-only.** It never changes a file, and only reads your plugins and themes.
* **Private.** Nothing leaves your site. Verdicts use the update information WordPress already has.

= How it decides =

Upgrade Radar answers one question: will code that works on PHP 7.4 today keep working on the PHP version you pick? It checks with PHP_CodeSniffer and the PHPCompatibilityWP rules, the standard tools for this.

* **Errors** are code that breaks on that PHP version. Only errors decide the verdict.
* **Warnings** are code that's deprecated there: it still works, but should be updated eventually.
* **Possible false alarms** are findings in code that never runs on that PHP version, such as code that only runs on older versions. They're listed, but don't count.

It checks the active plugins, including network-activated ones, the active theme, and its parent theme if it's a child theme.

= Credits =

Upgrade Radar includes PHP_CodeSniffer 3.13.6 (BSD-3-Clause license), and PHPCompatibility 10.0.0-alpha2, PHPCompatibilityWP 3.0.0-alpha2, PHPCompatibilityParagonie 2.0.0-alpha2 and PHPCSUtils 1.2.3 (LGPL-3.0-or-later license). Thank you to their authors.

Their namespaces start with UpgradeRadarVendor, so they can't clash with the copies other plugins bundle.

= Source code and build tools =

The source code, with its tests and build tools, is on GitHub: [github.com/PixelShinobi/Upgrade-Radar-compatibility-checker-for-PHP](https://github.com/PixelShinobi/Upgrade-Radar-compatibility-checker-for-PHP). The release zip is built by `bin/build-release`, which prefixes the bundled libraries' namespaces with PHP-Scoper.

== Installation ==

1. Install and activate the plugin from Plugins → Add New, or upload the zip file there.
2. Go to Tools → Upgrade Radar.
3. Pick the PHP version you want to move to, and click Scan now.
4. When the scan finishes, each plugin and theme shows its verdict. Open an item to see its problems by file and line, or download every finding as a CSV file.

Scans need the tokenizer and SimpleXML PHP extensions, which almost every host has. The page tells you if one is missing.

== Frequently Asked Questions ==

= Which PHP versions can I check? =

PHP 8.1, 8.2, 8.3, 8.4 and 8.5. The page suggests the next version above the one your site runs.

= My host disables exec() and has a 30-second time limit. Will it work? =

Yes, that's what Upgrade Radar is built for. It runs PHP_CodeSniffer inside WordPress, without exec(), and scans in short batches that each stay well under the time limit.

= Can I trust the results? Are there false alarms? =

Some findings will be false alarms, so treat a verdict as a strong hint, not a guarantee.

The rules come from PHPCompatibility 10.0.0-alpha2, an early release of the standard rules for PHP compatibility, and early releases have rough edges. A finding can also be about code that never runs on your site, for example a fallback for old PHP versions.

Upgrade Radar cuts down on false alarms where it safely can:

* Code that only runs on older PHP versions, such as `if ( PHP_VERSION_ID < 80000 ) { … }`, is marked "possible false alarm" and doesn't affect the verdict.
* So is code that only runs if a function, class or constant PHP removed still exists, such as `if ( function_exists( 'each' ) ) { each( … ); }`. A check of anything else doesn't count, because it doesn't prove the code can't run.
* Checks for newer PHP features are left out: code that runs on your site today already handles those.
* Plugin authors can mark lines they've reviewed with phpcs:ignore comments that name a PHPCompatibility rule.

Before you switch PHP on a live site, try the new version on a staging copy if your host offers one, and keep a backup.

= What can't it detect? =

Some changes only show up when the code runs with real data, and PHPCompatibility 10.0.0-alpha2 doesn't detect these yet:

* Passing null to a built-in function that doesn't accept it (deprecated in PHP 8.1).
* Creating dynamic properties on objects (deprecated in PHP 8.2).
* The HTML-ENTITIES encoding in mbstring functions, and callables written as strings such as 'self::method' (deprecated in PHP 8.2).
* Using ++ on strings that aren't letters and numbers (deprecated in PHP 8.3).

These are all deprecations, so they don't break your site on that version, but they may on a later one.

Upgrade Radar also doesn't check plugins and themes that aren't active, must-use plugins or drop-ins, and it skips files bigger than 2 MB or too big for your server's memory. The results say which files weren't checked.

= Does it change anything on my site? =

No. Scans only read files. Upgrade Radar keeps its scan progress and results in its own options, and removes them when you uninstall it.

= Does it send my data anywhere? =

No. Upgrade Radar makes no outside requests. Verdicts come from the update information WordPress already stores when it checks for updates.

= Will it slow down my site? =

Only while a scan runs, and only in the requests that run it: one batch at a time, each well under your server's time limit. The code checker is only loaded during a scan, never on other pages.

= What does "Update first" mean if there's no update? =

WordPress hasn't checked this version for updates yet, for example because it was just installed. Go to Dashboard → Updates to check, update if you can, then scan again.

= What does "Scan again" mean? =

The plugin or theme was updated or deactivated since the scan, so its verdict may be out of date.

= The scan only moves while the page is open =

When the page is closed, the scan runs on WP-Cron, which needs visits to your site. If WP-Cron is turned off (DISABLE_WP_CRON) and your host doesn't run it on a schedule, the scan only moves while the page is open. The page tells you when that's the case.

= Who can run scans? =

Users who can manage options, which is administrators by default.

= Another plugin I use also bundles PHP_CodeSniffer. Is that a problem? =

No. Upgrade Radar's copies of PHP_CodeSniffer and its rules have their own namespace, so they can't clash with another plugin's copy.

== Screenshots ==

1. One verdict per plugin and theme for the PHP version you picked, with counts at the top.
2. Every problem with its file and line. Possible false alarms say which check keeps the code from running.
3. A scan in progress. You can leave the page: it carries on in the background.

== Changelog ==

= 1.0.0 =
* First release.
* Scan the active plugins, the active theme and its parent theme for a target PHP version (8.1 to 8.5).
* One plain-English verdict per item — Ready, Update first, No compatible version yet, Needs a developer — worked out from WordPress's own update data.
* Problems listed by file and line, with a CSV export of every finding.
* Findings behind version or existence checks that can never trigger on the target are marked as possible false alarms and don't decide the verdict.
* Background scans in short, resumable batches (admin page or WP-Cron) that run on any host: in-process, no `exec()` needed.

== Upgrade Notice ==

= 1.0.0 =
First release of Upgrade Radar.

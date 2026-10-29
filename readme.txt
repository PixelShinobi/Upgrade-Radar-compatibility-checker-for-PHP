=== Upgrade Radar – compatibility checker for PHP ===
Contributors: pixelshinobi
Tags: php, compatibility, php upgrade, php version, updates
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks whether your active plugins and theme are ready for a newer PHP version, with a plain-English verdict for each one.

== Description ==

Before you switch your site to a newer PHP version, Upgrade Radar tells you whether the plugins and theme you actually use are ready for it — and what to do next for each one.

Pick the PHP version you want to move to (8.1 to 8.5) and start a scan. Upgrade Radar checks the active plugins, the active theme and its parent theme, in short batches, so a scan never runs into your server's time limit. You can leave the page: the scan carries on with WP-Cron, and the page picks up where it is when you come back.

The scan runs entirely on your own server, in-process. It never uses `exec()` or other functions that locked-down hosts disable, and it makes no requests to outside servers. Verdicts are worked out from the update data WordPress already has.

Each plugin and theme gets one verdict for that PHP version:

* **Ready:** nothing breaks. Deprecation warnings are listed, but deprecated code still works.
* **Update first:** WordPress knows of a newer version, or hasn't checked the installed one for updates yet. Update, then scan again.
* **No compatible version yet:** it's the latest version and still breaks. Ask its developer, or look for a replacement.
* **Needs a developer:** WordPress can't check it for updates, so it's custom code or from outside WordPress.org, and it breaks.

Each item's problems are listed by file and line, and you can download every finding as a CSV file. A finding that sits behind a version check or an existence check — such as `function_exists()` — that can never trigger on the target version is marked as a **possible false alarm**: it stays visible, with the guard it depends on, but it doesn't decide the verdict.

Please read the results the way the plugin presents them: the checks come from alpha releases of PHPCompatibility, so some findings may be false alarms, and a few kinds of breaks are not detected yet. A "Ready" verdict is a strong signal, not a guarantee — test important sites on a staging copy before you upgrade PHP.

Upgrade Radar never changes the files it reads, and it only reads inside your plugins directory and the active theme's directory.

== Installation ==

1. Install Upgrade Radar from **Plugins → Add New** in your WordPress admin, or upload the plugin's zip file there, and activate it.
2. Open **Tools → Upgrade Radar**.
3. Pick the PHP version you want to move to, and start the scan.
4. When the scan finishes, each plugin and theme shows its verdict; click an item to see its problems by file and line, or download the CSV with every finding.

== Frequently Asked Questions ==

= Which PHP versions can I check? =

You can check targets from PHP 8.1 to 8.5. Your site itself must run PHP 7.4 or later — that is WordPress's minimum, and the scan asks whether code that runs on PHP 7.4 today keeps working on the target version.

= My host disables exec() and has a 30-second time limit. Will it work? =

Yes — that is the case Upgrade Radar is built for. It runs PHP_CodeSniffer in-process, without `exec()`, and scans in short, resumable batches: one batch per request from the admin page, and WP-Cron batches after you close the page.

One exception: if your site sets `DISABLE_WP_CRON` and has no real cron job, scans only run while the results page is open. The page tells you when that is the case.

= Does it change anything on my site? =

No. Scanning is read-only. The plugin stores its scan progress and results in its own options, and removes them when you uninstall it.

= Does it send my data anywhere? =

No. Everything runs on your server, and the plugin makes no requests to outside servers. The verdicts use the update information WordPress itself already stores when it checks for updates.

= Why does a plugin say "Update first" when I only just installed it? =

WordPress has not checked that installed version for updates yet, so there is no update information for it. Let WordPress check for updates — or update the plugin — and scan again. Updating is the cheapest next step either way.

= Another plugin I use also bundles PHP_CodeSniffer. Is that a problem? =

No. Upgrade Radar's bundled copy of PHP_CodeSniffer is prefixed with its own namespace, so it cannot clash with another plugin's copy.

= Can the results be wrong? =

They can include false alarms. The compatibility rules are alpha releases of PHPCompatibility. Findings behind guards that can never trigger on the target version are marked "possible false alarms" and never decide a verdict, and the results page reminds you that results may include false alarms. Always test a PHP upgrade on a staging copy of an important site first.

== Screenshots ==

1. Scan results: one plain-English verdict for every active plugin and theme, for the PHP version you picked.
2. One item's problems in detail: every finding by file and line, with possible false alarms marked.

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

# Upgrade Radar: Plan

_Upgrade Radar – compatibility checker for PHP. Last updated Oct 29, 2026._

## Why this, why now

- **Demand is proven.** WP Engine's PHP Compatibility Checker still has 200,000+ active installs, but it is officially no longer maintained, rated 2.7★ (131 ratings), and only checks up to PHP 8.0.
- **There's a deadline.** PHP 8.2 loses security support on Dec 31, 2026, and hosts are already forcing upgrades (Acquia retired PHP 8.2 on its platform on Oct 1, 2026).
- **Competition has arrived (new since Oct 14).** Two maintained, free scanners are now on WordPress.org:
  - *BrightLeaf Digital PHP Compatibility Scanner*: 500+ installs, checks PHP 8.0–8.5, scans locally, free, no paid tier.
  - *Eli's PHP Compatibility Scanner*: 1,000+ installs, checks 7.4–8.4, needs PHP's `exec()` (many managed hosts disable it), sells a Pro plugin that scans remotely.

  A plain free scanner is now table stakes. We win on the angle below and on shipping fast, while the dead incumbent still holds most of the installs.

## Our angle

1. **Works on any host.** Run the scan in-process (no `exec()`) in small background batches, so it doesn't fail on locked-down hosting or time out on big sites.
2. **Says what to do, not just what's wrong.** One plain-English verdict per plugin/theme for the chosen PHP version:
   - **Ready**
   - **Update first**: an update is available; update, then rescan
   - **No compatible version yet**: already on the latest version and still flagged; contact the developer or consider a replacement
   - **Needs a developer**: custom code that isn't from WordPress.org

   Details (file, line, rule) are one click away.
3. **Later, as a paid service:** instant results from a cloud database of pre-scanned WordPress.org plugins, AI explanations and fix drafts, scheduled re-scans with email alerts, and a multi-site view for agencies.

## WordPress.org rules that shape the design

- **No locked features in the free plugin** (Guideline 5). Paid features must be a separate service (allowed as "serviceware", Guideline 6) or a separate Pro plugin sold elsewhere.
- **No calls to outside servers without explicit opt-in** (Guideline 7).
- **All code GPL-compatible** (Guideline 1). PHP_CodeSniffer is BSD-3-Clause; PHPCompatibility is LGPL-3.0-or-later. Both are fine.
- **The public slug can't start with another project's name** (Guideline 17). **Decided:** the plugin is **Upgrade Radar – compatibility checker for PHP**, with `upgrade-radar` as the requested slug and text domain. WordPress.org confirms the slug during review.
- **Upgrade prompts must be rare and dismissible** (Guideline 11).

## v1: free plugin, one weekend

**In**
- Admin screen: choose a target PHP version (8.1–8.5) and scan the active plugins, the active theme and its parent theme.
- Engine: PHP_CodeSniffer + PHPCompatibilityWP `3.0.0-alpha2` (pinned), run in-process, batched and resumable.
- Results: a verdict per item, error/warning counts, file:line details, an "update available" hint, and CSV export.
- If time allows: a WP-CLI command (`wp upgrade-radar scan --php=8.3`), which agencies use.

**Out until there's demand:** the cloud database, AI explanations, email alerts and the multi-site dashboard.

## Milestones

1. **Engine:** a WordPress-free scanner library plus a CLI, tested on fixture code with known PHP 8.1–8.4 breaks. **Done Oct 29**, see [Milestone 1 results](#milestone-1-results).
2. **Plugin:** admin screen, background batching and results UI; manual test in a Docker WordPress. **Done Oct 29**, see [Milestone 2 results](#milestone-2-results).
3. **Submit:** readme.txt and screenshots, a clean Plugin Check run, then submit to WordPress.org. **Ready to submit Oct 29**, see [Milestone 3 results](#milestone-3-results). Submitting is the owner's step.
4. **Launch watch:** track installs, reviews and support threads.
5. **Paid service:** only if the numbers below say so.

**Target: ship v1 the weekend of Oct 31 – Nov 1, 2026.**

## Milestone 1 results

The engine lives in `src/Scanner/`, with `bin/upgrade-radar` as its command-line front end. Its tests pass on PHP 7.4, 8.3 and 8.5.

**Decisions**
- **What a scan checks:** will code that runs on PHP 7.4 today keep working on the target? Scans use PHPCompatibility's testVersion `7.4-<target>`. Checking against the target alone misses breaks that only show when old code moves up, such as PHP 4 style constructors. The 7.4 lower bound is WordPress's minimum PHP version and ours, and keeping it fixed gives a plugin version the same result on every site, which the planned cloud database needs.
- **No new-feature checks:** the 92 `New*` sniffs flag features newer than 7.4, which code running on a site today already handles. A modern PHP 8.1 plugin got 12 false errors from them. They're excluded, along with a few pre-7.4 behavior changes and pieces of advice (`src/Scanner/ruleset.xml` says why for each).
- **Errors and warnings:** errors break on the target version. Warnings are deprecations that still work.
- **Annotations:** only `phpcs:ignore`/`phpcs:disable` comments that name a PHPCompatibility sniff count. They mark false alarms that authors reviewed (WordPress core has 32). Bare ones and `phpcs:ignoreFile` are usually there for other rule sets: Elementor has bare `phpcs:ignore` on real PHP 8.4 deprecations.
- **Prefixing:** PHP-Scoper works with 2 small patchers (`scoper.inc.php`). The scoped build gives the same findings and still scans after another plugin loaded its own PHP_CodeSniffer. Use it for the release build in milestone 3. Until then, the engine loads PHP_CodeSniffer only during a scan and refuses to scan when another copy is loaded.

**Measurements**
- **Speed and memory:** 10–40 ms per file. The biggest files take 0.5–3 s and need about 1 KB of memory per token. A PHPCSUtils cache grew by about 100 KB per file, so the engine clears it after each one. Files that wouldn't fit in the free memory are skipped and reported as not checked.
- **Under host limits:** in the Docker WordPress, with `exec()` disabled, a 30 s limit and 128 MB, a site's plugins and themes plus a 1,301-file plugin took 4 requests of at most 15.2 s and 76 MB each.
- **Real plugins:** no findings for Contact Form 7, Yoast SEO (3,257 files), Classic Editor, Akismet or the default themes. Elementor got 16 warnings, all real PHP 8.0 and 8.4 deprecations. 2015 versions of NextGEN Gallery, WP Super Cache and All in One SEO showed real breaks: `create_function()`, `$string{0}` and PHP 4 constructors.
- **Bundled code that's guarded:** WordPress core still gets 4 errors and 14 warnings, for calls behind `function_exists()` or version checks, such as `get_magic_quotes_gpc()` in getID3. Plugins that bundle such libraries will show the same false alarms. (Since then, [possible false alarms](#possible-false-alarms) handle these.)
- **Release size:** the vendor directory without tests and docs is 9.7 MB, about 2 MB zipped. The release zip must leave out `tests/`, `bin/` and `docker/`: the fixtures are PHP that's broken on purpose, which Plugin Check and possibly WordPress.org's SVN checks would reject.

**Not detected by PHPCompatibility 10.0.0-alpha2:** passing null to non-nullable built-in parameters (8.1), dynamic properties (8.2), the `HTML-ENTITIES` mbstring encoding and callables such as `'self::method'` (8.2), and `++` on non-alphanumeric strings (8.3).

**Notes for milestone 2**
- **Batches:** save the `ScanJob` state in an option with autoload off, and pass a checkpoint callback that saves it the same way. Give each batch about half of `max_execution_time`, at most 15 s.
- **Requests:** run batches in our own AJAX or REST requests. WP-Cron runs every due event in one request, so a rival scanner's event could load its PHP_CodeSniffer first. In that case `ScannerUnavailable` is temporary: retry in a later request.
- **Item roots:** take them from WordPress (`get_plugins()`, the active theme) and check they're inside the plugins or themes directory.
- **Output:** engine messages are plain text, so escape them on output.
- **Verdicts:** errors decide them. Warnings show as "deprecated, still works" and don't block "Ready".

## Milestone 2 results

The plugin is `upgrade-radar.php` with `src/Admin/` and `src/Jobs/`. Tools → Upgrade Radar picks a target (8.1–8.5, defaulting to the next version above the site's), scans the active plugins, the active theme and its parent theme, and shows one verdict per item, with counts, expandable file:line details, a CSV export and a note that results may include false alarms.

**How scans run**
- **One batch per request**, each up to half of what's left of the time limit and at most 15 s: the admin page's AJAX calls while it's open, WP-Cron otherwise. WP-Cron leaves the scan alone for 60 s after the page's last batch, so closing the page hands over within a minute.
- **One batch at a time:** an option row works as a lock (`INSERT IGNORE`, like `WP_Upgrader::create_lock()`), and a batch only saves while it holds the lock. Starting or cancelling a scan breaks the lock, so a batch still running for the old scan can't save over the new one.
- **Another plugin's PHP_CodeSniffer:** the scan names the plugin and waits. In a WP-Cron request that can be a coincidence, so it retries for 3 batches, then pauses with a Resume button and asks to deactivate that plugin while scanning.
- **Paths:** they come from WordPress and must be inside the plugins directory or a theme directory, checked again on every batch.

**Verdicts** come from the `update_plugins` and `update_themes` data WordPress stores when it checks for updates. Reading it makes no requests. Errors decide; warnings never block "Ready".
- **Ready:** no errors.
- **Update first:** WordPress lists a newer version, or has no update data for the installed version yet (just installed, or update checks failing). Updating is the cheapest next step either way, and the row says which case it is.
- **No compatible version yet:** WordPress.org says it's the latest version.
- **Needs a developer:** WordPress doesn't track it for updates, so it's custom code or from outside WordPress.org.

Verdicts are worked out when the page shows them, so they follow WordPress's update checks without a new scan. Items updated or deactivated since the scan show "Scan again".

**Possible false alarms:** built right after milestone 2, see [Possible false alarms](#possible-false-alarms).

**End-to-end test** (`tests/e2e/run.sh`, about ten minutes), in the Docker WordPress with `exec()` disabled, a 30 s limit and 128 MB. The site has 35 plugins (3,593 PHP files, mostly WordPress core code split into 26 plugins) and a child theme, with update data set up for each verdict:
- **Full scan for PHP 8.4:** 9 AJAX requests, the slowest 15.5 s, 113 s in all. Every verdict as expected, the 2.9 MB file reported as not checked, and every finding in the CSV.
- **Another plugin's PHP_CodeSniffer:** the scan paused after 3 requests, naming the plugin. Once it was deactivated, Resume finished the scan.
- **Cancel during a batch, then start again:** the new scan survives the old batch.
- **Page closed after one batch:** WP-Cron, started by visitors' page loads, finished the scan in 110 s.

`docker/apache/loopback.conf` lets the Docker WordPress reach itself at http://localhost:8080, which WP-Cron needs.

**Notes for milestone 3**

All addressed — see Milestone 3 results. One caveat stands: without WP-Cron (`DISABLE_WP_CRON` and no real cron), scans only run while the page is open. The page says so.

## Possible false alarms

A finding is a possible false alarm when the code it's in only runs if a guard has a value it never has on the target version. It stays visible, marked "possible false alarm" with the guard it depends on, but it doesn't count as an error or a warning, so it never decides the verdict. The analysis is in `src/Scanner/Guards/` and runs on PHP_CodeSniffer's tokens right after each file is scanned.

**What excuses a finding**
- **Version checks**, evaluated for every release of the target, 8.4.0 to 8.4.99: `PHP_VERSION_ID`, `PHP_MAJOR_VERSION`, `PHP_MINOR_VERSION`, `PHP_RELEASE_VERSION`, `version_compare()` with `PHP_VERSION` or `phpversion()`, comparisons, `!`, `&&`, `||`, `and`, `or`. A check that's true for some releases of the target excuses nothing.
- **Existence checks of the very symbol a finding says PHP removed:** `function_exists( 'each' )` or `is_callable( 'each' )` around `each()`, `class_exists()` or `interface_exists()` around a removed class, `defined()` around a removed constant. A check of anything else is unknown, so `if ( ! function_exists( 'foo' ) ) { function foo() { … } }` never excuses the code inside it: declaration guards and polyfills stay findings. Deprecated functions still exist, so checks for them excuse nothing either.

**Where guards are found:** enclosing `if`, `elseif` and `else` branches (braces, alternative syntax or none), `while` bodies, earlier operands of `&&`, `||`, `and`, `or` and ternaries, also inside an `if` condition, and early exits from the same block, such as `if ( ! function_exists( 'x' ) ) { return; }`. Anything unrecognized, like a variable or a call to another function, is unknown, and unknown never excuses.

**Never excused:** findings from sniffs about code PHP rejects or warns about when it compiles the file, such as `$string{0}`, `(real)` casts, nested ternaries without parentheses or `${var}` in strings. Only the 15 sniffs about code as it runs can be excused (`FalseAlarms::RUNTIME_SNIFFS`).

**Results:** on WordPress core for PHP 8.5, findings went from 3 errors and 44 warnings to 0 errors, 11 warnings and 36 possible false alarms, with the guard checked by hand for each. In the end-to-end test, the plugin bundling getID3 is now Ready. The analysis adds no measurable scan time.

**Not handled yet:** guards in another function or stored in a variable, `for`, `foreach` and `switch`, `else` without braces, and guards inside arrow functions or square brackets. These stay findings.

## Milestone 3 results

`bin/build-release` builds the release zip, `build/upgrade-radar.zip`: 1.6 MB, 8.0 MB unzipped, 827 files. WordPress.org takes up to 10 MB.

**What's in the zip:** the plugin's own files, `composer.json` (reviewers look there to see where `vendor/` comes from), and the production dependencies without their tests, docs and scripts. Not `tests/` (the fixtures are PHP that's broken on purpose), `bin/`, `docker/`, `tools/`, `.github/`, `.wordpress-org/`, the docs or the dev config. `src/Scanner/Cli.php` stays out too: only `bin/upgrade-radar` uses it.

**Prefixing:** PHP-Scoper prefixes PHP_CodeSniffer, PHPCSUtils and PHPCompatibility with `UpgradeRadarVendor\`, as milestone 1 tried. It reprints every file it prefixes. That's fine for the dependencies but not for our code: it dropped our coding standard and moved `phpcs:ignore` comments to other lines, where Plugin Check no longer saw them. So our code skips PHP-Scoper: `bin/prefix-scanner.php` prefixes the 10 names in `src/Scanner` that refer to the dependencies (9 imports and a class name in a string) and stops on any namespace it doesn't know. The rest of `src/` goes in as it is. A build that ran all of `src/` through PHP-Scoper, tried in parallel, showed why: it prefixed the `WP_Theme` type hint in `Jobs\Inventory` (a fatal error once a scan starts) and the `'get_plugins'` strings in `function_exists()` checks, so every WordPress name had to be listed as an exception. Its reprint also moved comments: the `phpcs:ignore` ones left 13 Plugin Check warnings, and moving the translators comments to fit it failed our lint. A Composer classmap autoloads the prefixed classes.

**Checks on the zip**
- **Fixtures:** the built plugin gives the same findings as the development copy in all 40 fixture scans, on PHP 7.4 and 8.3, also with another plugin's unprefixed PHP_CodeSniffer loaded first.
- **Release check** (`tests/release/run.sh`): installs the zip with WP-CLI on a fresh WordPress, as a user would, sets up the end-to-end site and runs its full scan for PHP 8.4: 10 requests, the slowest 15.4 s, 135 s in all, with every verdict as expected. CI runs it on pull requests.
- **Plugin Check 2.1.0** (`tests/release/plugin-check.sh`, cloned from GitHub; CI runs it after the release check): the first run found 33 errors and 4 warnings. Now it finds nothing.
  - **22 errors: exception messages in the engine aren't escaped** (12 `throw`s). The engine runs without WordPress, so it can't call `esc_html()`. Its messages are plain text, and the WordPress code escapes them where it shows them, as milestone 1 planned: `esc_html()`, or `textContent` in the page's script. Each `throw` says so in a `phpcs:ignore` comment. `composer lint` used to skip these sniffs for the whole engine. Now it only skips `bin/` and the command-line class, so CI catches a new one before Plugin Check would.
  - **11 errors in `src/Scanner/Cli.php`** (`fwrite()` and exception messages): it's left out of the zip.
  - **3 warnings about the error handler around PHP_CodeSniffer** (`set_error_handler()`, and `error_reporting()`, read to respect `@`). They had `phpcs:ignore` comments, which PHP-Scoper had moved. The comment now names Plugin Check's own sniff for `error_reporting()` too.
  - **1 warning: no `composer.json` next to `vendor/`.** The zip has ours now.

  Installing Plugin Check makes WordPress forget its update data, so it runs after the scan.

**GitHub releases:** pushing a version tag runs `.github/workflows/release.yml`. It checks the tag matches the plugin's version, runs the release check and Plugin Check on a fresh build, and publishes that zip as the tag's release, with the readme's changelog entry as its notes.

**readme.txt:** description, installation, FAQ, screenshots and changelog, tested with WordPress 7.1.4. The FAQ says plainly that results can include false alarms, how possible false alarms are marked, and which PHP changes PHPCompatibility 10.0.0-alpha2 can't detect yet.

**Assets** (`.wordpress-org/`, for the `assets` folder in the plugin's SVN): an original icon (256 and 128 px) and banner (1544×500 and 772×250), drawn as SVG (`source/`) and rendered to PNG with Chromium. The 3 screenshots come from the release check site: the verdicts for PHP 8.4, one plugin's problems with its possible false alarms, and a scan in progress.

## Numbers that decide what happens next

- **Stop** if there are fewer than 200 active installs 60 days after approval.
- **Build the paid service** at 500+ active installs, or when 3+ users ask for an AI or agency feature.
- **Time cap after launch:** about 2 hours a week for support and updates.

## Risks

- **The PHP 8.x rules are still alpha.** The newest release is PHPCompatibility 10.0.0-alpha2 (Nov 2025), though its `develop` branch is active (last commit Oct 28, 2026). Expect some false alarms: pin versions, label results honestly, and test against popular plugins. Its `develop` branch now requires PHP_CodeSniffer 4, so the next upgrade means moving off 3.13, and moving our own lint (WPCS) to a separate install until WPCS supports 4.
- **Rivals may ship faster.** Speed and the "what to do" angle are the defense.
- **WordPress.org's review wait** delays launch.
- **Support load** from non-technical site owners.

## Sources

- [WP Engine PHP Compatibility Checker](https://wordpress.org/plugins/php-compatibility-checker/)
- [BrightLeaf Digital PHP Compatibility Scanner](https://wordpress.org/plugins/brightleaf-digital-php-compatibility-scanner/)
- [Eli's PHP Compatibility Scanner](https://wordpress.org/plugins/eli-php-compatibility-scanner/)
- [Acquia: PHP 8.2 end-of-life FAQ](https://docs.acquia.com/acquia-cloud-platform/end-life-php-82-favor-php-84-faq)
- [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) · [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP)

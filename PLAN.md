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
- Admin screen: choose a target PHP version (8.1–8.5) and scan the active plugins and the active theme.
- Engine: PHP_CodeSniffer + PHPCompatibilityWP `3.0.0-alpha2` (pinned), run in-process, batched and resumable.
- Results: a verdict per item, error/warning counts, file:line details, an "update available" hint, and CSV export.
- If time allows: a WP-CLI command (`wp upgrade-radar scan --php=8.3`), which agencies use.

**Out until there's demand:** the cloud database, AI explanations, email alerts and the multi-site dashboard.

## Milestones

1. **Engine:** a WordPress-free scanner library plus a CLI, tested on fixture code with known PHP 8.1–8.4 breaks. **Done Oct 29**, see [Milestone 1 results](#milestone-1-results).
2. **Plugin:** admin screen, background batching and results UI; manual test in a Docker WordPress.
3. **Submit:** readme.txt and screenshots, a clean Plugin Check run, then submit to WordPress.org.
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
- **Bundled code that's guarded:** WordPress core still gets 4 errors and 14 warnings, for calls behind `function_exists()` or version checks, such as `get_magic_quotes_gpc()` in getID3. Plugins that bundle such libraries will show the same false alarms.
- **Release size:** the vendor directory without tests and docs is 9.7 MB, about 2 MB zipped. The release zip must leave out `tests/`, `bin/` and `docker/`: the fixtures are PHP that's broken on purpose, which Plugin Check and possibly WordPress.org's SVN checks would reject.

**Not detected by PHPCompatibility 10.0.0-alpha2:** passing null to non-nullable built-in parameters (8.1), dynamic properties (8.2), the `HTML-ENTITIES` mbstring encoding and callables such as `'self::method'` (8.2), and `++` on non-alphanumeric strings (8.3).

**Notes for milestone 2**
- **Batches:** save the `ScanJob` state in an option with autoload off, and pass a checkpoint callback that saves it the same way. Give each batch about half of `max_execution_time`, at most 15 s.
- **Requests:** run batches in our own AJAX or REST requests. WP-Cron runs every due event in one request, so a rival scanner's event could load its PHP_CodeSniffer first. In that case `ScannerUnavailable` is temporary: retry in a later request.
- **Item roots:** take them from WordPress (`get_plugins()`, the active theme) and check they're inside the plugins or themes directory.
- **Output:** engine messages are plain text, so escape them on output.
- **Verdicts:** errors decide them. Warnings show as "deprecated, still works" and don't block "Ready".

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

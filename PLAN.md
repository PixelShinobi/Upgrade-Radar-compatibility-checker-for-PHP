# Upgrade Radar: Plan

_Upgrade Radar – compatibility checker for PHP. Last updated Oct 28, 2026._

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

1. **Engine:** a WordPress-free scanner library plus a CLI, tested on fixture code with known PHP 8.1–8.4 breaks.
2. **Plugin:** admin screen, background batching and results UI; manual test in a Docker WordPress.
3. **Submit:** readme.txt and screenshots, a clean Plugin Check run, then submit to WordPress.org.
4. **Launch watch:** track installs, reviews and support threads.
5. **Paid service:** only if the numbers below say so.

**Target: ship v1 the weekend of Oct 31 – Nov 1, 2026.**

## Numbers that decide what happens next

- **Stop** if there are fewer than 200 active installs 60 days after approval.
- **Build the paid service** at 500+ active installs, or when 3+ users ask for an AI or agency feature.
- **Time cap after launch:** about 2 hours a week for support and updates.

## Risks

- **The PHP 8.x rules are still alpha.** The newest release is PHPCompatibility 10.0.0-alpha2 (Nov 2025), though its `develop` branch is active (last commit Oct 28, 2026). Expect some false alarms: pin versions, label results honestly, and test against popular plugins.
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

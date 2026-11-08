---
id: TASK-4
title: Stop the Laravel layer loading on unsupported Laravel versions
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-01 11:16'
updated_date: '2026-11-02 04:34'
labels:
  - v2-beta
dependencies: []
references:
  - composer.json
  - README.md
priority: low
type: enhancement
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The Laravel layer supports Laravel 12 and 13. Composer refuses every other Laravel version through a `conflict` on `laravel/framework` (`<12.0 || >=14.0`), because an app on an unsupported version can install and boot while its webhooks fail.

The first version of this task allowed Laravel 13 only. The owner approved widening it to 12: the suite passes there, the owner's `merchant` app is on Laravel 12, and Laravel 12's security support ends on 2027-02-24, so a later major version can drop it. The refusal stays at install, the self-disabling provider stays rejected, and the package is not split.

The core is framework-free and stays usable by non-Laravel hosts. A Laravel app on an unsupported version cannot use the core either, which is the accepted trade-off.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 What a Laravel 11 or older app, and a Laravel 14 app, gets on install is decided and recorded in `DESIGN-v2.md`
- [x] #2 Installing into an unsupported Laravel version fails clearly in Composer
- [x] #3 A non-Laravel install of the core still works and pulls in no Illuminate packages
- [x] #4 The README states the supported Laravel versions and how core-only use works on other versions
- [x] #5 Laravel 12 and 13 both install and boot from the archive, and Laravel 10, 11 and 14 are refused by Composer
- [x] #6 The commands of every CI matrix cell pass when run by hand: Laravel 12 with Testbench 10 and Laravel 13 with Testbench 11, on PHP 8.4 and 8.5
- [ ] #7 Laravel 12 and 13 are both tested in real CI, and the runs pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Widen the conflict to <12.0 || >=14.0 and allow Testbench ^10 || ^11.
2. Run the full suite, PHPStan, Pint and Rector on Laravel 12 and 13, PHP 8.4 and 8.5, from a clean copy of the tree, including the oldest Laravel 12 and 13 releases that can be installed.
3. Fix small compatibility problems, leaving Laravel 13 working; otherwise stop and report.
4. Add the Laravel dimension to CI, with coverage and the badge on one cell only.
5. Prove the install with fresh apps from an artifact zip at 2.0.0-beta.1 using the README command: Laravel 12 and 13 install; 10, 11 and a Laravel 14 stand-in are refused; core-only stays free of Illuminate.
6. Update DESIGN-v2.md Q22, README, UPGRADE and CHANGELOG, and check the Guzzle explanation for both versions.
7. Leave real CI unchecked and the task In Progress until it has run.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Pre-change, fresh apps on PHP 8.4.27 (package installed by path repo at explicit 2.0.0):
- Laravel 12.70.2: installs, about OK, webhook route and mib:* commands present. Package suite on testbench ^10: 580 passed, 32 skipped.
- Laravel 11.57.1 (Composer's advisory block had to be turned off in the scratch app to install it at all): installs, about OK, route and commands present, but the package suite has 49 failures, mostly ProcessNotification; Illuminate\Queue\Middleware\FailOnException is used by the job and tests get 503 at webhook delivery.
- Laravel 10.50.4 (same advisory override): composer require exits at post-autoload-dump, package stays installed, every artisan command dies with undefined method publishesMigrations(). Web requests still return 200 (checked with a real PHP server).

Decision: Composer conflict on laravel/framework <13.0 || >=14.0, recorded in DESIGN-v2.md section 12 Q22. Rejected: self-disabling provider, and a conflict on illuminate/support.

After the change, fresh apps on PHP 8.4.27:
- Laravel 12.70.2, 11.57.1, 10.50.4: composer require exits 2 with 'conflicts with laravel/framework <13.0 || >=14.0', Composer reverts composer.json and composer.lock, nothing in vendor, artisan about exits 0.
- Laravel 13.39.0: composer require with -W exits 0, about exits 0, route and mib:* commands present. Without -W it fails on Guzzle 8 in the app's lock, and the pre-change package fails identically.
- Stand-in laravel/framework packages: 12.99.0, 14.0.0 and 14.0.0-beta1 refused; 13.0.0 and 13.99.0 resolve.
- Core-only clean project: 11 packages, no illuminate or laravel; composer why illuminate/support and laravel/framework both report package not found. verifyCapture against a stub PSR-18 client returns Captured and AmountMismatch; NotificationVerifier accepts and rejects correctly.
- illuminate/support and illuminate/database ^12 alone: installs, core probe passes. The same host with a conflict on illuminate/support is refused.

Checks: pest 580 passed, 32 skipped; pint --test, phpstan, rector --dry-run and composer validate --strict all pass. composer validate needed the git-ignored local composer.lock hash refreshed with composer update --lock; package versions unchanged.

Widened to Laravel 12 on 2026-11-02, with owner approval. Evidence, all from clean copies of the working tree through a temp index.

Newest releases, CI commands by hand (composer validate, composer require with --dev --no-update, composer update, pint, rector, phpstan, pest):
- Laravel 12.70.2, Testbench 10.12.0: PHP 8.4.27 and 8.5.11, 727 passed, 50 skipped, all tools exit 0.
- Laravel 13.39.0, Testbench 11.3.0: the same.
- Laravel 13.0.0, Testbench 11.0.0, PHP 8.4.27, advisory block off: the same.
The 50 skips are the real MySQL and PostgreSQL store tests. They were not run for this task: no container was started, and nothing failed in a way that needed one.

Compatibility problem found on older 12: Illuminate FailOnException arrived in 12.19.0 (checked on GitHub: absent in 12.18.0, present in 12.19.0). On 12.18.0 the suite had 94 failures with webhook deliveries getting 503, the same failure that made Laravel 11 unsafe. ProcessNotification now has the same middleware written out as a closure, so the constraint stays <12.0 as approved. It stays a middleware, so it still covers failures while the job handle dependencies resolve.
After the change, with phpunit 12.3, symfony/console 7.3 and the advisory block off: 12.18.0 and 12.8.0 give 491 passed, 285 risky, 1 failed, 3191 assertions, the same count as a full run. The failure moves between tests and is Testbench cleanup calling PHPUnit ErrorHandler::enable() with too few arguments; 12.19.0 gave the same before the change. Composer could not resolve Pest 4 with Testbench on 12.1.1 or 12.3.0, so below 12.8 the suite did not run. Real app on 12.0.0 instead (symfony/console 7.2): about, route, commands, publish, migrate, webhook POST 200 and processed, malformed job failed on its first attempt, component rendered. Plain PHPStan level 2 of src against 12.0.0 reports nothing beyond 13 except PHPUnit, which that copy did not install.

Install, from a zip at 2.0.0-beta.1 built with git archive, README command, fresh apps:
- Laravel 12.70.2 and 13.39.0, PHP 8.4.27 and 8.5.11: exit 0; artisan about, POST mib/webhook, mib:reconcile and mib:prune-notifications, both vendor:publish tags and migrate all pass. A probe through the HTTP kernel: webhook 200 and Processed, wrong secret 401, malformed job in failed_jobs on attempt 1 and marked Failed, Blade::render of the checkout component.
- Laravel 11.57.1 and 10.50.4, PHP 8.4.27, advisory block off in the scratch app only: exit 2, conflicts with laravel/framework <12.0 || >=14.0, package not installed, composer.json and lock reverted, about still exits 0.
- Laravel 14 is not published (newest is 13.39.0, plus dev-master), so stand-in laravel/framework packages were used, advisory block off: 11.99.0, 14.0.0-beta1 and 14.0.0 exit 2 with the conflict; 12.0.0, 12.70.0, 13.0.0 and 13.99.0 resolve. With the block on, Composer also refuses a stand-in 12.0.0 and 13.0.0 because of advisories, which is separate from this conflict.
- Core only: 11 packages, no illuminate or laravel; composer why reports both not found; scratch core probe passes with no framework loaded.

Guzzle differs: a fresh Laravel 12 app has Guzzle 7.16.1, and the install works with or without -W (dry run exit 0). A fresh Laravel 13 app has Guzzle 8.3.1: without -W exit 2, "fixed to 8.3.1 (lock file version)"; with -W it moves to 7.16.1. README updated to say so.

CI: the workflow YAML parses and expands to 4 cells, each with the right Testbench. Coverage and the badge are limited to PHP 8.4 with Laravel 13. It has not run in real CI, so #7 stays open and the task stays In Progress. Testing of older 12 releases is not in CI; CI uses the newest 12 and 13 only.

Repo checks: pest 727 passed, 50 skipped; pint --test, phpstan, rector --dry-run and composer validate --strict exit 0. composer update --lock changed only the content hash of the local lock.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Composer now installs the package on Laravel 12 and 13 and refuses every other Laravel version, through a conflict on laravel/framework <12.0 || >=14.0. Non-Laravel hosts are unaffected. Laravel 12 is allowed because the suite passes there, the owner's merchant app is on it, and its security support ends on 2027-02-24. ProcessNotification no longer uses FailOnException, which only exists from 12.19.0 and left webhooks failing on older 12. CI gains a Laravel dimension. Decision recorded in DESIGN-v2.md Q22; README, UPGRADE and CHANGELOG updated. Verified with the CI commands by hand on Laravel 12 and 13 on PHP 8.4 and 8.5 (727 passed, 50 skipped real-database tests), fresh apps from an archive, Laravel 10 and 11 refused, a stand-in for Laravel 14, and a core-only install. Not yet run in real CI.
<!-- SECTION:FINAL_SUMMARY:END -->

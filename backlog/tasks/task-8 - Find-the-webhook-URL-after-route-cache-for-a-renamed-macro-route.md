---
id: TASK-8
title: 'Find the webhook URL after route:cache for a renamed macro route'
status: Done
assignee:
  - '@claude'
created_date: '2026-11-02 04:14'
updated_date: '2026-11-02 04:49'
labels:
  - v2-beta
dependencies: []
references:
  - src/Laravel/MibGlobalPay.php
  - src/Laravel/Support/WebhookRoute.php
  - src/Laravel/MibGlobalPayServiceProvider.php
priority: high
type: bug
ordinal: 8000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`MibGlobalPay::webhookUrl()` (`src/Laravel/MibGlobalPay.php:90`) first uses the `Route` remembered by `Route::mibGlobalPayWebhook()`. When routes load from the cache, the macro never runs, so nothing is remembered. It then falls back to the default route name, and a host group that prefixes the name (`->name('student.')` gives `student.mib-global-pay.webhook`) does not match, so it returns null.

`newCheckoutRequest()` treats null as "no webhook" and silently leaves out the notification URL, so in production the bank is not told where to post, with no error. ISIMS, the first adopter, registers the route this way in `routes/student.php` and its deploy runs `artisan optimize`, so this would hit it. Reproduced earlier in a scratch test with a cached-route setup; that repro no longer exists.

The README, `CHANGELOG.md` and `DESIGN-v2.md` (the "Stage 7.1" note) currently say the route is found in this case. This must be fixed before tagging `v2.0.0-beta.1`.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 After `route:cache`, `webhookUrl()` returns the correct absolute URL for a macro route with a name prefix, and for one on a host group domain
- [x] #2 `newCheckoutRequest()` includes the notification URL after `route:cache`, the same as without it
- [x] #3 A test loads routes from a real route cache file, and fails without the fix
- [x] #4 Hosts with no webhook route registered still get null, and the auto-registered route still works cached and uncached
- [x] #5 Two macro routes, or a route with the webhook disabled, have behaviour that is decided, tested and documented
- [x] #6 The README, `CHANGELOG.md` and `DESIGN-v2.md` match the tested behaviour
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Reproduce with a real route cache in Testbench and in fresh Laravel 12 and 13 apps.
2. Have the macro number the route in its action array, which compilation keeps; find the highest number among POST routes, else the route with the package name.
3. Compare cached and uncached output for each host setup, and decide two routes and disabled.
4. Update README, CHANGELOG and DESIGN-v2, then run all checks on both Laravel versions.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Reproduced on Laravel 13.39.0 and 12.70.2 fresh apps with the ISIMS route shape after artisan optimize: webhookUrl null and toBody() throws InvalidRequest. With auto-registration left on, the URL is silently wrong (/mib/webhook).
Laravel 13 lists domain routes first in getRoutes() and the cache file, so route order cannot give last-registered; the macro numbers routes instead. Mutating the lookup to route order fails 2 tests.
Lookup measured with 1339 cached routes: 0.78ms first, then 0.09ms on Laravel 13; about 2.2ms on Laravel 12. No memo added.
Decided: last registered wins, cached or not; same-name routes make route:cache throw (Laravel behaviour); disabled keeps today's behaviour (no auto route so null; macro route keeps its URL and answers 404).
New tests: 16 of 29 fail without the fix on both versions.
Not fixed, reported: on Laravel 13 a macro route in the web group answers 419 with or without the cache, because the macro does not exclude PreventRequestForgery.

Correction to the description: with no route, toBody() throws InvalidRequest rather than leaving the URL out silently. The silent case was auto-registration on, where it returned the wrong URL. Both are fixed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
webhookUrl() now finds macro routes after route:cache by a number the macro stores in the route's action array, which compilation keeps. Removed WebhookRoute::remember(). Verified with tests/Laravel/WebhookRouteCacheTest.php (real route:cache command, 16 of 29 fail without the fix) and fresh Laravel 12 and 13 apps after artisan optimize, whose output is byte-identical to uncached. Suite 756 passed, 50 skipped on both versions; pint, phpstan, rector and composer validate pass.
<!-- SECTION:FINAL_SUMMARY:END -->

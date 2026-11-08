---
id: TASK-15
title: Allow Guzzle 8 so the package installs on a fresh Laravel 13 app
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 11:39'
updated_date: '2026-11-08 11:42'
labels:
  - dependencies
dependencies: []
priority: high
type: bug
ordinal: 15017
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
QA (TASK-14.1) found that `composer require ibnnajjaar/mib-global-pay` fails on a fresh Laravel 13 app. Laravel 13 allows `guzzlehttp/guzzle ^7.8.2 || ^8.0` and a new app installs Guzzle 8.3.1, but the package requires `^7.9`, so Composer refuses unless the user downgrades Guzzle, guzzlehttp/promises and guzzlehttp/psr7 to older majors with -W. The package only uses `GuzzleHttp\Client` (through PSR-18 `sendRequest()`) and `GuzzleHttp\Psr7\HttpFactory`, so Guzzle 8 should work. The tests never caught it because the lock file pins Guzzle 7.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 composer.json accepts Guzzle 7.9+ and Guzzle 8
- [x] #2 The full suite, PHPStan, Pint and Rector pass with Guzzle 8 installed
- [x] #3 The full suite still passes with Guzzle 7 installed
- [x] #4 A fresh Laravel 13 app (mib-qa) installs the package with Guzzle 8 without -W, and a real call to the test gateway still works
- [x] #5 README requirements and the changelog mention Guzzle 8
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Change composer.json to "guzzlehttp/guzzle": "^7.9 || ^8.0"; update the lock (composer update guzzlehttp/guzzle -W) so it resolves to 8.
2. Run pest, phpstan, pint and rector on Guzzle 8.
3. Temporarily resolve Guzzle 7 (composer update with guzzlehttp/guzzle:^7.9), run pest, then restore the Guzzle 8 lock.
4. In ~/Herd/mib-qa, update to Guzzle 8 and confirm composer accepts it, then make one real Check Gateway call.
5. Update the README requirements, the MibGateway docblock that says "Guzzle 7", and the changelog.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
composer.json: guzzlehttp/guzzle ^7.9 || ^8.0; lock now on guzzle 8.3.1, promises 3.1.0, psr7 3.2.0.
Guzzle 8.3.1: pest 1038 passed (MySQL on, in lerd PHP 8.5 container), PHPStan max clean, Pint clean, Rector clean.
Guzzle 7.16.1 (lock switched temporarily with composer update --with guzzlehttp/guzzle:^7.9, then the Guzzle 8 lock restored): pest 1038 passed, PHPStan clean.
Fresh Laravel 13.40 app (throwaway ~/Herd/.mib-guzzle-check, removed): ships guzzle 8.3.1; composer require of the package by path succeeded with no -W and guzzle stayed 8.3.1.
mib-qa moved to guzzle 8.3.1; real checkGateway() reports operating and retrieveOrder(QA-INV-0001-1) reads CAPTURED 10.00.
Docs: README install command no longer needs --with-all-dependencies (that note was the documented workaround for this bug); README requirements, MibGateway docblock, DESIGN-v2 Guzzle note and CHANGELOG [Unreleased] Fixed updated.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Widened the Guzzle constraint to ^7.9 || ^8.0 so the package installs on a fresh Laravel 13 app without downgrading Guzzle. Verified on Guzzle 8.3.1 (suite, PHPStan, Pint, Rector) and 7.16.1 (suite, PHPStan), with a plain composer require into a fresh Laravel 13.40 app, and with real test-gateway calls from the QA app on Guzzle 8. Removed the --with-all-dependencies workaround from the README and recorded the fix in the changelog.
<!-- SECTION:FINAL_SUMMARY:END -->

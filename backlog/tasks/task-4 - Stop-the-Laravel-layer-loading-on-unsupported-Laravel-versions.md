---
id: TASK-4
title: Stop the Laravel layer loading on unsupported Laravel versions
status: To Do
assignee: []
created_date: '2026-11-01 11:16'
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
The Laravel layer targets Laravel 13, but `composer.json` only lists `illuminate/support` under `suggest` and has no `conflict` rule. A Laravel 12 or older app can install the package, and `extra.laravel.providers` then auto-discovers `MibGlobalPayServiceProvider` there. Whether that breaks is untested.

The core is framework-free and should stay usable by non-Laravel hosts. A blanket `conflict` would also stop an older Laravel app using only the core, so that trade-off has to be decided as part of this task.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 What a Laravel 12 or older app gets on install is decided and recorded in `DESIGN-v2.md`
- [ ] #2 Installing into an unsupported Laravel version either fails clearly in Composer or leaves the app booting normally
- [ ] #3 A non-Laravel install of the core still works and pulls in no Illuminate packages
- [ ] #4 The README states the supported Laravel versions and how core-only use works on other versions
<!-- AC:END -->

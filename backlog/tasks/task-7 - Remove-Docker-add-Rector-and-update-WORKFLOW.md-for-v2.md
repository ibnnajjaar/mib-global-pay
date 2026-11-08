---
id: TASK-7
title: 'Remove Docker, add Rector and update WORKFLOW.md for v2'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-01 11:40'
updated_date: '2026-11-01 11:46'
labels:
  - v2-beta
dependencies: []
priority: medium
type: chore
ordinal: 7000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The Docker setup is no longer wanted: PHP comes from the local environment and CI. The package has no automated refactoring checks, which are worth having as tooling while the code is still changing before a beta. `WORKFLOW.md` still shows the 1.x flow, where the result indicator decides the outcome and the webhook marks orders paid, which v2 no longer does.

PHPStan with Larastan is already at `level: max` and runs in CI, so it needs no change. This Rector is development tooling, not the consumer upgrade set that `DESIGN-v2.md` §12 Q8 decided against.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Docker files and every reference to them are gone
- [ ] #2 Rector runs through a Composer script and is checked in CI
- [x] #3 Rector changes do not alter payment behaviour: tests, Pint and PHPStan all pass afterwards
- [x] #4 `WORKFLOW.md` matches the v2 code
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Remove the Docker files and references.
2. Add Rector with sets chosen for this package, review every proposed change to `src`, apply, verify.
3. Add Composer scripts and a CI step.
4. Rewrite WORKFLOW.md for v2.
PHPStan stays as it is: already level max with Larastan and enforced in CI.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Rector rules off, with reasons in `rector.php`: nullable instanceof and flip rules, static-to-non-static, nullsafe coalesce, arrow return types, `@var` removal (it deleted an annotation PHPStan needs in `SessionUpdate::toBody()`). Tests are not processed.

Verified locally on PHP 8.5: 504 tests pass, Pint clean, PHPStan max clean, `rector --dry-run` clean. AC 2 stays open until the new CI step has run on a push; PHP 8.4 also not run locally.
<!-- SECTION:NOTES:END -->

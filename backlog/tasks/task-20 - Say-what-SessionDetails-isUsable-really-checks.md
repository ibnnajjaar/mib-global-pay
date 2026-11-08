---
id: TASK-20
title: 'Say what SessionDetails::isUsable() really checks'
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 14:54'
updated_date: '2026-11-08 14:55'
labels:
  - qa
  - docs
dependencies: []
priority: low
type: docs
ordinal: 20017
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Found in TASK-14.17 (2026-11-08): a session created with withTimeout(600) still retrieved 11 minutes later with updateStatus SUCCESS, so isUsable() returned true, and only interaction.timeout ('0') showed the timeout had elapsed. isUsable() only checks updateStatus. Owner agreed to leave the behaviour and fix the docblock, since nothing in the package calls it and the timeout behaviour was seen once.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The isUsable() docblock says it reports only whether the last session update succeeded, not whether the payer can still use the session, and mentions interaction.timeout
- [x] #2 No behaviour change; the suite and static analysis pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Rewrite the isUsable() docblock. 2. Run checks.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Docblock added to SessionDetails::isUsable(): it reports only that session.updateStatus is SUCCESS, not whether the payer can still use the session, and notes the observed interaction.timeout '0'. No code change. Full suite 1058 passed; Pint, PHPStan, Rector clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Documented that SessionDetails::isUsable() only reflects the last session update, not session expiry; no behaviour change. Checks pass.
<!-- SECTION:FINAL_SUMMARY:END -->

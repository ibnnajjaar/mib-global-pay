---
id: TASK-18
title: Remove the unused OperationNotEnabled exception
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 14:12'
updated_date: '2026-11-08 14:16'
labels:
  - qa
  - cleanup
dependencies: []
priority: low
type: chore
ordinal: 18017
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Found in TASK-14.9 (2026-11-08). Core\Exceptions\OperationNotEnabled is declared, documented in DESIGN-v2.md and listed in the exceptions test, but nothing throws it. A disabled operation actually arrives as GatewayException HTTP 400 with error.field interaction.operation, validationType INVALID and explanation 'Value AUTHORIZE is invalid. Merchant TESTIUMMPGS is not enabled for AUTHORIZE., ...'. Owner asked to remove the dead class and get a proposal for handling the case separately (not part of this task).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 OperationNotEnabled.php is deleted and no code, test or doc refers to it
- [x] #2 The full test suite and static analysis pass
- [x] #3 CHANGELOG notes the removal
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Delete src/Core/Exceptions/OperationNotEnabled.php.
2. Remove it from tests/Core/ExceptionsTest.php and DESIGN-v2.md (exception list), and any README/UPGRADE mention.
3. CHANGELOG entry; full checks.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Deleted src/Core/Exceptions/OperationNotEnabled.php; removed it from tests/Core/ExceptionsTest.php and the DESIGN-v2.md exception list; the DESIGN Capabilities idea is now marked planned and not built. CHANGELOG 'Removed' entry. No references left in src, tests, README, UPGRADE or docs (the remaining mentions are the historical QA notes in TASK-14/14.9). Full suite 1050 passed (one fewer dataset row); Pint, PHPStan, Rector clean. Handling proposal for disabled operations given to the owner separately.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Removed the unused OperationNotEnabled exception and its test and design references; CHANGELOG notes it. Verified with the full suite (1050 passed), Pint, PHPStan and Rector.
<!-- SECTION:FINAL_SUMMARY:END -->

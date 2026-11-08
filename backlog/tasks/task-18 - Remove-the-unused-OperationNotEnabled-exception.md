---
id: TASK-18
title: Remove the unused OperationNotEnabled exception
status: To Do
assignee:
  - '@claude'
created_date: '2026-11-08 14:12'
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
- [ ] #1 OperationNotEnabled.php is deleted and no code, test or doc refers to it
- [ ] #2 The full test suite and static analysis pass
- [ ] #3 CHANGELOG notes the removal
<!-- AC:END -->

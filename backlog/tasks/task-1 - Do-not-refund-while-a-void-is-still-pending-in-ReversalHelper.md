---
id: TASK-1
title: Do not refund while a void is still pending in ReversalHelper
status: To Do
assignee: []
created_date: '2026-11-01 11:16'
labels:
  - v2-beta
dependencies: []
references:
  - src/Core/Reversal/ReversalHelper.php
  - src/Laravel/Jobs/ReverseCapture.php
priority: low
type: bug
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`ReversalHelper::reverse()` (`src/Core/Reversal/ReversalHelper.php`, from line 55) sends a refund whenever the void response is not approved. That includes `PENDING` and `UNKNOWN` results, where the void may still succeed, so the payer could be reversed twice unless the gateway blocks it. Whether the gateway blocks it is unverified.

Reproduced with a mocked gateway: a `PENDING` void produced retrieve, void and refund requests. This is reached through direct calls and through the opt-in auto-reversal (`MIB_AUTO_REVERSE_DOUBLE_CAPTURE`, job `ReverseCapture`). The existing refund fallback for a definite decline or an HTTP 4xx rejection is intended and is covered by `tests/Core/Verification/ReversalHelperTest.php`.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A void with a pending or unknown result sends no refund
- [ ] #2 The outcome tells the caller the reversal is unconfirmed, and logging in `ReverseCapture` does not report it as reversed
- [ ] #3 A definitely declined or HTTP 4xx-rejected void still falls back to a refund
- [ ] #4 Resending with the same transaction IDs after a pending void cannot return the money twice
- [ ] #5 Tests in `ReversalHelperTest` cover pending and unknown void results
<!-- AC:END -->

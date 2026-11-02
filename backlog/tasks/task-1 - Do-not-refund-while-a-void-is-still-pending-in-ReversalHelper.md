---
id: TASK-1
title: Do not refund while a void is still pending in ReversalHelper
status: Done
assignee:
  - '@claude'
created_date: '2026-11-01 11:16'
updated_date: '2026-11-02 03:25'
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
- [x] #1 A void with a pending or unknown result sends no refund
- [x] #2 The outcome tells the caller the reversal is unconfirmed, and logging in `ReverseCapture` does not report it as reversed
- [x] #3 Tests in `ReversalHelperTest` cover pending and unknown void results
- [x] #4 No refund is sent while a void's outcome is unknown; a refund after a void rejection follows only a read showing the void absent or definitely failed; resends use the same stable IDs
- [x] #5 Refunds are guarded against resends: a refund the order already lists under the refund ID, or a full refund of this target, is reported and nothing is sent; a host's partial refund is not mistaken for this reversal
- [x] #6 The refund sends targetTransactionId
- [x] #7 ReverseCapture retries Unconfirmed by itself with backoff, then escalates with an error log and a ReversalUnconfirmed event
- [x] #8 A definitely declined void falls back to a refund; a void rejected with HTTP 400 does so only after a read shows it absent or definitely failed; any other 4xx is rethrown with no refund
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. UNSPECIFIED_FAILURE is inconclusive.
2. Only HTTP 400 on the void is a refund candidate: read the void by ID and re-read the order; refund only if the void is absent or definitely failed and the order is unchanged. Anything else is Unconfirmed; a failed read is rethrown. Other 4xx are rethrown.
3. Derive void and refund IDs from order and target (TransactionId::derive, ReversalIds, ReversalHelper::reverseWithDerivedIds). A failed void is not replayed; the refund follows. A failed refund is final: Refund, reversed=false.
4. Guard refunds: our refund ID always matches; another ID only if it targets this capture and covers all of it. Any unsettled void or refund on the order blocks sending.
5. Send targetTransactionId on the refund.
6. ReverseCapture: own tries/backoff, release on Unconfirmed, then error log and ReversalUnconfirmed; failed() escalates exceptions; a failed refund gives ReversalFailed.
7. Fix docblocks, README, CHANGELOG, DESIGN-v2. Append MIB questions.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Validation: pest 523 passed (baseline 504), pint --test passed, phpstan no errors, rector --dry-run clean.

Resend trace before the fix: pending void, then GET order, VOID void-1 again; a 4xx duplicate rejection reached the refund. Same path when the void had since been approved and totalCaptured was unchanged. With the guard off, 4 of the resend tests fail (Refund returned, or an unexpected request).

Now: the order is read first. Void listed as unsettled: only the GET goes out, outcome Unconfirmed. Listed as approved: only the GET, outcome Void. Listed as definitely declined: VOID then one REFUND.

AC #4 left open: the guard needs Retrieve Order to list the pending void. CaptureVerifier makes the same assumption but neither is verified against MIB. If the void is not listed and a resent VOID gets a 4xx, the requests are identical to a first-time 4xx rejection (AC #3), so the refund is sent and the helper, being stateless, cannot tell them apart. Needs either MIB confirming the listing, or a decision on a caller-supplied resend signal.

Owner-approved redesign, 2026-11-02. This replaces the earlier 'AC #4 left open' note: the resend guard no longer depends on Retrieve Order listing the void, because a void rejection now needs a read before any refund, and IDs are stable.

Validation: pest 648 passed, 32 skipped (baseline 580/32); pint --test passed; phpstan no errors; rector --dry-run clean; composer validate --strict valid. Removing the covering code made tests fail in 18 of 18 checks, including UNSPECIFIED_FAILURE (5 failed) and the 4xx narrowing (10 failed). Job retries and escalation run through a real database queue with queue:work --tries=1, so they come from the job itself.

Gateway behaviour is documented (MIB docs, API v100), not observed. Open sandbox checks for the owner, not criteria: resend an identical void; same ID with a different body; refund after a void; retrieve an unknown transaction ID.

Criterion #3 is unchecked: its wording ('HTTP 4xx-rejected void still falls back') contradicts the approved narrowing, so it is no longer true as written. Status stays In Progress until the owner rewords or removes it.

AC reworded after the owner approved narrowing the refund fallback to HTTP 400. Proved by ReversalHelperTest: 'refunds the refundable amount when the void is declined', 'refunds after an HTTP 400 on the void only once a read shows...' and 'does not fall back to a refund on any 4xx except 400'.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A void whose result is unknown, including UNSPECIFIED_FAILURE, no longer triggers a refund: it returns Unconfirmed. Only a definite decline, or an HTTP 400 confirmed by reading the void back, can refund. IDs are derived from order and capture via reverseWithDerivedIds(), refunds are guarded against resends and name their target, and ReverseCapture retries Unconfirmed for about 45 minutes, then escalates with ReversalUnconfirmed or ReversalFailed. Verified with 648 passing tests, mutation checks, and a real database queue with --tries=1. Gateway behaviour is documented, not observed: the sandbox checks in the notes are still open.
<!-- SECTION:FINAL_SUMMARY:END -->

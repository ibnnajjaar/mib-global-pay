---
id: TASK-16
title: Recognise the gateway's unknown-transaction error
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 14:12'
updated_date: '2026-11-08 14:14'
labels:
  - qa
  - verification
dependencies: []
priority: high
type: bug
ordinal: 16017
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Found in TASK-14.3 against the MIB test gateway (2026-11-08). Retrieve Transaction for a transaction that does not exist answers HTTP 400 {"error":{"cause":"INVALID_REQUEST","explanation":"Unable to find order QA-INV-0001-1 and transaction nope-1 for merchant TESTIUMMPGS"},"result":"ERROR"}, with the same wording whether the order or only the transaction is missing. CaptureVerifier::isTransactionNotFound() only accepts wording that starts with 'transaction', so it rejects this, and ReversalHelper::readVoid() rethrows instead of treating the void as absent. isOrderNotFound() accepts the same text although it names a transaction; harmless today because it is only applied to Retrieve Order errors. Owner approved the fix.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 isTransactionNotFound() accepts 'Unable to find order <id> and transaction <id> for merchant <m>' with HTTP 400 and cause INVALID_REQUEST
- [x] #2 isOrderNotFound() no longer accepts the order-and-transaction wording, and still accepts 'Unable to find order <id> for merchant <m>'
- [x] #3 The existing not-found rule tests still pass, with the real gateway wording added as cases
- [x] #4 DESIGN-v2.md §4.2 records the observed responses
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. In CaptureVerifier::saysMissing(), accept the gateway's 'Unable to find order <id> and transaction <id> ...' wording for the transaction subject, and reject it for the order subject.
2. Add the observed gateway bodies to tests/Core/Verification/OrderNotFoundRuleTest.php (order: accepted; order-and-transaction: transaction accepted, order rejected).
3. Update the docblocks and DESIGN-v2.md §4.2 with the observed responses; CHANGELOG entry.
4. Run the suite and static analysis.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
CaptureVerifier::saysMissing() first checks for 'Unable to find order <id> and transaction ...' and counts it only for the transaction subject; everything else is unchanged. Tests: OrderNotFoundRuleTest gains the observed unknown-order body (NotFound), the order-and-transaction body as a not-NotFound case for Retrieve Order, and four isTransactionNotFound cases (observed text accepted; 'order X and transaction.reference', the plain unknown-order text and a field-level variant rejected). ReversalHelperTest's void-absent dataset gains the observed body. The new cases fail on the old rule (4 failures) and pass now. Full suite 1047 passed; Pint, PHPStan and Rector clean. DESIGN-v2.md (status list and §4.2) and CHANGELOG updated.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
isTransactionNotFound() now accepts the MIB gateway's real unknown-transaction text 'Unable to find order X and transaction Y for merchant M' (HTTP 400, INVALID_REQUEST), so ReversalHelper can treat an absent void as absent; isOrderNotFound() no longer matches that text. Verified with new rule and ReversalHelper cases that fail before the change, the full suite (1047 passed), Pint, PHPStan and Rector.
<!-- SECTION:FINAL_SUMMARY:END -->

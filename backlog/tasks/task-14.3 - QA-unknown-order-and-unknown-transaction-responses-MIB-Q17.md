---
id: TASK-14.3
title: 'QA: unknown order and unknown transaction responses (MIB Q17)'
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:12'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: high
type: task
ordinal: 14003
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
`CaptureVerifier` treats an order as `NotFound` only when the gateway answers in its documented error format (`result` `ERROR`, `error.cause` `INVALID_REQUEST`, no `error.field` or `error.validationType`, HTTP 404, or HTTP 400 saying the order is missing). `NotFound` lets `AttemptGuard` supersede an abandoned attempt and dispatches `PaymentFailed`. The real response has never been seen (MIB question 17; README "Verify"). If it does not match, payers with an abandoned attempt are blocked from paying again. This is the most important open question.

## Steps (tinker in the harness)
1. Using a raw Guzzle call, or `MibGlobalPay::gateway()` with a logger that logs response bodies (redacted), call Retrieve Order for an order ID that was never created, such as `QA-NEVER-` plus a random suffix. Record the HTTP status and the full JSON body (it has no secrets).
2. `MibGlobalPay::verifyCapture($sameId, Money::of('10.00', Currency::Mvr))`: record the returned `status`, or the exception class and message.
3. Retrieve Transaction for a real order from the connectivity task with a transaction ID that does not exist (`MibGlobalPay::retrieveTransaction($orderId, 'nope-1')`). Record the HTTP status and body, and whether `CaptureVerifier::isTransactionNotFound()` would accept it.
4. Retrieve Order for an order whose checkout session was created but never paid (from the connectivity task, step 4). Record status and body: does the gateway know an order that never had a payment attempt?

## Expected
- 2 returns `VerificationStatus::NotFound` (not an exception).
- If 2 throws `GatewayException` saying the response "was not recognised as a gateway error", that is a **finding**: record the exact body, because the §4.2 rule must change before release.
- 4 is recorded either way. It decides whether an abandoned attempt reads as `NotFound` or as an order with no transactions.

## Safety
- Test gateway and TEST merchant only. Never use a real card, and never point the harness at a production host.
- Never print, log, screenshot or write the API password or webhook secret into a task, note, GIF or chat. Refer to them by variable name.
- Do not trigger JavaScript alert/confirm dialogs in the browser; they block browser automation.

## Recording results
- Before starting, move this task to In Progress and read `backlog instructions task-execution`.
- For each step, record in Implementation Notes (`backlog task edit <id> --append-notes ...`): what you did, what you saw, the gateway order ID, and the screenshot or GIF file name.
- Record a GIF of each browser flow (Claude in Chrome `gif_creator`, with frames before and after each action) and name it after the task, for example `qa-03-payment-page-approved.gif`.
- A result that differs from "Expected" is a finding, not a failure of the task: record it with evidence and tell the owner. Do not change package code and do not create follow-up tasks without the owner's approval.
- Check an acceptance criterion only when you have observed it. Then read `backlog instructions task-finalization` before closing.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The HTTP status and JSON body for an unknown order are recorded verbatim in notes
- [x] #2 verifyCapture() on an unknown order gives NotFound, or the mismatch is recorded as a finding
- [x] #3 The unknown-transaction response is recorded, with whether isTransactionNotFound() accepts it
- [x] #4 What Retrieve Order returns for a session that was never paid is recorded
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Script qa-scripts/14.3-unknown-order.php: Retrieve Order on a never-created ID, verifyCapture on it, Retrieve Transaction with an unknown transaction ID on the paid order QA-INV-0001-1 (and on an unknown order), and Retrieve Order / verifyCapture on the unpaid session order QA-KX3HBO57O6VWY3PN from TASK-14.2.
2. Print HTTP status, raw body (gateway error bodies hold no secrets), and what CaptureVerifier::isOrderNotFound/isTransactionNotFound say.
3. Record verbatim; findings where the rule disagrees.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08 ~17:45 +05:00, script ~/Herd/mib-qa/qa-scripts/14.3-unknown-order.php (no browser, no GIF). Bodies verbatim:
1. Retrieve Order QA-NEVER-5634A48D (never created): HTTP 400, body {"error":{"cause":"INVALID_REQUEST","explanation":"Unable to find order QA-NEVER-5634A48D for merchant TESTIUMMPGS"},"result":"ERROR"}. No error.field, no validationType. isOrderNotFound=true. MIB Q17 answered: unknown order = HTTP 400 + 'Unable to find order <id> for merchant <merchant>'.
2. verifyCapture(QA-NEVER-5634A48D, 10.00 MVR): status not_found (VerificationStatus::NotFound), no exception. As expected.
3. Retrieve Transaction QA-INV-0001-1 / nope-1 (order exists, transaction does not): HTTP 400, body {"error":{"cause":"INVALID_REQUEST","explanation":"Unable to find order QA-INV-0001-1 and transaction nope-1 for merchant TESTIUMMPGS"},"result":"ERROR"}. isTransactionNotFound=FALSE, isOrderNotFound=true.
   3b. Same call on an unknown order: identical wording ('Unable to find order QA-NEVER-5634A48D and transaction nope-1 for merchant TESTIUMMPGS'), HTTP 400.
   FINDING: the gateway's unknown-transaction text starts 'Unable to find order <id> and transaction <id>', which the §4.2 / saysMissing() rule for 'transaction' does not match, so CaptureVerifier::isTransactionNotFound() never accepts the real response. Effect: ReversalHelper::readVoid() rethrows GatewayException instead of treating an absent void as absent, so reverseWithDerivedIds() fails closed (exception, no refund sent) in the case where the void left no transaction. Safe, but that recovery path cannot complete. Also the gateway cannot tell 'order missing' from 'transaction missing' in this message (same wording for both), and isOrderNotFound() matches this transaction message too (harmless today: it is only applied to Retrieve Order errors). Package code not changed; owner to decide.
4. Retrieve Order QA-KX3HBO57O6VWY3PN (session created in TASK-14.2, never paid): HTTP 400, body {"error":{"cause":"INVALID_REQUEST","explanation":"Unable to find order QA-KX3HBO57O6VWY3PN for merchant TESTIUMMPGS"},"result":"ERROR"}. The gateway does NOT know an order that only had a session. 4b. verifyCapture on it: not_found. So an abandoned attempt reads as NotFound (not as an order with no transactions), which lets AttemptGuard supersede it as designed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Answered MIB Q17 against the test gateway (qa-scripts/14.3-unknown-order.php): an unknown order is HTTP 400 INVALID_REQUEST 'Unable to find order <id> for merchant <m>', which isOrderNotFound() accepts, so verifyCapture() returns NotFound; an order with only an unpaid session is unknown to the gateway and also NotFound. Finding: an unknown transaction returns 'Unable to find order <id> and transaction <id> ...' (HTTP 400), which isTransactionNotFound() rejects, so ReversalHelper's absent-void path fails closed with a GatewayException.
<!-- SECTION:FINAL_SUMMARY:END -->

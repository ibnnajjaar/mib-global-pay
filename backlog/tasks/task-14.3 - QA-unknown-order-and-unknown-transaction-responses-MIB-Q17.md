---
id: TASK-14.3
title: 'QA: unknown order and unknown transaction responses (MIB Q17)'
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
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
- [ ] #1 The HTTP status and JSON body for an unknown order are recorded verbatim in notes
- [ ] #2 verifyCapture() on an unknown order gives NotFound, or the mismatch is recorded as a finding
- [ ] #3 The unknown-transaction response is recorded, with whether isTransactionNotFound() accepts it
- [ ] #4 What Retrieve Order returns for a session that was never paid is recorded
<!-- AC:END -->

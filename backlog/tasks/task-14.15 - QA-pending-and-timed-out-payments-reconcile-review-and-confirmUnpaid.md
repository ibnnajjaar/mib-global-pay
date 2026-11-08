---
id: TASK-14.15
title: 'QA: pending and timed-out payments, reconcile, review and confirmUnpaid'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 14:00'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: medium
type: task
ordinal: 14015
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
A `TIMED_OUT` or pending payment may still have reached the bank, so the package freezes the payable:
- `VerificationPending` refusal while it is unresolved;
- `mib:reconcile` keeps asking the gateway;
- `PaymentNeedsReview` fires after `MIB_REVIEW_AFTER_SECONDS`;
- `confirmUnpaid()` releases the payer.

Whether `TIMED_OUT` is final is an open MIB question.

## Steps
1. In the harness `.env`, set `MIB_REVIEW_AFTER_SECONDS=120`, then clear config.
2. Browser: create an invoice, start the payment, and pay with the timed-out test card (timed-out expiry). Record what the gateway page shows, and the return page text (expected: "We are still confirming your payment. Please check back shortly." or not completed).
3. Record the attempt state and Retrieve Order's status and gateway code for that order.
4. Open `{APP}/qa/invoices/{id}/pay`. Expect the refusal reason `verification_pending`, unless the order clearly failed. Record it.
5. Run `mib:reconcile` from the dashboard. Record the output and events.
6. Wait at least 120 s, then run `mib:reconcile` again. Expect exactly one `PaymentNeedsReview` event. Run it a third time: still only one.
7. Dashboard: "confirmUnpaid", with "confirmed by" set to `qa-agent` and a reason. Expect `confirmed_unpaid_at` and `confirmed_unpaid_by` to be set. If the gateway reports money moved, expect `AttemptNotUnpaid` and record it.
8. Open `{APP}/qa/invoices/{id}/pay` again. Expect a new attempt (`-2`), and pay it with the approved card.
9. Restore `MIB_REVIEW_AFTER_SECONDS` (remove it), then clear config.

## Expected
As listed. Record whether the gateway ever turned the timed-out order into a capture, which answers the TIMED_OUT question.

## Shared setup (from the parent task)
- The QA harness app from the "Build the QA harness app" task is running, with its queue worker, and you know its base URL (called `{APP}` below; the public tunnel URL if webhooks are needed).
- `{APP}/qa` is the harness dashboard. It shows invoices, their checkout attempts (`gateway_order_id`, `operation`, `state`, `settled_at`, `reversed_at`, `expires_at`), recorded package events and stored webhook notifications, each with a `data-testid` for the browser.
- Test cards: confirm them first on the gateway's test card page (see the parent task). Use those values; if they differ from the parent task's table, note it.

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
- [ ] #1 A timed-out payment shows the pending or not-completed text, and blocks a new attempt with verification_pending when unresolved
- [ ] #2 mib:reconcile dispatches PaymentNeedsReview exactly once after the review period
- [ ] #3 confirmUnpaid records who decided and allows a new attempt that can be paid
- [ ] #4 Whether the gateway treats TIMED_OUT as final is recorded
- [ ] #5 MIB_REVIEW_AFTER_SECONDS is restored
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08 ~18:24 +05:00. Steps 1-4 and 6 (TIMED_OUT card, verification_pending, PaymentNeedsReview after MIB_REVIEW_AFTER_SECONDS) need a card payment; blocked by the gateway's Hosted Checkout outage (TASK-14.5). MIB_REVIEW_AFTER_SECONDS was not changed.
Step 5 (partial): artisan mib:reconcile with four live, unpaid, unexpired attempts (QA-INV-0003-1, -0004-1, -0005-1, -0007-1): each row 'Verification not_found, Event -'; no events dispatched, states unchanged (still within expires_at).
Step 7: dashboard confirm_unpaid on live QA-INV-0003-1 (confirmed_by qa-agent, reason QA) -> 'confirmed unpaid'; attempt state failed, confirmed_unpaid_at 2026-11-08 13:24:10 UTC, confirmed_unpaid_by qa-agent, confirmed_unpaid_reason QA. No event recorded for it.
Step 7 negative: confirm_unpaid on QA-INV-0001-1 (captured, then voided) -> AttemptNotUnpaid 'The gateway reports this attempt as reversed, so it cannot be confirmed unpaid'.
Step 8 (partial): /qa/invoices/3/pay again -> 302 to /mib/pay/QA-INV-0003-2; a new attempt QA-INV-0003-2 (live) was created. Paying it is blocked by the outage.

19:00 +05:00 (14:00:04 UTC) mib:reconcile after the live attempts passed expires_at (which already includes the 300 s grace: QA-INV-0003-2 created 13:24:10, expires 13:59:10): QA-INV-0004-1, -0005-1, -0007-1 and -0003-2 -> Verification not_found, Event PaymentFailed (source reconcile, status not_found), state failed. QA-INV-0003-1 (confirmed unpaid earlier) listed again, not_found, no event. A second run listed only QA-INV-0003-1 and dispatched nothing; PaymentFailed count stays 4 (one per attempt). So abandoned sessions expire into PaymentFailed exactly once, which matches TASK-14.3 (the gateway does not know session-only orders).
<!-- SECTION:NOTES:END -->

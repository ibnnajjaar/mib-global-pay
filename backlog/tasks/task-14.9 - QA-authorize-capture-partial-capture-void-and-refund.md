---
id: TASK-14.9
title: 'QA: authorize, capture, partial capture, void and refund'
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: medium
type: task
ordinal: 14009
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
The follow-up operations (MIB guide docs/hosted-checkout/subsequentOperations.md) and their events: `PaymentAuthorized`, `PaymentPartiallyCaptured`, `PaymentCaptured`, `VoidProcessed`, `RefundProcessed` and `PaymentReversed`.

## Steps (browser, with the dashboard's action buttons)
1. Create an invoice for 100.00 MVR and open `{APP}/qa/invoices/{id}/pay?operation=authorize`. Pay with the approved card.
   - Expect the return page "Thank you. Your payment has been approved.", attempt `authorized`, and a `PaymentAuthorized` event.
2. Open `{APP}/qa/invoices/{id}/pay` again. Expect the refusal reason `authorization_held`.
3. Capture 40.00 (partial). Expect gateway result SUCCESS and a `PaymentPartiallyCaptured` event.
4. Capture the remaining 60.00. Expect `PaymentCaptured`, attempt `captured`, and the invoice paid.
5. Refund 10.00 (partial). Expect SUCCESS and `RefundProcessed`. Record whether the invoice stays paid.
6. On a second invoice paid by a normal purchase, click "void" straight after paying. Record whether the void succeeds (it does only if the payment has not gone to the acquirer yet) and which event appears.
7. On a third paid invoice, click "reverseWithDerivedIds". Record `ReversalOutcome.method` and `reversed`. Click it again and record that nothing new was sent (same outcome, and no new transaction in Retrieve Order).
8. On an authorized but uncaptured invoice (repeat step 1), void the authorization. Expect `VoidProcessed`, and that a new checkout is then allowed.

## Expected
As listed in each step. Every money event appears exactly once per transaction, each with a `deliveryKey`.

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
- [ ] #1 An AUTHORIZE checkout shows the approved text, records PaymentAuthorized and blocks a new attempt with authorization_held
- [ ] #2 Partial and final captures record PaymentPartiallyCaptured and then PaymentCaptured
- [ ] #3 A partial refund records RefundProcessed, and the effect on the invoice is recorded
- [ ] #4 The void and reverseWithDerivedIds outcomes are recorded, and a second reversal call sends nothing new
- [ ] #5 Voiding an authorization records VoidProcessed and allows a new checkout
<!-- AC:END -->

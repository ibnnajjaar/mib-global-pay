---
id: TASK-14.10
title: 'QA: card verification (VERIFY) checkout'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:22'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: low
type: task
ordinal: 14010
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
`InteractionOperation::Verify` checks a card without moving money, is the only operation that may send a zero amount, and dispatches `AccountVerified`.

## Steps (browser)
1. Create an invoice and open `{APP}/qa/invoices/{id}/pay?operation=verify`. Record what amount the gateway page shows.
2. Complete it with the approved card.
3. Record the return page text, attempt state and events, and Retrieve Order's totals (captured amount 0).
4. If Payment Options Inquiry (from the connectivity task) said VERIFY is not enabled, record the error the checkout gives instead.

## Expected
"Thank you. Your card has been verified.", attempt `verified`, an `AccountVerified` event, no `PaymentCaptured`, and nothing captured on the order.

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
- [ ] #1 A VERIFY checkout completes with the verified text and records AccountVerified
- [ ] #2 No money is captured on the order, and no PaymentCaptured is recorded
- [ ] #3 If VERIFY is not enabled, the gateway's error is recorded instead
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08 ~18:19 +05:00. /qa/invoices/7/pay?operation=verify (INV-0007, 10.00 MVR) -> 302 to /mib/pay/QA-INV-0007-1; attempt operation VERIFY, state live. Unlike AUTHORIZE, the gateway ACCEPTED a VERIFY session although Payment Options Inquiry lists only PURCHASE: Retrieve Session shows interaction.operation VERIFY, order.amount 10.00 MVR. Completing it in the browser is blocked by the gateway's Hosted Checkout outage (TASK-14.5). Kept In Progress.
<!-- SECTION:NOTES:END -->

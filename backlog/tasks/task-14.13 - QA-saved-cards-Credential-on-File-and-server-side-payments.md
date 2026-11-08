---
id: TASK-14.13
title: 'QA: saved cards (Credential on File) and server-side payments'
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 14:01'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: low
type: task
ordinal: 14013
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
`withSaveCard()`, `OrderResponse::token()`, the token client and the `pay()` / `authorize()` calls with `StoredPayment` are unverified, including where Retrieve Order puts the saved token (README "Beta status"). The merchant must be enabled for tokenization; if it is not, record the error and stop.

## Steps
1. Browser: create an invoice and open `{APP}/qa/invoices/{id}/pay?save_card=1`. Record whether the gateway page shows a save-card consent option, and take it. Pay with the Credential on File test card (4111111111111111, a future expiry, any CSC), or the approved card if that is rejected.
2. Tinker: `MibGlobalPay::retrieveOrder($orderId)->token()`. Record whether a token came back. Record the raw location of the token in the order JSON, with the card number masked.
3. Dashboard: "retrieve token". Record the masked card number and expiry it shows.
4. Tinker: `MibGlobalPay::pay(MibGlobalPay::randomOrderId(), TransactionId::generate('pay'), Money::of('5.00', Currency::Mvr), StoredPayment::payerInitiated($token))`. Record the result and gateway code.
5. If an agreement can be used (record if the gateway refuses): `authorize(...)` with `StoredPayment::merchantInitiated($token, $agreementId)` on a new order. Record the result.
6. Browser: a new invoice with checkout, passing the token through `withTokens()` (add a `token=` option to the harness pay route if needed). Record whether the gateway page offers the saved card.
7. Dashboard: "delete token". Then repeat step 4. Expect an error about an invalid token.

## Expected
A token is created and retrievable, a payer-initiated payment with it succeeds, and a deleted token is refused. Record any step the merchant profile does not allow.

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
- [ ] #1 Whether the Hosted Checkout save-card flow returns a token is recorded, with where Retrieve Order puts it
- [ ] #2 A server-side payer-initiated payment with the token succeeds, or the gateway's refusal is recorded
- [ ] #3 The merchant-initiated (agreement) payment result is recorded
- [ ] #4 A deleted token is refused
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
2026-11-08 19:01 +05:00: NOT RUN. Every step needs the gateway's Hosted Payment Page, which has shown 'Payment Page - System Error' ('Sorry, but we are unable to complete your payment', a new support code each time, e.g. GPH3YXDZLJGYG for QA-INV-0012-1) for every new session since 18:13 +05:00, including on the gateway's own /checkout/pay/<session> URL. Details in TASK-14.5. Resume when MIB confirms the test gateway's Hosted Checkout works. Server-side token steps (4, 5, 7) also need a token, which only the save-card checkout (step 1) produces. Also note TASK-14.2: the merchant lists only PURCHASE, so the merchant-initiated authorize in step 5 will likely be refused.
<!-- SECTION:NOTES:END -->

---
id: TASK-14.4
title: 'QA: Payment Page purchase with an approved card'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 11:37'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: high
type: task
ordinal: 14004
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
The main payer flow end to end: `startCheckout()` → signed `/mib/pay/{order}` → Checkout.js redirect to the gateway's Payment Page → payer pays → `/mib/return/{order}` → verification → events → webhook.

## Steps (browser)
1. Open `{APP}/qa`, create an invoice for 10.00 MVR, and note its row.
2. Open `{APP}/qa/invoices/{id}/pay`. Expect a brief "Redirecting to the payment page…" and then the gateway's Hosted Payment Page on the gateway host.
3. On the gateway page, check the merchant name "MIB QA" and the amount 10.00 MVR. Screenshot it.
4. Pay with the approved test card (approved expiry, CSC 100). Complete any 3-D Secure simulator step the page shows, choosing the successful outcome.
5. The browser returns to `{APP}/mib/return/{order}`. Record the text shown.
6. Back on `{APP}/qa`, check the attempt and the events.
7. Wait up to 2 minutes and reload: check whether a webhook notification row appeared.
8. Reload `{APP}/mib/return/{order}?resultIndicator=...` from history (the same URL a second time).

## Expected
- 5: "Thank you. Your payment was successful."
- 6: the attempt `state` is `captured`, with `settled_at` set. The invoice `paid_at` is set and `payableState` is Paid. `qa_events` has exactly one `PaymentCaptured` for that order, with a `deliveryKey`.
- 7: with the tunnel, a notification row exists for the order and no second `PaymentCaptured` appears (dedupe). Without the tunnel, note "not tested".
- 8: the same success text, and still only one `PaymentCaptured`.
- The gateway order ID looks like `QA-{invoice number}-1`.

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
- [x] #1 The payer reaches the gateway Payment Page showing the merchant name and amount
- [x] #2 After paying, the return page says the payment was successful
- [x] #3 The attempt is captured, the invoice is paid, and exactly one PaymentCaptured was recorded
- [ ] #4 Reloading the return URL and the webhook arriving do not dispatch PaymentCaptured again
- [x] #5 A GIF of the flow is saved and named in notes
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Run the steps in the description in Chrome against the mib-qa harness through the tunnel, with Mastercard 5123450000000008, expiry 01/39, CSC 100. Use invoice INV-0001 (10.00 MVR), whose attempt QA-INV-0001-1 was started from tinker while testing the harness.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Steps 1-2: dashboard via tunnel showed INV-0001 (10.00 MVR, payable state frozen) with live attempt QA-INV-0001-1. Clicking Pay reused the live attempt and Chrome landed on https://test-mib.mtf.gateway.mastercard.com/checkout/pay/SESSION...?checkoutVersion=1.0.0 with tab title "MIB QA" (merchant name). BLOCKED at step 3: the Claude in Chrome extension has no site permission for test-mib.mtf.gateway.mastercard.com, so the page cannot be read or used. Waiting for the owner.

Run 2026-11-08 (Chrome, invoice INV-0001, order QA-INV-0001-1, Mastercard 5123450000000008 01/39 CSC 100):
- Step 3: gateway Hosted Payment Page showed header "MIB QA", order summary INV-0001, total "MVR Rf10.00", a 30-minute timer, cardholder name, card number, expiry (two separate MM and YY boxes in the gateway's frames), CSC and optional billing address. Footer "Powered by MIB Global Pay".
- Step 4: after Pay, the gateway showed its "ACS Emulator for 3DS V2"; chose "(Y) Authentication/Account Verification Successful". So 3-D Secure is on for this test merchant by default.
- Step 5: returned to /mib/return/QA-INV-0001-1?resultIndicator=...&sessionVersion=...&checkoutVersion=1.0.0 showing "Thank you. Your payment was successful."
- Step 6: attempt captured, settled_at set, settled_transaction_id 1, settled 1000 minor; invoice paid_at set, payableState paid; exactly one PaymentCaptured (source return_url, deliveryKey present). Retrieve Order: status CAPTURED, amount 10.00, captured 10.00; transactions AUTHENTICATION SUCCESS APPROVED trans-60 and PAYMENT SUCCESS APPROVED 1.
- Step 7: the gateway sent 2 webhooks at 16:36:33 +05:00 (about 1 min after payment): one for the AUTHENTICATION transaction (trans-60) and one for the PAYMENT (1). Both got HTTP 401: the X-Notification-Secret the gateway sent (32 chars) differs from MIB_WEBHOOK_SECRET in .env (32 chars, different hash). Package behaved correctly (rejects a wrong secret). FINDING (config, not code): the webhook secret provided does not match the merchant's notification secret. Dedupe through the webhook not yet observed.
- Step 8: reloading the return URL showed the success text again; still exactly one PaymentCaptured.
- GIF: qa-14.4-payment-page-approved.gif (browser downloads folder).
Waiting for the owner to supply the correct webhook secret; the gateway redelivers, so step 7 can be rechecked after the fix.
<!-- SECTION:NOTES:END -->

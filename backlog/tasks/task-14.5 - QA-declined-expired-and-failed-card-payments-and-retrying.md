---
id: TASK-14.5
title: 'QA: declined, expired and failed card payments, and retrying'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:16'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: medium
type: task
ordinal: 14005
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
Failure handling on the Hosted Payment Page and in the package: the payer is told it failed, no money event goes out, and the payer can try again (on the gateway page, or with a new attempt).

## Steps (browser)
1. Create an invoice (10.00 MVR) and open `{APP}/qa/invoices/{id}/pay`.
2. Pay with the declined test card (declined expiry). Record what the gateway page shows. It usually lets the payer retry on the same page.
3. On the same page, retry with the expired-card values. Record the message.
4. Finally pay with the approved card on the same page. Record the return page text and the attempt state.
5. Create a second invoice and pay with the declined card. If the gateway page offers a way back to the merchant (a cancel or return link), use it. Otherwise open `{APP}/mib/return/{order}` directly. Record the result page text.
6. For that second invoice, open `{APP}/qa/invoices/{id}/pay` again. Record whether the same attempt is reused (same gateway order ID) or a new one starts, and whether a refusal reason is shown.

## Expected
- 2-3: the gateway shows a decline or expired message and stays on its page. No `PaymentCaptured`.
- 4: success text and a captured attempt, so retries on one session work. Exactly one `PaymentCaptured`.
- 5: "Your payment was not completed." or the pending text. `PaymentFailed` may be recorded. No `PaymentCaptured`.
- 6: no refusal page. Either the live attempt is reused or a new attempt (`-2`) starts, and the old one becomes `superseded` or `failed`. Record which.

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
- [ ] #1 Declined and expired cards show gateway error messages, and no money event is recorded
- [ ] #2 A successful retry on the same Payment Page captures exactly once
- [ ] #3 After a declined payment, the payer can start paying again, and what happens to the old attempt is recorded
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
2026-11-08 18:13-18:25 +05:00 BLOCKED by the test gateway: every new Hosted Checkout session fails before the card form. Our page and the gateway's own page https://test-mib.mtf.gateway.mastercard.com/checkout/pay/<session> (tab title 'Payment Page - System Error') show 'Sorry, but we are unable to complete your payment. Please try again by refreshing the page. If the problem persists please contact your service provider.' with support codes DZLVFRPTCEJDF, 2UN2BBH264QDE, LTFYXHC4O5SZF, WSW76BJDYRXWH (embedded mode), MWI4FAWJZZAHG and YFJUFTGEWP7CG (a minimal session from qa-scripts/minimal-session.php, no notificationUrl). Sessions are valid via the API (retrieveSession: updateStatus SUCCESS, amount 10.00 MVR). Console: 'ReferenceError: dialog is not defined' from checkout.min.js (Last-Modified 2026-10-29, unchanged). The same flow worked at 16:30 for QA-INV-0001-1, so this is a gateway-side fault, not the package. Attempts QA-INV-0003-1 and QA-INV-0004-1 are live and unpaid. Will retry later.
<!-- SECTION:NOTES:END -->

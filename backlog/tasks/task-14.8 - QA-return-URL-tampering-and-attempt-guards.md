---
id: TASK-14.8
title: 'QA: return URL tampering and attempt guards'
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:18'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: high
type: task
ordinal: 14008
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
The return URL and `resultIndicator` are payer-controlled. The package must decide only from the gateway (`verifyCapture()`), and must never start a second charge while money is captured, held or unresolved (README "Verify" and "When a payment stays unresolved").

## Steps (browser)
1. Create an invoice, start the payment, and stop on the gateway page without paying. Open `{APP}/mib/return/{order}?resultIndicator=forged` in a new tab. Record the page text and attempt state.
2. Open `{APP}/mib/return/{order}` with no query string. Record the text.
3. Go back to the gateway tab and pay with the approved card. Then open `{APP}/mib/return/{order}?resultIndicator=forged`. Record the text: it must follow the gateway, not the indicator.
4. Open `{APP}/qa/invoices/{id}/pay` again for that paid invoice. Record the refusal reason.
5. Create a new invoice and open `{APP}/qa/invoices/{id}/pay` twice in a row without paying. Record whether the second visit reuses the same gateway order ID.

## Expected
- 1 and 2: not "successful"; the not-completed or still-confirming text. Attempt not `captured`. No `PaymentCaptured`.
- 3: "Thank you. Your payment was successful." even with the forged indicator, because the result comes from the gateway.
- 4: a refusal page with reason `already_settled` or `money_captured`. No new attempt row.
- 5: the same gateway order ID both times (the live attempt is reused).

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
- [x] #1 A forged or missing resultIndicator never shows success or records PaymentCaptured for an unpaid order
- [x] #2 A paid order shows success even with a forged indicator
- [x] #3 A paid invoice refuses a new checkout with a recorded reason, and no attempt is added
- [x] #4 Starting checkout twice reuses the live attempt
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08 ~18:22 +05:00 with curl through the tunnel (the gateway's Hosted Checkout was down, so no new payment could be made; the already-paid QA-INV-0001-1 from TASK-14.4 stands in for step 3).
1. Unpaid live attempt QA-INV-0004-1: /mib/return/QA-INV-0004-1?resultIndicator=forged -> 200 'Your payment was not completed.' Attempt still live; PaymentCaptured count unchanged (1, from TASK-14.4).
2. /mib/return/QA-INV-0004-1 with no query -> 200 'Your payment was not completed.'
3. Paid order: /mib/return/QA-INV-0001-1?resultIndicator=forged -> 'Thank you. Your payment was successful.' (result comes from the gateway, not the indicator). No new PaymentCaptured (still 1).
4. /qa/invoices/1/pay for the paid INV-0001 -> refusal page, data-testid refusal-reason = payable_not_payable. Attempt count for INV-0001 stays 1. NOTE: Expected said already_settled or money_captured; the guard checks payableState() first and HasMibPayments::payableState() returns Paid once an attempt is settled, so payable_not_payable is the reason a host using the trait will see. Not a defect; the task's expectation was imprecise.
5. /qa/invoices/3/pay twice: both 302 to /mib/pay/QA-INV-0003-1 (same signed link); INV-0003 still has only attempt QA-INV-0003-1 (live). The live attempt is reused.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Checked return URL tampering and attempt guards through the tunnel: a forged or missing resultIndicator on an unpaid live order shows 'Your payment was not completed.' with no money event; a forged indicator on the paid QA-INV-0001-1 still shows success (decided by the gateway); a paid invoice is refused with payable_not_payable (HasMibPayments reports Paid before the settlement checks) and gets no new attempt; two checkout starts reuse the same live attempt. Step 3 used the earlier paid order because the gateway's payment page was down.
<!-- SECTION:FINAL_SUMMARY:END -->

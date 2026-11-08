---
id: TASK-14.8
title: 'QA: return URL tampering and attempt guards'
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
- [ ] #1 A forged or missing resultIndicator never shows success or records PaymentCaptured for an unpaid order
- [ ] #2 A paid order shows success even with a forged indicator
- [ ] #3 A paid invoice refuses a new checkout with a recorded reason, and no attempt is added
- [ ] #4 Starting checkout twice reuses the live attempt
<!-- AC:END -->

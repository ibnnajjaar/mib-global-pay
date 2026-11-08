---
id: TASK-14.6
title: 'QA: Embedded Page and Modal display modes'
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 14:01'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: medium
type: task
ordinal: 14006
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
`CheckoutJs` and the `<x-mib-global-pay::checkout>` component support three modes. Only the Payment Page redirect is exercised elsewhere.

## Steps (browser)
1. Create an invoice and open `{APP}/qa/invoices/{id}/pay`, but stop at the redirect: note the gateway order ID from the dashboard (the attempt is `live`).
2. Open `{APP}/qa/attempts/{order}/show?mode=embedded`. Expect "Loading the payment form…" and then the gateway's card form embedded in the harness page (an iframe from the gateway host). Pay with the approved card.
3. Create another invoice, start its attempt, and open `{APP}/qa/attempts/{order}/show?mode=modal`. Expect a "Pay now" button. Click it: a dialog opens with the embedded form. Close the dialog without paying, then reopen it and pay with the approved card.
4. For each, record the return page text, attempt state and events.
5. Resize the browser to phone width (about 390 px) on the embedded page and screenshot: the form must be usable without horizontal scrolling.

## Expected
- 2 and 3: the form renders inside the page or dialog, payment succeeds, and the return page says success. One `PaymentCaptured` each.
- 3: closing and reopening the dialog works, and no browser console errors come from the package script (read the console with a pattern such as `Checkout|mib`).
- 5: usable at phone width.

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
- [ ] #1 Embedded mode renders the gateway form in the page and a payment through it succeeds
- [ ] #2 Modal mode opens a dialog from Pay now, survives close and reopen, and a payment through it succeeds
- [ ] #3 No package-related console errors appear in either mode
- [ ] #4 The embedded form is usable at phone width
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
2026-11-08 19:01 +05:00: NOT RUN. Every step needs the gateway's Hosted Payment Page, which has shown 'Payment Page - System Error' ('Sorry, but we are unable to complete your payment', a new support code each time, e.g. GPH3YXDZLJGYG for QA-INV-0012-1) for every new session since 18:13 +05:00, including on the gateway's own /checkout/pay/<session> URL. Details in TASK-14.5. Resume when MIB confirms the test gateway's Hosted Checkout works.
<!-- SECTION:NOTES:END -->

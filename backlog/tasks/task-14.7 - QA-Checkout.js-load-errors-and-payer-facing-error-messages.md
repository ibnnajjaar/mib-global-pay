---
id: TASK-14.7
title: 'QA: Checkout.js load errors and payer-facing error messages'
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: low
type: task
ordinal: 14007
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
When Checkout.js fails to load or reports an error, the component replaces its text with "The payment page could not be loaded. Please try again." (CHANGELOG 2.0.0-beta.1). This is only unit-tested.

## Steps (browser)
1. Start an attempt and open `{APP}/qa/attempts/{order}/show?mode=embedded`, then edit the URL to use a made-up session ID. If the harness has no such option, add `?session=` support to that route first. Record what the page shows and the console errors.
2. In the harness `.env`, set `MIB_CHECKOUT_JS_URL` to an HTTPS URL that returns 404 (for example the gateway host plus `/static/checkout/missing.js`), clear config, and open a fresh attempt's `/mib/pay/{order}` link. Record the page text. Restore the setting afterwards and clear config again.
3. Open a `/mib/pay/{order}` link with its `signature` query parameter changed by one character. Record the HTTP status.
4. Open `/mib/pay/{order}` for an attempt that is already captured (from the purchase task), using a fresh signed link from the dashboard if it offers one. Record where you land.
5. Open `{APP}/mib/return/QA-DOES-NOT-EXIST-1`. Record the HTTP status.

## Expected
- 1 and 2: the text becomes "The payment page could not be loaded. Please try again." No raw stack trace or blank page.
- 3: 403 (invalid signature).
- 4: a redirect to `/mib/return/{order}`, which shows the success text. The payer is not sent to pay again.
- 5: 404.

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
- [ ] #1 An invalid session and an unreachable Checkout.js both show the payer-facing error message
- [ ] #2 A tampered signed payment link is refused with 403
- [ ] #3 Opening the payment page of a finished attempt redirects to its result instead of a new payment
- [ ] #4 An unknown order on the return route answers 404
- [ ] #5 MIB_CHECKOUT_JS_URL is restored afterwards
<!-- AC:END -->

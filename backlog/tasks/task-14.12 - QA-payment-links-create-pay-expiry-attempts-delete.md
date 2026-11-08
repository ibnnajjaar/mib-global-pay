---
id: TASK-14.12
title: 'QA: payment links (create, pay, expiry, attempts, delete)'
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
ordinal: 14012
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
Payment Links are new in this beta and unverified on the gateway. The error page maps the gateway's `errorCode` (`EXPIRED`, `PAID`, `USAGE_COUNT_EXCEEDED`, `DOES_NOT_EXIST`, `DELETED`) to payer messages.

## Steps (browser)
1. Create an invoice and open `{APP}/qa/invoices/{id}/link?days=7&attempts=3`. Copy the link URL (`data-testid="payment-link"`).
2. Open the link in a fresh tab. Expect the gateway's payment page for that amount. Pay with the approved card. Record the return page text, attempt state and events.
3. Open the same link again. Expect a redirect to `{APP}/mib/link-error?errorCode=PAID` with "This order has already been paid.". Record the actual code.
4. Create a second link with `attempts=1`. Pay once with the declined card, then open the link again. Expect "This payment link has been tried too many times. Please ask for a new one." (`USAGE_COUNT_EXCEEDED`).
5. Create a third link and delete it with the dashboard button. Open it. Expect "This payment link is no longer valid." (`DELETED` or `DOES_NOT_EXIST`).
6. If the harness allows a link expiring in a few minutes, create one, wait for it to expire, and open it. Expect "This payment link has expired. Please ask for a new one.". If the gateway's minimum expiry is too long, record that and skip.
7. Open `{APP}/mib/link-error?errorCode=SOMETHING_ELSE`. Expect "This payment link could not be opened. Please try again later.".

## Expected
As listed. Record each `errorCode` the gateway actually sends, because the mapping is unconfirmed.

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
- [ ] #1 A payment link opens the gateway page, and paying through it captures the invoice
- [ ] #2 A paid, over-used, deleted and (if possible) expired link each show the matching message, with the real errorCode recorded
- [ ] #3 An unknown errorCode shows the generic message
<!-- AC:END -->

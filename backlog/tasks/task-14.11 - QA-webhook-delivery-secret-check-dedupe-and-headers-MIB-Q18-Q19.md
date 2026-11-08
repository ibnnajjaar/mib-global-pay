---
id: TASK-14.11
title: 'QA: webhook delivery, secret check, dedupe and headers (MIB Q18, Q19)'
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
labels:
  - qa
dependencies:
  - TASK-14.1
parent_task_id: TASK-14
priority: high
type: task
ordinal: 14011
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
The webhook format and headers are unconfirmed (README "Beta status"): the body shape, whether `X-Notification-Attempt` starts at 1, whether `X-Notification-ID` is always present, and the header casing. The route must answer 200 (accepted or duplicate), 401 (bad secret), 400 (malformed) or 503 (not configured).

## Preconditions
The harness is reachable through its public tunnel, `MibGlobalPay::webhookUrl()` is the tunnel URL, and webhook notifications are enabled for the test merchant. If the webhook is configured in Merchant Administration and you have no access, ask the owner.

## Steps
1. Temporarily log the raw headers and body of each webhook request in the harness (secret header redacted), for example with a route middleware that runs before the package's. Remove it after this task.
2. Pay one invoice by Payment Page purchase. Within about 2 minutes, record the logged headers (names exactly as received) and the body shape.
3. On the dashboard, check that the notification row exists, its outcome is processed, and there is exactly one `PaymentCaptured`.
4. With curl, POST to the webhook URL:
   - (a) a body with a wrong `X-Notification-Secret`;
   - (b) a malformed JSON body with the right secret (read the secret from the env, without echoing it);
   - (c) the exact body and headers from step 2 again.
5. Close the browser before the return page loads on another purchase (open the payment page, pay, and close the tab as soon as the gateway says success). Wait for the webhook and record that it alone settled the attempt.

## Expected
- 2: a notification arrives. Record the header names, attempt counter, ID header and body.
- 4(a): 401. 4(b): 400. 4(c): 200 with no second `PaymentCaptured` (duplicate).
- 5: the attempt becomes `captured` and `PaymentCaptured` is recorded, with no return-page visit.

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
- [ ] #1 A real webhook arrives, and its header names, attempt counter, ID header and body shape are recorded
- [ ] #2 A wrong secret gets 401, a malformed body gets 400, and a replayed notification gets 200 without a second money event
- [ ] #3 A payment whose payer never returns is settled by the webhook alone
- [ ] #4 The temporary raw-request logging is removed afterwards
<!-- AC:END -->

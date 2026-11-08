---
id: TASK-14.11
title: 'QA: webhook delivery, secret check, dedupe and headers (MIB Q18, Q19)'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:22'
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
- [x] #1 A real webhook arrives, and its header names, attempt counter, ID header and body shape are recorded
- [x] #2 A wrong secret gets 401, a malformed body gets 400, and a replayed notification gets 200 without a second money event
- [ ] #3 A payment whose payer never returns is settled by the webhook alone
- [x] #4 The temporary raw-request logging is removed afterwards
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08. Step 1 deviation: instead of a temporary logging middleware, the raw requests were read from the ngrok inspector (127.0.0.1:4040), which records every request through the tunnel; the X-Notification-Secret value was redacted when reading. No harness code was added, so nothing needs removing.
Real gateway notifications (MIB Q18/Q19):
- Headers as delivered through ngrok (ngrok may canonicalise case): Accept */*, Accept-Encoding gzip,deflate, Content-Type 'application/json;charset=utf-8', User-Agent 'AHC/2.1', X-Notification-Attempt, X-Notification-Id, X-Notification-Secret. Source IP 12.22.155.240.
- X-Notification-Id: 64 hex characters (e.g. 465baa3c84…), the same on every retry of one notification. X-Notification-Attempt starts at 1 and counts up per retry.
- Retry schedule when the endpoint returned 401 (TASK-14.4): attempts 1-6 at 16:36:33, 16:37:45, 16:39:18, 16:42:21, 16:48:23, 17:19:25 +05:00 (gaps ~1.2, 1.5, 3, 6, 31 min). Attempt 6 got 200 and was a duplicate.
- One 3DS card payment sends two notifications: transaction.type AUTHENTICATION (id trans-60) and PAYMENT (id 1). A void sends one: notification 610c707ea7 (attempt 1) arrived ~50 s after the void call.
- Body shape (PAYMENT, QA-INV-0001-1): top-level keys authentication{3ds,3ds2,amount,time,transactionId,version}, authorizationResponse{...}, device{browser,ipAddress}, gatewayEntryPoint CHECKOUT_VIA_WEBSITE, merchant, order{amount,authenticationStatus,chargeback,creationTime,currency,description,id,lastUpdatedTime,merchantAmount,merchantCategoryCode,merchantCurrency,notificationUrl,reference,status,totalAuthorizedAmount,totalCapturedAmount,totalDisbursedAmount,totalRefundedAmount}, orderEventPKBucket, orderEventPKInsertionTime, response{acquirerCode,acquirerMessage,cardSecurityCode,gatewayCode,gatewayRecommendation}, result, sourceOfFunds{provided.card{brand,expiry,fundingMethod,issuerCountryCode,nameOnCard,number (masked 512345xxxxxx0008),scheme,storedOnFile},type}, timeOfLastUpdate, timeOfRecord, transaction{acquirer{...},amount,authenticationStatus,authorizationCode,currency,id,receipt,source,stan,terminal,type}, version '100'. Amounts are JSON numbers (10.00), not strings. The AUTHENTICATION body has the same layout with transaction.type AUTHENTICATION and order totals 0.
Step 3: dashboard notifications table: 465baa3c84 processed, 31c4ed844a ignored; exactly one PaymentCaptured (TASK-14.4).
Step 4 (curl through the tunnel, 18:21 +05:00; secret read from .env, never echoed): (a) wrong secret -> 401; no secret header -> 401 {"error":"unauthorized"}. (b) malformed JSON with the right secret -> 400 {"error":"malformed notification"}; '{}' -> 400 too. (c) replays of real notifications via the inspector: 465baa3c84 and 31c4ed844a at 16:58:18 -> 200, and void notification 610c707ea7 replayed at 18:21:32 -> 200; no second PaymentCaptured / VoidProcessed / PaymentReversed, and no new row in mib_global_pay_notifications. The gateway's own attempt-6 retry at 17:19 was also a 200 duplicate.
Void notification processing: VoidProcessed and PaymentReversed recorded once each (source webhook, distinct deliveryKeys), attempt QA-INV-0001-1 reversed_at set.
Step 5 (payer never returns) NOT DONE: the gateway's Hosted Checkout is down (see TASK-14.5), so no new payment can be made. Kept In Progress for that.
Harness note: the queue worker had been running code from before the RecordMibEvent fix (workers keep loaded code), so NotificationReceived rows up to id 5 have no gateway order ID; the worker was restarted at 18:21.
<!-- SECTION:NOTES:END -->

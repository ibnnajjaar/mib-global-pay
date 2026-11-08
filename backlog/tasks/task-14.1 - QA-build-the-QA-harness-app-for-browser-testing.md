---
id: TASK-14.1
title: 'QA: build the QA harness app for browser testing'
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:10'
labels:
  - qa
dependencies: []
references:
  - README.md
parent_task_id: TASK-14
priority: high
type: task
ordinal: 14001
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
No app uses v2 yet, so the other QA subtasks need a small Laravel app that installs this package from the local checkout and gives a browser-testing agent stable pages and selectors to drive and check. It is throwaway test tooling, kept outside this package.

## What to build
A new sibling project at `~/Herd/mib-qa` (its own git repository; never commit it into this package):
- **Stack:** Laravel 13 on PHP 8.5, served by lerd (`lerd` MCP tools: `site` with `link`, `exec` for artisan and composer, `worker` for the queue). SQLite or a new lerd MySQL database. Queue driver `database`, with a lerd queue worker running.
- **Package:** a Composer `path` repository pointing at `../mib-global-pay` (symlinked), requiring `ibnnajjaar/mib-global-pay:2.x-dev@dev`. Publish and run the package migrations (`vendor:publish --tag=mib-global-pay-migrations`, then `migrate`).
- **Env:** copy only these keys' values from `~/Herd/mib-global-pay/.env` without printing them: `MIB_GATEWAY_URL`, `MIB_MERCHANT_ID`, `MIB_API_PASSWORD`, `MIB_WEBHOOK_SECRET`, `MIB_CHECKOUT_JS_URL`. Also set `MIB_ORDER_PREFIX=QA`, `MIB_MERCHANT_NAME="MIB QA"`, `MIB_CURRENCY=MVR` (change it if the connectivity check shows MVR is not enabled), `MIB_CHECKOUT_COMPLETED_URL` empty (so the package's own result page shows), and `QUEUE_CONNECTION=database`.
- **Public URL for webhooks:** the gateway must reach the webhook over public HTTPS. Start a tunnel to the lerd site (for example `cloudflared tunnel --url https://mib-qa.test`, or whatever the owner prefers), and set `APP_URL` to the tunnel URL so the return URL and `order.notificationUrl` use it. Ask the owner before installing a tunnel tool. Without a tunnel, browser flows still work but the webhook subtask cannot.
- **Payable model:** `Invoice` (`number`, `total` decimal string, `currency`) implementing `Core\Attempts\Payable` with the `HasMibPayments` trait, as in README.md "Laravel > Quick start".
- **Event recorder:** one listener for every event in `IbnNajjaar\MIBGlobalPay\Laravel\Events` that stores the event class, `deliveryKey` (when present, unique), gateway order ID, status and a short JSON summary in a `qa_events` table. On `PaymentCaptured`, set `invoices.paid_at`.

## Harness pages (no auth; local QA only; every value the browser checks has a `data-testid`)
- `GET /qa`: the dashboard.
  - A form to create an invoice: amount, currency.
  - The invoice list with `paid_at` and `payableState()`.
  - Each invoice's attempts (`gateway_order_id`, `operation`, `state`, `session_id` present yes/no, `settled_at`, `reversed_at`, `confirmed_unpaid_at`, `expires_at`).
  - The last 50 `qa_events` rows, and the last 20 rows of the package's notifications table.
- `GET /qa/invoices/{invoice}/pay`: calls `MibGlobalPay::startCheckout()` and redirects to `paymentUrl()`.
  - Query options: `operation=purchase|authorize|verify`, `locale`, `save_card=1`, `three_ds=bypass|mandatory`, `csc=optional`, `retry_redirect=1` (calls `withRetryRedirect({APP}/qa/retry-landing, 3)`), `logo=1` (an HTTPS logo URL), `display=hide` (hides billing address and email through `withDisplayControl()`). These are applied through the `customize` closure.
  - On `CheckoutNotAllowed`, show a page with `data-testid="refusal-reason"` holding `$e->result->reason->value`.
- `GET /qa/attempts/{order}/show?mode=payment|embedded|modal`: renders `<x-mib-global-pay::checkout :session="..." mode="...">` for that attempt's session, so the Embedded and Modal modes can be tested without changing `MIB_CHECKOUT_MODE`.
- `GET /qa/invoices/{invoice}/link?days=7&attempts=3`: calls `MibGlobalPay::startPaymentLink()` and shows the link URL (`data-testid="payment-link"`).
- POST actions shown as buttons on the dashboard, each showing the gateway's `result` and `gatewayCode` afterwards:
  - capture the full or a partial amount;
  - void the latest transaction;
  - refund the full or a partial amount;
  - `reverseWithDerivedIds`;
  - delete a payment link;
  - retrieve and delete a saved token;
  - `confirmUnpaid` (with a "confirmed by" field);
  - run `mib:reconcile`.
- `GET /qa/retry-landing`: a plain page saying "retry landing" with the query string shown.

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
- [x] #1 `{APP}/qa` loads, and creating an invoice shows it in the list
- [x] #2 The package is installed from the local checkout by path, and its migrations have run
- [x] #3 Each harness route and action listed in the description exists and shows its result with a data-testid
- [x] #4 A queue worker is running, and a test event dispatched from tinker appears in qa_events
- [x] #5 The webhook URL (`MibGlobalPay::webhookUrl()`) is a public HTTPS URL, or the notes say no tunnel was approved
- [x] #6 No credential value appears in the harness repository, notes or screenshots
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Create ~/Herd/mib-qa with composer create-project laravel/laravel (Laravel 13), link it as a lerd site on PHP 8.5 with its own MySQL database and the database queue.
2. Require ibnnajjaar/mib-global-pay 2.x-dev from a path repository (../mib-global-pay), publish config and migrations, migrate.
3. Copy the gateway env keys from mib-global-pay/.env without printing them; set MIB_ORDER_PREFIX=QA, MIB_MERCHANT_NAME, APP_URL=https://hamburger-ogle-famished.ngrok-free.dev.
4. Start the ngrok tunnel on that static domain to the lerd site with lerd share --ngrok --domain, and start a lerd queue worker.
5. Check the webhook route answers 401 without the secret, through the tunnel.
6. Then: Invoice model with HasMibPayments, qa_events recorder, the /qa dashboard, pay/link/show routes and action buttons, each with data-testid.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Progress 2026-11-08:
- Created ~/Herd/mib-qa (Laravel 13.40, SQLite, database queue), linked as lerd site mib-qa (PHP 8.5). Not yet committed in its own git repo.
- Package required from path ../mib-global-pay (symlink, 2.x-dev). FINDING: a fresh Laravel 13 app ships guzzlehttp/guzzle 8.3.1, and the package requires guzzle ^7.9, so composer require fails until -W downgrades guzzle to 7.16.1 (with promises 2.x, psr7 2.x). Laravel 13 itself allows ^7.8.2 || ^8.0. The package constraint should probably allow ^8 too; owner to decide.
- .env: gateway keys copied from mib-global-pay/.env without printing; APP_URL=https://hamburger-ogle-famished.ngrok-free.dev; MIB_ORDER_PREFIX=QA; MIB_MERCHANT_NAME="MIB QA". AppServiceProvider forces root URL and https so return, signed and webhook URLs use the tunnel.
- Invoice model (Payable + HasMibPayments), qa_events table and RecordMibEvent listener for all package events (idempotent on deliveryKey; sets invoices.paid_at on PaymentCaptured). Package migrations published and run. Queue worker started (lerd-queue-mib-qa).
- Webhook route POST /mib/webhook answers 401 locally with no or a wrong secret.
- BLOCKED: lerd share --ngrok --domain hamburger-ogle-famished.ngrok-free.dev fails with ERR_NGROK_320: the domain is not reserved on the ngrok account of the local authtoken. Waiting for the owner.
- Still to do: /qa dashboard, pay/link/show routes, action buttons, data-testids.

Tunnel: the first domain belonged to another ngrok account. Now lerd share --ngrok --domain excavator-splurge-granite.ngrok-free.dev runs (background, from ~/Herd/mib-qa); APP_URL updated to it. Through the tunnel, POST /mib/webhook answers 401 with no or a wrong secret, and 400 with the right secret and an empty JSON body (secret accepted, malformed notification). MibGlobalPay::webhookUrl() returns https://excavator-splurge-granite.ngrok-free.dev/mib/webhook. Owner is adding that URL in the MIB gateway. Still to do: /qa dashboard, routes, actions, data-testids.

Harness pages built: /qa dashboard (invoices, attempts, actions, events, notifications, with data-testids), /qa/invoices/{id}/pay (operation, locale, save_card, token, three_ds, csc, retry_redirect, logo, display options), /qa/invoices/{id}/link (days, minutes, attempts), /qa/attempts/{order}/show (mode, session), POST /qa/attempts/{order}/action (retrieve_order, capture, refund, void, reverse, delete_link, retrieve_token, delete_token, confirm_unpaid), POST /qa/reconcile, /qa/retry-landing.
Harness fix: signed /mib/pay links gave 403 through the tunnel because lerd's nginx sends X-Forwarded-Port: 80. bootstrap/app.php now trusts proxies for X-Forwarded-For/Host/Proto only. Not a package issue.
First real gateway call: startCheckout(INV-0001, 10.00 MVR) created session for QA-INV-0001-1 (state live, successIndicator stored). /mib/pay/{order} loads checkout.min.js from the test gateway and calls Checkout.configure + showPaymentPage. Tampered signature gives 403.

Verification 2026-11-08 (closing):
- AC1: through the tunnel, GET /qa returns 200; posting the invoice form (25.50 MVR) redirects and the dashboard shows action-result 'Created INV-0002 for 25.50 MVR' and row invoice-INV-0002.
- AC2: package from path ../mib-global-pay (symlink, 2.x-dev); package migrations published and run (checkout attempts and notifications tables in use by TASK-14.4).
- AC3: routes /qa, /qa/invoices, /qa/invoices/{id}/pay, /link, /qa/attempts/{order}/show, /action, /qa/reconcile, /qa/retry-landing exist; every action button and result has a data-testid (action-result, refusal-reason, payment-link, retry-landing, checkout-target...).
- AC4: queue worker lerd-queue-mib-qa running; qa-scripts/14.1-queued-test-event.php queues a closure that fires UnknownOrderNotification; the worker recorded it in qa_events within 1 s.
- AC5: MibGlobalPay::webhookUrl() = https://excavator-splurge-granite.ngrok-free.dev/mib/webhook (ngrok static domain via lerd share); the gateway's real notifications reach it (TASK-14.4).
- AC6: the API password and webhook secret values were searched for (by value, never printed) in mib-qa tracked files, full git history, ignored files (excluding .env and vendor) and this package's repo and history: 0 hits.
- Harness also has qa-scripts/bootstrap.php for one-off scripts run with: podman exec -w ~/Herd/mib-qa lerd-php85-fpm php qa-scripts/<name>.php
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Built the QA harness at ~/Herd/mib-qa (Laravel 13.40, PHP 8.5, SQLite, database queue, lerd site, ngrok tunnel): Invoice payable, qa_events recorder, /qa dashboard with pay/link/show routes and gateway actions, all with data-testids. Verified by creating an invoice through the tunnel, a queued test event reaching qa_events, the gateway's real webhooks reaching the public URL, and a by-value credential scan with 0 hits.
<!-- SECTION:FINAL_SUMMARY:END -->

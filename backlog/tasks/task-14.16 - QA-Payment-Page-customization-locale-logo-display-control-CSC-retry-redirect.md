---
id: TASK-14.16
title: >-
  QA: Payment Page customization (locale, logo, display control, CSC, retry
  redirect)
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 14:01'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: low
type: task
ordinal: 14016
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
The Hosted Checkout customization fields in docs/hosted-checkout/customizingPaymentExperience.md, customizeRedirectAndRetryBehavior.md and nonEcommerceIntegrations.md are sent by the package but never checked on the real page.

## Steps (browser; one new invoice per step, opened through `{APP}/qa/invoices/{id}/pay` with the option shown)
1. `?locale=ar` (and then `?locale=fr_CA`): record the language the gateway page uses.
2. `?logo=1`: record whether the HTTPS logo shows, and its size or cropping. The guides disagree: a 144 px minimum, or a height over 140 px is cropped.
3. `?display=hide`: record whether the billing address and email fields are hidden.
4. `?csc=optional`: record whether the CSC field is optional, and whether a payment without CSC is accepted.
5. `?retry_redirect=1`: fail the payment with the declined card 3 times. Expect a redirect to `{APP}/qa/retry-landing` after the third failure. Record what happens and the query string received.
6. Record the merchant name on every page ("MIB QA").
7. Note anything the page shows that the request did not set, such as Click to Pay, wallets, or an email field.

## Expected
Each option visibly changes the page as described, or the gateway's behaviour is recorded as a finding.

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
- [ ] #1 The effect of locale, logo, display control and CSC options on the gateway page is recorded with screenshots
- [ ] #2 Retry redirect sends the payer to the redirect URL after the configured failures, or the actual behaviour is recorded
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
2026-11-08 19:01 +05:00: NOT RUN. Every step needs the gateway's Hosted Payment Page, which has shown 'Payment Page - System Error' ('Sorry, but we are unable to complete your payment', a new support code each time, e.g. GPH3YXDZLJGYG for QA-INV-0012-1) for every new session since 18:13 +05:00, including on the gateway's own /checkout/pay/<session> URL. Details in TASK-14.5. Resume when MIB confirms the test gateway's Hosted Checkout works.
<!-- SECTION:NOTES:END -->

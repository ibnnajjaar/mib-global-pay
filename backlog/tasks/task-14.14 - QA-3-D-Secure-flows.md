---
id: TASK-14.14
title: 'QA: 3-D Secure flows'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:25'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: low
type: task
ordinal: 14014
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
`withThreeDSecure()` sends `interaction.action.3DSecure` (`BYPASS` or `MANDATORY`; docs/hosted-checkout/features.md). Whether the test merchant is configured for 3DS is unknown.

## Steps (browser)
1. Read the gateway's "Testing your integration for 3DS authentication" page and pick a frictionless card and a challenge card.
2. Create an invoice and open `{APP}/qa/invoices/{id}/pay?three_ds=mandatory`. Pay with the challenge card. Complete the simulator's challenge successfully. Record each screen, the return page text and events.
3. Repeat, failing the challenge in the simulator. Record the gateway page message, then the return page text if you get there. Expect no `PaymentCaptured`.
4. Repeat with `three_ds=bypass` and the challenge card. Record whether the challenge is skipped.
5. Record an Authentication notification body if one arrives on the webhook. The package classifies it as `AuthenticationNotification`.

## Expected
A successful challenge pays, a failed challenge does not, and bypass skips the challenge if the merchant allows it. If 3DS is not configured, record what the gateway does with these flags.

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
- [ ] #1 A successful 3DS challenge leads to a captured payment
- [ ] #2 A failed 3DS challenge leads to no capture and no money event
- [ ] #3 The effect of BYPASS is recorded
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Step 1 (2026-11-08): read the gateway page .../authentication/3DS/test_your_integration.html (JS-rendered; table parsed from the HTML). Picked: Mastercard CHALLENGE 5123450000000008 (also the approved card; TASK-14.4 got the ACS emulator challenge with it) or 2223000000000007; Mastercard FRICTIONLESS 5123456789012346 (transStatus Y, ECI 02); Visa challenge 4440000009900010, Visa frictionless 4440000042200014. Other useful Mastercard rows: Authentication Attempted 5500005555555559 (A), Authentication Rejected 5506900140100503 (R). The 3DS emulator's challenge options: Successful authentication (Y), Failed authentication (N), Cancelled authentication (N, challengeCancel 01), Unable to authenticate (U), Authentication rejected (R). Expiry for these: use the approved expiry 01/39 so the payment itself is approved.
Already observed in TASK-14.4 (default settings, no three_ds option): a successful 3DS2 challenge with 5123450000000008 led to a captured payment; the gateway sent an AUTHENTICATION notification (trans-60, authenticationStatus AUTHENTICATION_SUCCESSFUL, 3ds2.transactionStatus Y, acsEci 02), which the package classified as AuthenticationNotification and ignored.
Steps 2-4 (mandatory, failed challenge, bypass) need the payment page; blocked by the gateway's Hosted Checkout outage (TASK-14.5).
<!-- SECTION:NOTES:END -->

---
id: TASK-14.2
title: 'QA: gateway connectivity, credentials and enabled features'
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
ordinal: 14002
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
First proof that the bank's test credentials work with the package, and the answer to which operations and currencies the merchant has enabled (MIB questions 1, 2 and 7-15). No browser needed.

## Steps (in the harness, with `lerd` `exec` artisan `tinker`, or a one-off artisan command)
1. `MibGlobalPay::checkGateway()`: record `isOperating()` and the status.
2. `MibGlobalPay::paymentOptionsInquiry()`: record the supported payment types, currencies and any operations the response lists.
3. `MibGlobalPay::paymentOptionsInquiry(Currency::Mvr)`: record whether MVR is accepted.
4. Start one Hosted Checkout session with `MibGlobalPay::initiateCheckout(MibGlobalPay::newCheckoutRequest(MibGlobalPay::randomOrderId(), Money::of('10.00', Currency::Mvr), 'MIB QA', '{APP}/qa'))`. Record that a `sessionId` and `successIndicator` came back (not their values).
5. `MibGlobalPay::retrieveSession($sessionId)`: record the session status and the `order.amount` and `currency` it echoes.
6. Repeat step 1 with a wrong API password set only in that tinker session (`new MibGateway(new GatewayConfig(...))`): record the exception class and message.

## Expected
- 1: operating.
- 2-3: MVR is listed. If it is not, the harness `MIB_CURRENCY` must change before the browser tasks; note it.
- 4-5: a session is created and retrievable with the same amount and currency.
- 6: an authentication `GatewayException` (HTTP 401). No order or session is created.

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
- [ ] #1 Check Gateway reports the gateway operating
- [ ] #2 The enabled currencies and operations from Payment Options Inquiry are recorded in notes
- [ ] #3 A checkout session is created and Retrieve Session returns it with the same amount and currency
- [ ] #4 Wrong credentials fail with an authentication GatewayException, and that is recorded
<!-- AC:END -->

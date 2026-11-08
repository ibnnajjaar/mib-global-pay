---
id: TASK-14.2
title: 'QA: gateway connectivity, credentials and enabled features'
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:11'
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
- [x] #1 Check Gateway reports the gateway operating
- [x] #2 The enabled currencies and operations from Payment Options Inquiry are recorded in notes
- [x] #3 A checkout session is created and Retrieve Session returns it with the same amount and currency
- [x] #4 Wrong credentials fail with an authentication GatewayException, and that is recorded
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Script qa-scripts/14.2-connectivity.php in the harness running steps 1-6 through the MibGlobalPay facade.
2. For step 6 build a second MibGateway with a random wrong password (never printed), against Check Gateway and also Retrieve Order.
3. Record results; findings for anything that differs from Expected.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08 ~17:40 +05:00, script ~/Herd/mib-qa/qa-scripts/14.2-connectivity.php (no browser, no GIF).
1. checkGateway(): isOperating=true, status OPERATING, gatewayVersion 26.9.4-3R.
2. paymentOptionsInquiry(): result SUCCESS, merchant TESTIUMMPGS. paymentTypes.card: cardTypes MASTERCARD and VISA (schemeTokenTypes DYNAMIC_CSC_AND_EXPIRY), currencies [MVR] only, 3DSecureSchemes SECURE_CODE and VERIFIED_BY_VISA, interactionConstraints CARD_SECURITY_CODE_ENTRY, transactionSources [INTERNET]. supportedPaymentOperations: [PURCHASE] only.
3. paymentOptionsInquiry(MVR): cardsAcceptCurrency(MVR)=true, cardCurrencies [MVR]. Extra: paymentOptionsInquiry(USD) gives cardsAcceptCurrency(USD)=false. MIB_CURRENCY=MVR stays.
4. initiateCheckout(QA-KX3HBO57O6VWY3PN, 10.00 MVR, PURCHASE): result SUCCESS, sessionId, successIndicator and sessionVersion all present, updateStatus SUCCESS. This order was never paid (used by TASK-14.3 step 4).
5. retrieveSession: updateStatus SUCCESS, isUsable=true, order.id QA-KX3HBO57O6VWY3PN, amount 10.00, currency MVR, interaction.operation PURCHASE. Matches step 4.
6. Wrong API password (random value, new MibGateway with otherwise identical config):
   - checkGateway() still SUCCEEDS (OPERATING). FINDING (task expectation, not a package bug): Check Gateway is an unauthenticated endpoint, so it cannot prove credentials. The README/health-check guidance should not present checkGateway() as a credentials check, if it does.
   - retrieveOrder('QA-INV-0001-1') throws GatewayException 'Invalid credentials.', httpStatus 401, error {cause: INVALID_REQUEST, explanation: 'Invalid credentials.'}. This is the authentication failure Expected describes.
FINDING (important for later tasks): the merchant lists only PURCHASE in supportedPaymentOperations. AUTHORIZE/CAPTURE (TASK-14.9), VERIFY (TASK-14.10) and possibly VOID may be rejected for this test merchant; those tasks will record what the gateway actually does. Ask MIB to enable them on TESTIUMMPGS if the package's authorize/verify flows are to be tested.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Ran the connectivity checks against the MIB test gateway with qa-scripts/14.2-connectivity.php: gateway OPERATING (26.9.4-3R); cards MASTERCARD/VISA, currency MVR only (USD not accepted), operations PURCHASE only; a session for QA-KX3HBO57O6VWY3PN was created and retrieved with 10.00 MVR PURCHASE; a wrong password fails Retrieve Order with GatewayException 401 'Invalid credentials.'. Findings: Check Gateway needs no credentials, so it succeeds with a wrong password; and only PURCHASE is enabled for the merchant.
<!-- SECTION:FINAL_SUMMARY:END -->

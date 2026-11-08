---
id: TASK-14.9
title: 'QA: authorize, capture, partial capture, void and refund'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:22'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: medium
type: task
ordinal: 14009
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
The follow-up operations (MIB guide docs/hosted-checkout/subsequentOperations.md) and their events: `PaymentAuthorized`, `PaymentPartiallyCaptured`, `PaymentCaptured`, `VoidProcessed`, `RefundProcessed` and `PaymentReversed`.

## Steps (browser, with the dashboard's action buttons)
1. Create an invoice for 100.00 MVR and open `{APP}/qa/invoices/{id}/pay?operation=authorize`. Pay with the approved card.
   - Expect the return page "Thank you. Your payment has been approved.", attempt `authorized`, and a `PaymentAuthorized` event.
2. Open `{APP}/qa/invoices/{id}/pay` again. Expect the refusal reason `authorization_held`.
3. Capture 40.00 (partial). Expect gateway result SUCCESS and a `PaymentPartiallyCaptured` event.
4. Capture the remaining 60.00. Expect `PaymentCaptured`, attempt `captured`, and the invoice paid.
5. Refund 10.00 (partial). Expect SUCCESS and `RefundProcessed`. Record whether the invoice stays paid.
6. On a second invoice paid by a normal purchase, click "void" straight after paying. Record whether the void succeeds (it does only if the payment has not gone to the acquirer yet) and which event appears.
7. On a third paid invoice, click "reverseWithDerivedIds". Record `ReversalOutcome.method` and `reversed`. Click it again and record that nothing new was sent (same outcome, and no new transaction in Retrieve Order).
8. On an authorized but uncaptured invoice (repeat step 1), void the authorization. Expect `VoidProcessed`, and that a new checkout is then allowed.

## Expected
As listed in each step. Every money event appears exactly once per transaction, each with a `deliveryKey`.

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
- [ ] #1 An AUTHORIZE checkout shows the approved text, records PaymentAuthorized and blocks a new attempt with authorization_held
- [ ] #2 Partial and final captures record PaymentPartiallyCaptured and then PaymentCaptured
- [ ] #3 A partial refund records RefundProcessed, and the effect on the invoice is recorded
- [ ] #4 The void and reverseWithDerivedIds outcomes are recorded, and a second reversal call sends nothing new
- [ ] #5 Voiding an authorization records VoidProcessed and allows a new checkout
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08 ~18:19 +05:00.
Steps 1-4 and 8 (AUTHORIZE, partial and final capture, void of an authorization) CANNOT run on this merchant: /qa/invoices/6/pay?operation=authorize (INV-0006, 100.00 MVR) returns HTTP 500 from the harness because Initiate Checkout is refused: HTTP 400 {"error":{"cause":"INVALID_REQUEST","explanation":"Value AUTHORIZE is invalid. Merchant TESTIUMMPGS is not enabled for AUTHORIZE., please contact your Service Provider for expected values.","field":"interaction.operation","validationType":"INVALID"},"result":"ERROR"}. startCheckout() threw GatewayException(400) and left no attempt row for INV-0006 (good). Matches TASK-14.2 (supportedPaymentOperations = PURCHASE only). MIB must enable AUTHORIZE/CAPTURE on TESTIUMMPGS to test these.
FINDING (minor): Core\Exceptions\OperationNotEnabled ('The operation is not enabled for this merchant') exists but nothing throws it; this gateway refusal surfaces as a generic GatewayException 400 with field interaction.operation. A host cannot tell it apart without parsing error.field.
Step 6 (void straight after a purchase): dashboard 'void' on the paid QA-INV-0001-1 (purchased 16:35, same acquirer batch day) -> result=SUCCESS gatewayCode=APPROVED type=VOID_PAYMENT txn=void-9db91923193109c7 amount=10.00 orderStatus=CANCELLED. The VOID webhook (notification 610c707ea7, attempt 1) arrived ~50 s later and recorded VoidProcessed and PaymentReversed once each (source webhook, each with a deliveryKey); attempt reversed_at set. A replay of that notification added nothing. Harness note: invoices.paid_at stays set because the harness listener does not clear it on PaymentReversed (harness, not package); payableState() is what a host should read.
Steps 5 (partial refund) and 7 (reverseWithDerivedIds twice) need another paid order; blocked by the gateway's Hosted Checkout outage (TASK-14.5). Kept In Progress.
<!-- SECTION:NOTES:END -->

---
id: TASK-14.17
title: 'QA: identifiers and Update Session on a live session (MIB Q3, Q4, Q16)'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 13:32'
labels:
  - qa
dependencies:
  - TASK-14.2
parent_task_id: TASK-14
priority: low
type: task
ordinal: 14017
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
These are still unconfirmed:
- which characters `order.id` and `order.reference` accept (question 16);
- whether Update Session can change a live Hosted Checkout session (question 3; `updateSession()` ships as experimental);
- how long a session lasts (question 4).

## Steps
1. Tinker: start checkout sessions whose `withReference()` contains `(R)INV/2026/123`, a space, Dhivehi text (`ފައިސާ`), `&` and `#`. Record which the gateway accepts and what Retrieve Order echoes back. The package sanitises the order ID itself; record the generated IDs.
2. Tinker: start a session for 10.00 MVR, then `MibGlobalPay::updateSession($id, new SessionUpdate(orderAmount: Money::of('12.00', Currency::Mvr)))`. Record the response.
3. Browser: open that session's attempt page (`{APP}/qa/attempts/{order}/show?mode=payment`, or the payment page for a session created in tinker) and record the amount shown, 10.00 or 12.00. Do not pay.
4. Start a session with `withTimeout(600)` and do not open it. After 11 minutes, try to open it in the browser. Record what the gateway shows. Then call `retrieveSession()` and record its status.

## Expected
Record the facts. These steps answer questions rather than check known behaviour, and every answer goes into the parent's summary.

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
- [x] #1 Which reference characters the gateway accepts is recorded
- [ ] #2 Whether Update Session changes the amount the payer sees on a live session is recorded
- [ ] #3 What happens to a session after its interaction timeout is recorded
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Run 2026-11-08 18:20-18:32 +05:00, script ~/Herd/mib-qa/qa-scripts/14.17-identifiers-update-session.php (refs | update | timeout | check <session>).
Step 1, order IDs the package generates (attemptOrderId(ref, 1), prefix QA): '(R)INV/2026/123' -> QA-R-INV-2026-123-1; 'INV 2026 123' -> QA-INV-2026-123-1; 'ފައިސާ-1' -> QA-1-1 (Dhivehi dropped entirely); 'A&B#1' -> QA-A-B-1-1; 'inv_2026-ok' -> QA-inv_2026-ok-1. NOTE: a reference made only of non-Latin text collapses to almost nothing ('ފައިސާ-1' -> QA-1-1), so two Dhivehi-only references can map to the same readable ID; the README says randomOrderId() is the fallback on a collision.
order.reference via withReference() on random order IDs; all ACCEPTED by Initiate Checkout and echoed back unchanged by Retrieve Session: '(R)INV/2026/123', 'INV 2026 123', 'ފައިސާ' (UTF-8 kept), 'A&B', 'C#1', and the combination '(R)INV/2026/123 ފައިސާ & #9'. (Retrieve Order cannot echo it because the gateway does not know an order until a payment is attempted, see TASK-14.3.)
Step 2: session for QA-LPELUO4OXFFQPETC at 10.00 MVR, then updateSession(orderAmount 12.00 MVR) -> updateStatus SUCCESS, orderAmount 12.00, new session version c5baa8e002; Retrieve Session afterwards shows 12.00, operation PURCHASE. So the gateway accepts an amount change on a live session.
Step 3 (what the payer sees, 10.00 or 12.00): NOT DONE, the gateway's payment page is down (TASK-14.5); session SESSION0002784034737H0155493K55 also shows 'Payment Page - System Error'.
Step 4: session QA-W3DA7RHTKABBVSBP created 13:20:45 UTC with withTimeout(600) (Retrieve Session interaction.timeout 600). At 13:32:04 UTC (11 min 19 s later) Retrieve Session still succeeds: updateStatus SUCCESS, order 10.00 MVR, interaction.timeout now '0'. FINDING (minor): SessionDetails::isUsable() returns true for that expired session because it only checks updateStatus; an elapsed interaction timeout shows only as interaction.timeout '0'. Nothing in the package calls isUsable() today. What the browser shows for an expired session could not be told apart from the outage.
<!-- SECTION:NOTES:END -->

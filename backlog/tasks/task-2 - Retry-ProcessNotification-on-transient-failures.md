---
id: TASK-2
title: Retry ProcessNotification on transient failures
status: Done
assignee:
  - '@claude'
created_date: '2026-11-01 11:16'
updated_date: '2026-11-02 04:01'
labels:
  - v2-beta
dependencies: []
references:
  - src/Laravel/Jobs/ProcessNotification.php
  - src/Laravel/Outcome/OutcomeProcessor.php
priority: low
type: bug
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`src/Laravel/Jobs/ProcessNotification.php` sets no `$tries` or `$backoff`, so on a worker using the Laravel default of one try, a single gateway timeout during `verifyCapture()` fails the job for good. `failed()` marks the notification `Failed`, and the controller has already answered 200. Any redelivery gets `duplicate` because the notification ID stays claimed.

`mib:reconcile` recovers captures for attempts still awaiting the gateway, but nothing recovers `RefundProcessed` or `VoidProcessed`, which only come from webhooks. Found by reading the code; not reproduced.

Retries only help once a retried job can dispatch again. `OutcomeProcessor::once()` and `dispatchLifecycle()` claimed the transaction before dispatching, so a retry after a listener failure saw a duplicate and the event was lost. The owner approved a design that adds fixing both bugs to this task: money events go out at least once until one dispatch completes, recorded only after `Payable::lock()` has returned, with a stable `deliveryKey`; advisory events stay at most once per notification.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A transient gateway or transport failure is retried with a backoff, without relying on the worker `--tries` option
- [x] #2 A notification is marked `Failed` only after its retries run out
- [x] #3 A permanently malformed job does not retry pointlessly
- [x] #4 Tests cover a failure followed by a successful retry, and running out of retries
- [x] #5 The README states the queue and retry behaviour hosts should expect
- [x] #6 `dispatchLifecycle()` records `RefundProcessed` and `VoidProcessed` only after the dispatch completes, so a retry after a listener failure dispatches them again, and the notification is `Failed`, not `Processed`, if one never completes
- [x] #7 Every money event carries a stable `deliveryKey` built from order ID, transaction ID and outcome, with a documented and tested fallback when there is no transaction ID
- [x] #8 The README states the host contract: listeners idempotent on `deliveryKey`, non-database effects sent after commit, and the database store preferred
- [x] #9 After a listener failure, a retry or mib:reconcile dispatches a money event again until one dispatch completes; once recorded, no trigger dispatches that order ID, transaction ID and outcome again. Repeats remain possible (a crash before recording, a second worker before the record is written, a repeatable-read snapshot, a cache flush), so listeners must be idempotent on deliveryKey. Known limits: a process dying mid-dispatch on a lock with no rollback can leave later listeners unrun, and reconcile does not revisit attempts no longer awaiting the gateway or derive refund and void events, where queue:retry is the recovery. Advisory events go out at most once per notification
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Give ProcessNotification its own tries and backoff, and fail immediately on permanent errors (FailOnException middleware).
2. Claim advisory events (NotificationReceived, UnknownOrderNotification, webhook PaymentPending and PaymentFailed) per notification ID, restoring the claim if a transactional lock rolled it back.
3. Replace claim-before-dispatch for money events with NotificationStore::wasDelivered() and markDelivered(): check, dispatch, record only once Payable::lock() has returned. On any throwable, write no delivery record.
4. Add a stable deliveryKey to money events, with a fallback when there is no transaction ID.
5. Keep the delivery record through an enclosing transaction: database store joins or defers, cache store defers to commit. Keep delivery records out of the TTL and the prune.
6. When a PaymentCaptured dispatch throws, record payment_captured_interrupted, so a settlement that survived on a lock with no rollback does not stop the retry.
7. Prove it through a real queue worker on both stores, and on SQLite, MySQL and PostgreSQL: retries, retries running out, reconcile, the concurrency window, nested locks, ReverseCapture duplicates.
8. README host contract, DESIGN-v2.md decision (Q23), WORKFLOW section 3 and diagram, CHANGELOG, UPGRADE.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Settings: tries 5, backoff 30/120/300/600 s (about 18 min), under the 15-minute SQS delay limit. Permanent (FailOnException, first attempt): MalformedNotification, InvalidRequest, GatewayException 4xx except 401, 403, 408, 425, 429. Everything else retries. The controller claims once and the job never re-claims; failed() runs once at the end; queue:retry can still finish it as Processed.

Money events: wasDelivered(), dispatch, markDelivered() after Payable::lock() returns. Delivery records share the (order, transaction, outcome) row with claimTransaction, so no schema change.

No transaction ID: CaptureMismatch and PaymentReversed use sha1 of status and totals (same from every trigger, a new total is a new event). RefundProcessed and VoidProcessed use sha1 of the notification ID (two partial refunds can look identical; a redelivery under a new ID would repeat).

Retention: cache delivery records have no TTL and mib:prune-notifications keeps delivery rows, because reconcile, AttemptGuard and the return URL have no time limit and an expired record would resend CaptureMismatch and queue another ReverseCapture. Cost: about one small row or key per money event.

Criterion 4 exceptions, disclosed because its wording names only a crash: (a) the owner-approved window: a second worker entering the lock before the first records repeats PaymentCapturedForUnpayableOrder, CaptureMismatch and PaymentReversed, and a PaymentCaptured whose listener saved no settlement; RefundProcessed and VoidProcessed have no lock and repeat on any overlap. Tests show each. (b) A PostgreSQL or MySQL repeatable-read snapshot that started before another worker's record behaves the same way. (c) A cache flush or eviction loses cache records. All are covered by idempotency on deliveryKey.

Added beyond the approved design: the payment_captured_interrupted marker. Without it, a lock with no rollback kept a settlement from an earlier listener, the retry saw a duplicate, and the notification ended Processed with a later listener never completed, which the owner's brief ruled out. Remaining: if the process dies mid-dispatch on such a lock, nothing marks it and the settlement stops the event (pinned by a LIMIT test).

mib:reconcile reaches only what awaitingGateway() returns. It never produces RefundProcessed or VoidProcessed and does not reach an attempt moved to Captured, Failed or Superseded (pinned by a LIMIT test); queue:retry recovers those.

Nested locks: database store joins the host transaction, so a rollback removes the record with the work. Cache store waits for commit through DatabaseTransactionsManager::addCallback; the database store does too when the host transaction is on another connection. Transactions opened outside Laravel's database layer are not seen.

Validation: pest with both servers 777 passed; SQLite only 727 passed, 50 skipped; MySQL only 752 passed, 25 skipped; PostgreSQL only 752 passed, 25 skipped. DatabaseNotificationStoreTest repeated 3 more times on all three, 75 passed each. pint --test, phpstan, rector dry-run and composer validate --strict passed. 18 temporary code removals each made tests fail.

AC reworded by the coordinating session: the earlier wording named only a crash as a cause of repeats, which the tests show is too narrow. Each listed repeat and limit is covered by a test in ProcessNotificationRetryTest or DatabaseNotificationStoreTest, including the LIMIT: tests.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
ProcessNotification retries by itself, and a listener failure no longer loses a money event: OutcomeProcessor checks wasDelivered(), dispatches, and calls markDelivered() only after Payable::lock() has returned, so a retry, queue:retry or mib:reconcile dispatches again until one dispatch completes. Money events carry a stable deliveryKey; advisory events stay at most once per notification; a notification whose money event never completes ends as Failed. Delivery records survive host rollbacks correctly on both stores and are not expired or pruned. README host contract, DESIGN-v2.md Q23, WORKFLOW, CHANGELOG and UPGRADE updated. Verified with pest on SQLite, MySQL and PostgreSQL (777 passed with both servers), pint, phpstan, rector and composer validate, and temporary code removals that made the new tests fail.
<!-- SECTION:FINAL_SUMMARY:END -->

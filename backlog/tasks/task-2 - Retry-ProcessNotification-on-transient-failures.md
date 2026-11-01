---
id: TASK-2
title: Retry ProcessNotification on transient failures
status: To Do
assignee: []
created_date: '2026-11-01 11:16'
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

Retries only help once a retried job can dispatch again. `OutcomeProcessor::once()` and `dispatchLifecycle()` currently claim the transaction before dispatching, so a retry after a listener failure sees a duplicate. Those two bugs were reported separately as fix-before-tagging items and are not tracked by this task.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A transient gateway or transport failure is retried with a backoff, without relying on the worker `--tries` option
- [ ] #2 A notification is marked `Failed` only after its retries run out
- [ ] #3 A permanently malformed job does not retry pointlessly
- [ ] #4 A retry dispatches each outcome event at most once
- [ ] #5 Tests cover a failure followed by a successful retry, and running out of retries
- [ ] #6 The README states the queue and retry behaviour hosts should expect
<!-- AC:END -->

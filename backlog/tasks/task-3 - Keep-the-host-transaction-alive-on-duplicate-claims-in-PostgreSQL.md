---
id: TASK-3
title: Keep the host transaction alive on duplicate claims in PostgreSQL
status: To Do
assignee: []
created_date: '2026-11-01 11:16'
labels:
  - v2-beta
dependencies: []
references:
  - src/Laravel/Stores/DatabaseNotificationStore.php
priority: low
type: bug
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`DatabaseNotificationStore::insert()` (`src/Laravel/Stores/DatabaseNotificationStore.php:57`) detects duplicates by catching `UniqueConstraintViolationException`. `claimTransaction()` runs inside `Payable::lock()`, normally a host `DB::transaction`. On PostgreSQL a failed insert aborts the whole transaction, so later host queries in that lock fail with "current transaction is aborted", and a commit silently rolls back.

This matters most for `AttemptGuard`, which the design says to call inside the lock, followed by more host work. Found by reading the code; not run on PostgreSQL. MySQL hosts such as ISIMS are not affected. The current suite uses SQLite and the cache store, so it would not catch this.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A duplicate `claim()` or `claimTransaction()` returns false without raising a database error, on MySQL, PostgreSQL and SQLite
- [ ] #2 Inside an open PostgreSQL transaction, a duplicate claim leaves the transaction usable and its other writes commit
- [ ] #3 The store still tells the first claimer true and every later claimer false under concurrency
- [ ] #4 Tests cover duplicates inside a transaction, and PostgreSQL behaviour has been verified by running it
<!-- AC:END -->

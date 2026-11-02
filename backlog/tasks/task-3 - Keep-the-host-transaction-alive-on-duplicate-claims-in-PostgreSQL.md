---
id: TASK-3
title: Keep the host transaction alive on duplicate claims in PostgreSQL
status: Done
assignee:
  - '@claude'
created_date: '2026-11-01 11:16'
updated_date: '2026-11-01 15:33'
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
- [x] #1 A duplicate `claim()` or `claimTransaction()` returns false without raising a database error, on MySQL, PostgreSQL and SQLite
- [x] #2 Inside an open PostgreSQL transaction, a duplicate claim leaves the transaction usable and its other writes commit
- [x] #3 The store still tells the first claimer true and every later claimer false under concurrency
- [x] #4 Tests cover duplicates inside a transaction, and PostgreSQL behaviour has been verified by running it
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Probe insertOrIgnore, ON CONFLICT and a savepoint on real MySQL 8.4, PostgreSQL 17 and SQLite.
2. Add tests/Laravel/DatabaseNotificationStoreTest.php plus a race worker: same tests on all three databases, MySQL and PostgreSQL skipped unless MIB_TEST_MYSQL_DSN / MIB_TEST_PGSQL_DSN are set.
3. Confirm the new tests fail on the unfixed store.
4. Fix DatabaseNotificationStore::insert(): keep the plain insert and UniqueConstraintViolationException catch; when already in a transaction wrap the insert in a nested transaction (savepoint).
5. Run tests on every database, with multi-process races; run pest, pint, phpstan, rector.
6. CI service containers, CHANGELOG line, remove containers.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Probe results (real MySQL 8.4.13, PostgreSQL 17.11, SQLite 3.54.1):
- Bug reproduced on PostgreSQL: after a caught duplicate the next query fails with 25P02 and the commit does not persist.
- insertOrIgnore rejected. MySQL INSERT IGNORE truncated two different over-long order IDs to one value and reported the second as a duplicate (0 rows), and stored '' for a NULL in a NOT NULL column. SQLite INSERT OR IGNORE also swallows NOT NULL failures.
- ON CONFLICT DO NOTHING on PostgreSQL alone rejected: it surfaces 40001 under REPEATABLE READ and SERIALIZABLE when the row was committed after the snapshot, where the plain insert gives 23505, i.e. a correct false. It would also need a driver branch, and getDriverName() is not on ConnectionInterface.
- Savepoint chosen: correct on all three, non-duplicate errors still surface and leave the host transaction usable, and classification stays with Laravel's UniqueConstraintViolationException. Outside a transaction it is not needed, so that path stays a plain insert.
- NULLs: the indexes never conflict across claim() and claimTransaction() rows on any of the three because NULLs are distinct, so no conflict target is needed. Empty order_id is a real value, so duplicates are detected.

Fix: DatabaseNotificationStore::insert() wraps the insert in a nested transaction (savepoint) when transactionLevel() > 0; outside a transaction it stays a plain insert. Duplicates are still detected by UniqueConstraintViolationException.
Run on real MySQL 8.4.13 and PostgreSQL 17.11 (throwaway podman containers, now removed), and SQLite 3.54.1, using Homebrew PHP 8.5.11 because the lerd php shim runs in a container and drops env vars.
- Full suite with both DSNs set: 612 passed. New test file: 47 passed, 5 repeat runs also all passed.
- No servers (default): 580 passed, 32 skipped.
- Without the fix: 14 of 47 new tests fail with both DSNs set, mostly PostgreSQL 25P02; with no servers 1 fails (the SQLite savepoint test).
- Mutation checks: insertOrIgnore fails 9 tests; no unique index with check-then-insert fails 14 of 18 race tests, so the race harness does race.
- Stress: 12 processes x 100 keys, 3 modes, 2 claim kinds, each database: no key had two winners and no false without a committed claim. Rollback mode on MySQL gave 70 InnoDB deadlock errors, surfaced as errors rather than false.
CI: services and env added to .github/workflows/tests.yml; YAML parses, but not run because nothing is pushed.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A duplicate claim inside a host transaction no longer aborts that transaction on PostgreSQL: DatabaseNotificationStore runs the insert in a savepoint when a transaction is open. insertOrIgnore was rejected because it produces false duplicates and swallows NOT NULL failures on MySQL. Verified by running the new test file and multi-process races on real MySQL 8.4, PostgreSQL 17 and SQLite, by the tests failing without the fix, and by pest, pint, phpstan and rector passing.
<!-- SECTION:FINAL_SUMMARY:END -->

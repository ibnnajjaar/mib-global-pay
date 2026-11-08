---
id: TASK-13
title: Drop the PostgreSQL runs of the database store tests
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 10:59'
updated_date: '2026-11-08 11:01'
labels:
  - tests
dependencies: []
priority: low
type: chore
ordinal: 13000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The project owner decided PostgreSQL coverage is not needed. The MySQL and PostgreSQL runs of tests/Laravel/DatabaseNotificationStoreTest.php were always skipped locally, and CI started a postgres:17 service only for them. SQLite and MySQL runs stay. The savepoint handling in DatabaseNotificationStore (TASK-3) stays in src, so PostgreSQL hosts keep working but that behaviour is no longer tested.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 No test in the suite runs against PostgreSQL or reads MIB_TEST_PGSQL_DSN
- [x] #2 CI no longer starts a PostgreSQL service, sets MIB_TEST_PGSQL_DSN or installs pdo_pgsql
- [x] #3 The store tests pass on SQLite and MySQL with no skips when MIB_TEST_MYSQL_DSN is set
- [x] #4 The full suite, Pint, PHPStan and Rector pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Remove pgsql from the datasets, storeTestConfig() and the pgsql-only branches in DatabaseNotificationStoreTest.php, and update its header comment.
2. Remove the postgres service, MIB_TEST_PGSQL_DSN and pdo_pgsql from .github/workflows/tests.yml.
3. Run the store tests on SQLite and MySQL in the lerd PHP container, then the full suite, Pint, PHPStan and Rector.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Removed pgsql from the 'store databases' dataset, storeTestConfig() and the two repeatable-read tests (now MySQL only, isolation set before beginTransaction as MySQL needs). Removed the postgres service, MIB_TEST_PGSQL_DSN and pdo_pgsql from .github/workflows/tests.yml. grep finds no pgsql/postgres in tests/ or .github/. Locally, lerd's php shim does not pass env vars into the container, so the MySQL run used: podman exec -e MIB_TEST_MYSQL_DSN=mysql://root:lerd@lerd-mysql:3306/mib_store_test -w $PWD lerd-php85-fpm php vendor/bin/pest. Results: store tests 50 passed, 0 skipped; full suite 1038 passed, 0 skipped; Pint, PHPStan (max) and Rector clean. DatabaseNotificationStore's savepoint code (TASK-3) is unchanged, so PostgreSQL hosts still work but that path is now untested.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Dropped the PostgreSQL runs of DatabaseNotificationStoreTest and the CI postgres service, at the owner's request. SQLite and MySQL runs remain. Verified with the store tests on MySQL in the lerd PHP 8.5 container (50 passed, none skipped), the full suite (1038 passed), Pint, PHPStan and Rector.
<!-- SECTION:FINAL_SUMMARY:END -->

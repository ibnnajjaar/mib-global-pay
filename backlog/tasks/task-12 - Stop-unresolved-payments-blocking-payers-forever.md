---
id: TASK-12
title: Stop unresolved payments blocking payers forever
status: Done
assignee:
  - '@claude'
created_date: '2026-11-02 08:30'
updated_date: '2026-11-02 08:33'
labels:
  - bug
dependencies: []
priority: high
ordinal: 12000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
A transaction that stays TIMED_OUT or pending reads as Pending, so AttemptGuard refuses new attempts with no way out. Provider guidance (Stripe, Gr4vy) is to keep the outcome unresolved rather than let a timer clear it, reconcile, and have a person decide after about 24 hours. MIB has not said whether TIMED_OUT is final (question 30 in docs/mib-questions.md), so automatic retry stays out of scope until it answers.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A new attempt is still refused while the gateway reports the earlier attempt as pending, including after it expires
- [x] #2 After a configurable period, default 24 hours, the pipeline dispatches PaymentNeedsReview once instead of PaymentPending
- [x] #3 A person can confirm an attempt as unpaid, which re-reads the gateway first, refuses if money moved, records who did it, and unblocks a new attempt
- [x] #4 mib:reconcile keeps checking superseded and confirmed-unpaid attempts for a configurable period, without repeating failure events
- [x] #5 A late capture after a newer payment is treated as a double capture and reversed automatically by default
- [x] #6 README, CHANGELOG, DESIGN-v2.md and the MIB questions are updated, and tests, PHPStan, Pint and Rector pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Keep refusing new attempts while the outcome is pending.
2. Dispatch PaymentNeedsReview once after a configurable period, measured from the latest transaction.
3. Add confirmUnpaid(), which re-reads the gateway and records who decided.
4. Keep retired attempts in reconcile for a period, reporting only money.
5. Turn double-capture auto-reversal on by default.
6. Update docs and tests.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Validation: Pest 1013 passed and 50 skipped, PHPStan, Pint and Rector clean. Not run against the gateway. The 24-hour review and 7-day watch periods are chosen defaults, not gateway rules. Automatic retry on TIMED_OUT waits for MIB question 30. confirmUnpaid() exists for the package attempt model; hosts with their own storage set their attempt to Failed themselves.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Unresolved payments now escalate to a person after 24 hours through PaymentNeedsReview, confirmUnpaid() releases the payer after re-reading the gateway, reconcile watches retired attempts for late charges, and double-capture reversal is on by default. Verified with Pest, PHPStan, Pint and Rector; not run against the gateway.
<!-- SECTION:FINAL_SUMMARY:END -->

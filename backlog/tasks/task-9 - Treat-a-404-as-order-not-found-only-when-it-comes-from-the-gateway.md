---
id: TASK-9
title: Treat a 404 as order not found only when it comes from the gateway
status: Done
assignee:
  - '@claude'
created_date: '2026-11-02 04:14'
updated_date: '2026-11-02 05:02'
labels:
  - v2-beta
dependencies: []
references:
  - src/Core/Verification/CaptureVerifier.php
  - src/Core/MibGateway.php
  - src/Core/Reversal/ReversalHelper.php
  - src/Laravel/Testing/MibGlobalPayFake.php
  - >-
    https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/apiReference/apiRefAPIErrorHandling.html
priority: high
type: bug
ordinal: 9000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`CaptureVerifier::isOrderNotFound()` (`src/Core/Verification/CaptureVerifier.php:94`) returns true for every HTTP 404, whatever the body. A 404 HTML page from a wrong host, a proxy or a mistyped base URL therefore becomes `NotFound`, which the design treats as unpaid. `AttemptGuard` then allowed a new attempt in a reproduction, while an earlier attempt could already hold the money, so the payer could be charged twice. It can also reach `PaymentFailed`.

The check is shared by `MibGateway::verifyCapture()`, `MibGlobalPayFake::verifyCapture()` and, since TASK-1, `ReversalHelper::readVoid()`, where "absent" can allow a refund if the re-read order agrees.

MIB has not confirmed what an unknown order returns (question 13 in `docs/mib-questions.md`, question 17 in `DESIGN-v2.md` section 12), and no sandbox call has been made. The published docs describe the error format but not this case. `DESIGN-v2.md:255` also says "HTTP 400/404 with order not found", which implies a text check on 404s that does not exist. This must be fixed before tagging `v2.0.0-beta.1`.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A 404 or 400 that is not a gateway JSON error (HTML, empty or other JSON) is not treated as not found: `verifyCapture()` throws instead of returning `NotFound`
- [x] #2 With that response, `AttemptGuard` allows no new attempt and `PaymentFailed` is not dispatched, and `ReversalHelper` sends no refund
- [x] #3 A gateway-shaped not-found error still maps to `NotFound`, covering every form the package accepts
- [x] #4 The not-found rule is checked against a real sandbox response for an unknown order, or stays listed as unverified in `DESIGN-v2.md`, with the assumed format stated
- [x] #5 Tests cover each response shape through `verifyCapture()` and `AttemptGuard`, and fail without the fix
- [x] #6 `DESIGN-v2.md` and the README describe the actual rule
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Read the MIB error docs and define gateway-shaped from them. 2. Add GatewayException::isGatewayError() and tighten CaptureVerifier: documented format + INVALID_REQUEST + no field details, then 404 any wording or 400 naming the order. 3. Give readVoid a transaction variant and gate the void's own 400. 4. Useful message from MibGateway. 5. Fake follows the same shape. 6. ProcessNotification::isPermanent only for gateway errors. 7. Tests through verifyCapture, AttemptGuard, ReversalHelper, jobs and reconcile, and mutation-check them. 8. Update DESIGN-v2, README, WORKFLOW, CHANGELOG, mib-questions.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Docs: API Error Handling gives 400 malformed and 401 auth, client errors usually INVALID_REQUEST; Transaction Errors and Retrieve Order define result ERROR, the four causes, field/validationType; explanation format cannot be guaranteed. None mention HTTP 404 or an unknown order, so the applied format is an assumption, listed as unverified in DESIGN-v2.md 4.2.
Decisions: gateway-shaped 404 counts with no wording (requiring wording depends on text the docs do not guarantee). 400 needs wording naming the order, anchored at the start. Any field or validationType means no. isTransactionNotFound for readVoid. A void 400 not in the format is rethrown with no read and no refund. isPermanent: a 4xx not in the format is retried, gateway 4xx stays permanent.
False negative cost: every abandoned attempt throws from verifyCapture, AttemptGuard throws for that payable, payer cannot retry; no money moves; reconcile shows error rows and failed exit, jobs end Failed.
Validation: new tests failed under 11 mutations in a scratch copy, including the old rule (81 failures). No gateway request was made.

Final checks: pest 899 passed, 50 skipped; pint --test passed; phpstan no errors; rector dry-run clean; composer validate --strict valid.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
NotFound now needs a body in the documented error format with cause INVALID_REQUEST and no field details, then HTTP 404 with any wording or HTTP 400 saying the order is missing. Anything else throws GatewayException with a message naming the host. AttemptGuard allows no new attempt, PaymentFailed is not dispatched, and ReversalHelper sends no refund for such a response. readVoid uses the new isTransactionNotFound, and a void 400 not in the format is rethrown. ProcessNotification retries a 4xx not in the format. The fake follows the same rule. The assumed format is listed as unverified in DESIGN-v2.md 4.2, since no sandbox response exists. Verified with pest (899 passed, 50 skipped), pint, phpstan, rector, composer validate, and 11 mutations in a scratch copy that all failed tests.
<!-- SECTION:FINAL_SUMMARY:END -->

---
id: TASK-10
title: Align Hosted Checkout behaviour with the gateway docs
status: Done
assignee:
  - '@claude'
created_date: '2026-11-02 06:50'
updated_date: '2026-11-02 06:54'
labels:
  - bug
dependencies: []
priority: high
ordinal: 10000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
An audit of the package against the Hosted Checkout guides and the v100 Initiate Checkout reference found flows the package accepts but misreads, a component that strands payers on errors, promised 2.0 options that are missing, and package docs that disagree with the code. AUTHORIZE, VERIFY and NONE are the most serious: the outcome pipeline only understands money captured in full, so a good authorization can end as PaymentFailed and allow a second attempt while funds are held. Full authorize-and-capture support is a 2.x design question and is out of scope here.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The Laravel initiateCheckout entry point rejects operations other than PURCHASE, and the docs state that verifyCapture, AttemptGuard and PaymentOutcome support PURCHASE only
- [x] #2 A VERIFY checkout can be sent with a zero amount, and a zero amount is still rejected for every other operation
- [x] #3 GatewayConfig rejects API versions below 67
- [x] #4 The Blade component supports payment and embedded modes, optional Checkout.js callbacks, and shows the payer a visible message on an error by default
- [x] #5 CheckoutRequest has typed display controls for billing address, customer email, shipping and payment terms
- [x] #6 The merchant logo URL must be HTTPS even when insecure URLs are allowed
- [x] #7 README, CHANGELOG, DESIGN-v2.md, the MIB questions and the local Hosted Checkout notes match the code
- [x] #8 Tests, static analysis, lint and Rector checks pass
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Reject non-PURCHASE operations in the Laravel initiateCheckout entry point and document the PURCHASE-only pipeline.
2. Add CheckoutRequest::forVerification() so only VERIFY can send a zero amount.
3. Reject API versions below 67.
4. Add payment and embedded modes, callback props and a visible default error to the Blade component.
5. Add typed display controls and always require HTTPS for the logo.
6. Document that the return parser fields are not gateway documented.
7. Update README, CHANGELOG, DESIGN-v2.md, MIB questions and local notes.
8. Add tests, then run tests, analysis, lint and Rector.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Validation: Pest 915 passed and 50 skipped (899 passed before this work), PHPStan, Pint and Rector clean. The Blade JavaScript was checked as rendered HTML only, not in a browser. Nothing was run against the gateway, so zero-amount VERIFY, the Embedded Page and the removed default cancel callback are unconfirmed there. The direct core path still misreads AUTHORIZE, VERIFY and NONE, which is documented rather than blocked. TIMED_OUT behaviour is unchanged and is question 30 in docs/mib-questions.md.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
The Laravel initiateCheckout entry point now refuses operations other than PURCHASE, VERIFY can send a zero amount through forVerification(), and API versions below 67 are rejected. The checkout component gained embedded mode, callback props and a visible error, CheckoutRequest gained typed display controls, and logos must be HTTPS. README, CHANGELOG, DESIGN-v2.md and local notes were updated. Verified with Pest, PHPStan, Pint and Rector; not run against the gateway.
<!-- SECTION:FINAL_SUMMARY:END -->

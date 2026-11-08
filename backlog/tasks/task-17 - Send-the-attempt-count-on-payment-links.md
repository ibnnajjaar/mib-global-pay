---
id: TASK-17
title: Send the attempt count on payment links
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 14:12'
updated_date: '2026-11-08 14:15'
labels:
  - qa
  - payment-links
dependencies: []
priority: high
type: bug
ordinal: 17017
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Found in TASK-14.12 against the MIB test gateway (2026-11-08). Initiate Checkout with a paymentLink but no numberOfAllowedAttempts is refused: HTTP 400 'Missing parameter', field paymentLink.numberOfAllowedAttempts, validationType MISSING. CheckoutRequest::withPaymentLink(), CheckoutStarter::paymentLink() and MibGlobalPay::startPaymentLink() treat the count as optional and leave it out when null, and README's example startPaymentLink($invoice, now()->addDays(7)) relies on that, so it always fails. The gateway docs say the default is 25 attempts. Payment links are not enabled for the test merchant (MSO lacks PAYMENT_LINK), so the owner does not want further live testing of links; this is a request-shape fix only.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A payment link request always sends paymentLink.numberOfAllowedAttempts, 25 when the caller gives none
- [x] #2 Explicit counts are still sent as given and values below 1 are still rejected
- [x] #3 Docblocks and README say the count defaults to 25 and is always sent
- [x] #4 Tests cover the default and an explicit count
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. CheckoutRequest: add a DEFAULT_PAYMENT_LINK_ATTEMPTS = 25 constant and send it when no count is given.
2. Update docblocks (CheckoutRequest::withPaymentLink, CheckoutStarter::paymentLink, MibGlobalPay::startPaymentLink) and README.
3. Tests for the default and an explicit count; CHANGELOG.
4. Full checks.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
CheckoutRequest::DEFAULT_PAYMENT_LINK_ATTEMPTS = 25, sent by paymentLinkFields() when the count is null. Docblocks (CheckoutRequest::withPaymentLink, CheckoutStarter::paymentLink, MibGlobalPay::startPaymentLink), README 'Payment links' (also notes the PAYMENT_LINK privilege), DESIGN-v2.md feature table and CHANGELOG updated. Tests: HostedCheckoutFeaturesTest dataset (null -> 25, 1 -> 1, 7 -> 7) and ManagedCheckoutTest startPaymentLink without a count sends 25; both fail with the default removed. Zero attempts is still rejected by the existing 'zero attempts' case. Full suite 1051 passed; Pint, PHPStan, Rector clean. Not tested live: payment links are disabled for the test merchant, and the owner chose not to test them further.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Payment link requests now always send paymentLink.numberOfAllowedAttempts, 25 by default, because the MIB gateway rejects a link without it; docs updated. Verified with new unit and Laravel tests (failing without the change), the full suite (1051 passed), Pint, PHPStan and Rector. Not run live: links are disabled for the test merchant.
<!-- SECTION:FINAL_SUMMARY:END -->

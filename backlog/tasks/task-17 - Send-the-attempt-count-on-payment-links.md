---
id: TASK-17
title: Send the attempt count on payment links
status: To Do
assignee:
  - '@claude'
created_date: '2026-11-08 14:12'
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
- [ ] #1 A payment link request always sends paymentLink.numberOfAllowedAttempts, 25 when the caller gives none
- [ ] #2 Explicit counts are still sent as given and values below 1 are still rejected
- [ ] #3 Docblocks and README say the count defaults to 25 and is always sent
- [ ] #4 Tests cover the default and an explicit count
<!-- AC:END -->

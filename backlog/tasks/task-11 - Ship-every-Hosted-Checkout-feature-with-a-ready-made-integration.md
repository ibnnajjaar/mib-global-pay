---
id: TASK-11
title: Ship every Hosted Checkout feature with a ready-made integration
status: Done
assignee:
  - '@claude'
created_date: '2026-11-02 07:47'
updated_date: '2026-11-02 08:03'
labels:
  - enhancement
dependencies: []
priority: high
ordinal: 11000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The maintainer wants this release to cover all documented Hosted Checkout features, with no deferral to 2.x, and wants hosts to integrate without writing Checkout.js or attempt bookkeeping. This reverses the 2.x phasing in DESIGN-v2.md §14 for Hosted Checkout, the PURCHASE-only limit added in TASK-10, and the rule that hosts own attempt storage (now optional, not required). Non-Hosted Checkout integrations such as Hosted Session, browser payment APIs, wallets APIs, risk and reporting are not part of this.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 AUTHORIZE, VERIFY, NONE and partial capture flow through verification, AttemptGuard and the Laravel pipeline with their own statuses and events
- [x] #2 Payment Links can be created and deleted
- [x] #3 Credential on File works: save card, tokens on checkout, agreements, token retrieve and delete, server-side PAY, AUTHORIZE and VERIFY with a token
- [x] #4 REFERRAL, Plan Offers Inquiry, Retrieve Agreement and capture with updated order data are typed
- [x] #5 CheckoutRequest has typed customer, billing, shipping, statement descriptor, line item, airline, cruise and payment plan data
- [x] #6 Payment Page, Embedded Page and modal work without host JavaScript, in core PHP and in Blade
- [x] #7 A Laravel host can take a payment with a model trait, one checkout call and package-provided routes, attempt storage and resolver
- [x] #8 README, CHANGELOG, DESIGN-v2.md, tests, PHPStan, Pint and Rector are up to date and passing
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add typed request data, Payment Links, tokens, agreements and server-side operations from the v100 reference.
2. Add Authorized, Verified and PartiallyCaptured to verification, the guard and the pipeline.
3. Add CheckoutJs for all three displays.
4. Add the attempts table, trait, resolver, starter, routes and pages.
5. Update docs and tests, then run all checks.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Validation: Pest 999 passed and 50 skipped, PHPStan, Pint and Rector clean. Generated JavaScript parsed and ran in Node against a stubbed Checkout object for all three displays. Nothing ran against the real gateway or in a browser. Found and fixed a bug during testing: a refusal thrown inside Payable::lock() rolled back outcomes the guard had just recorded. Where the guide and the v100 reference disagree, the reference wins: deferralPeriod, top-level agreement, transaction.source MERCHANT instead of storedCredential.initiator. order.item follows the guide because the reference does not list it. Unconfirmed on the gateway: Payment Links, tokens, agreements, server-side payments, Plan Offers, order.item, the location of the saved token, and the 1-6 character referral code limit, which is an assumption.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Added every documented Hosted Checkout feature, CheckoutJs for any PHP app, and a ready-made Laravel integration with startCheckout(), a model trait, attempt storage, routes and pages. Verified with Pest, PHPStan, Pint, Rector and Node execution of the generated JavaScript; not run against the gateway.
<!-- SECTION:FINAL_SUMMARY:END -->

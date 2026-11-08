---
id: TASK-14
title: 'QA: end-to-end test v2 against the MIB test gateway'
status: To Do
assignee: []
created_date: '2026-11-08 11:09'
updated_date: '2026-11-08 11:37'
labels:
  - qa
dependencies: []
references:
  - DESIGN-v2.md
  - README.md
  - docs/hosted-checkout/testingSteps.md
  - docs/mib-questions.md
priority: high
type: task
ordinal: 14000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Why
v2.0.0-beta.1 passes 1038 automated tests, but every gateway call in them is mocked. No authenticated flow has ever run against the real MIB test gateway, and several gateway behaviours the package depends on are unconfirmed (DESIGN-v2.md, top of file, and §12 questions for MIB). The bank has provided test gateway credentials, now in this repo's git-ignored `.env` (`MIB_GATEWAY_URL`, `MIB_MERCHANT_ID`, `MIB_API_PASSWORD`, `MIB_WEBHOOK_SECRET`, `MIB_CHECKOUT_JS_URL`). These subtasks are the manual and browser QA that must pass before tagging 2.0.0.

## How the subtasks fit together
1. "Build the QA harness app" comes first. No existing app uses v2 (`isims` and `merchant` are on 1.x), so QA runs in a small throwaway Laravel app that requires this package by path.
2. The API-only checks (connectivity, unknown order) need no browser and answer the most important open questions, so run them next.
3. The browser flows each start from the harness dashboard at `{APP}/qa` and finish by checking the dashboard, the database and the recorded events.

## Test cards (confirmed by the owner for these tests)
Source: https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/testAndGoLive.html?locale=en_US (read on 2026-11-08; the page needs JavaScript or a full HTML download to show its tables).

Standard test cards (all regions):
| Scheme | Card numbers |
| --- | --- |
| Mastercard | 5123450000000008, 2223000000000007, 5111111111111118, 2223000000000023 |
| Visa | 4508750015741019, 4012000033330026 |
| American Express | 371881634498004, 371881127160004 (CSC is 4 digits) |

The expiry date chooses the transaction result:
| Expiry | `response.gatewayCode` |
| --- | --- |
| 01/39 | APPROVED |
| 05/39 | DECLINED |
| 04/27 | EXPIRED_CARD |
| 08/28 | TIMED_OUT |
| 01/37 | ACQUIRER_SYSTEM_ERROR |
| 02/37 | UNSPECIFIED_FAILURE |
| 05/37 | UNKNOWN |

The CSC chooses the CSC result: 100 MATCH, 101 NOT_PROCESSED, 102 NO_MATCH (Amex: 1000, 1010, 1020).
The billing address street chooses the AVS result, for example Alpha St ADDRESS_MATCH, November St NO_MATCH, Zero St NOT_REQUESTED.

Credential on File (from MIB's guide, docs/hosted-checkout/credentialonfileHCO.md): 4111111111111111, any future expiry, any 3-digit CSC.

3-D Secure: "3-D Secure enrolled" cards are listed on https://test-mib.mtf.gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/pickAdditionalFunctionality/authentication/3DS/test_your_integration.html?locale=en_US

Default for the subtasks: Mastercard 5123450000000008, CSC 100, with expiry 01/39 for "approved", 05/39 "declined", 04/27 "expired" and 08/28 "timed out". ACQUIRER_SYSTEM_ERROR, UNSPECIFIED_FAILURE and UNKNOWN (01/37, 02/37, 05/37) are useful extra cases for the failure and pending subtasks.

## Rules for every subtask
- Test gateway and TEST merchant only. Never a real card. Never print or store the API password or webhook secret anywhere visible.
- Package code is not changed during QA. A difference from the expected result is recorded as a finding with evidence (order ID, notes, GIF) and reported to the owner.
- Record GIFs with Claude in Chrome's gif_creator, named after the subtask.

## Out of scope
- Production. The production host is still unknown (MIB question 14).
- Double-capture auto-reversal on the real gateway. It needs two captured attempts for one payable, which the package's guards are designed to prevent, so it stays covered by the automated tests.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Every QA subtask is Done, with its observed results and evidence in its notes
- [ ] #2 Every finding (a difference from the expected result) is listed in this task's notes with the subtask and gateway order ID
- [ ] #3 The answers the QA found to the open MIB questions (DESIGN-v2.md §12: Q3, Q4, Q5-6, Q16, Q17, Q18, Q19 and the enabled features) are summarised in this task's notes
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Answers from the first real payment (TASK-14.4, 2026-11-08):
- Q19 webhook headers: X-Notification-Attempt starts at 1; X-Notification-Id is present (sent as "X-Notification-Id", so lower-case d; HTTP headers are case-insensitive); X-Notification-Secret present; User-Agent AHC/2.1; Content-Type application/json;charset=utf-8.
- Q18 webhook body: standard transaction shape (merchant, order.*, transaction.*, response.*, result, version "100", timeOfRecord, plus authentication.* and authorizationResponse.*). One payment with 3DS produced TWO notifications: transaction.type AUTHENTICATION (id trans-60) and PAYMENT (id 1).
- 3-D Secure is enabled by default on the test merchant (ACS Emulator for 3DS V2 shown with no withThreeDSecure() flag).
- Hosted Payment Page shows the amount as "MVR Rf10.00".
- FINDING (package): composer require on a fresh Laravel 13 app fails because the package requires guzzlehttp/guzzle ^7.9 while Laravel 13 ships Guzzle 8 (see TASK-14.1).
- FINDING (config): MIB_WEBHOOK_SECRET in .env does not match the merchant's notification secret; real webhooks were rejected with 401.
<!-- SECTION:NOTES:END -->

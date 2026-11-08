---
id: TASK-19
title: Let hosts detect an operation the merchant has not enabled
status: Done
assignee:
  - '@claude'
created_date: '2026-11-08 14:54'
updated_date: '2026-11-08 14:55'
labels:
  - qa
  - errors
dependencies: []
priority: low
type: enhancement
ordinal: 19017
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Follow-up to TASK-18, approved by the owner (2026-11-08). The MIB gateway rejects an operation that is not enabled for the merchant with HTTP 400 {"error":{"cause":"INVALID_REQUEST","explanation":"Value AUTHORIZE is invalid. Merchant TESTIUMMPGS is not enabled for AUTHORIZE., please contact your Service Provider for expected values.","field":"interaction.operation","validationType":"INVALID"},"result":"ERROR"} (TASK-14.9). Hosts can only recognise it by reading error.field themselves. The agreed approach is a helper on GatewayException that relies on the structured fields, not the explanation text, which the docs say is not guaranteed.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 GatewayException::isOperationNotEnabled() is true for the observed response and false when the body is not a gateway error, the cause differs, the field differs, or validationType differs
- [x] #2 README shows how to use it
- [x] #3 CHANGELOG notes it
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. GatewayException::isOperationNotEnabled(): HTTP 400, gateway error format, cause INVALID_REQUEST, field interaction.operation, validationType INVALID.
2. Dataset test in tests/Core/ExceptionsTest.php with the observed body and near misses.
3. README Errors section and CHANGELOG.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Added GatewayException::isOperationNotEnabled() (HTTP 400 + gateway format + INVALID_REQUEST + field interaction.operation + validationType INVALID). ExceptionsTest dataset: the observed MIB body and a no-explanation variant are true; another field, MISSING, another cause, non-gateway format, HTTP 401 and no body are false. README Errors section shows a catch example; CHANGELOG 'Added'. Full suite 1058 passed; Pint, PHPStan, Rector clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Hosts can now detect a disabled operation with GatewayException::isOperationNotEnabled(), based on the structured fields of the MIB gateway's observed response. Verified with a dataset test of the real body and near misses, the full suite (1058 passed), Pint, PHPStan and Rector.
<!-- SECTION:FINAL_SUMMARY:END -->

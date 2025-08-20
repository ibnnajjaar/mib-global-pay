# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning (SemVer).

- Keep a Changelog: https://keepachangelog.com/en/1.1.0/
- Semantic Versioning: https://semver.org/spec/v2.0.0.html

## [1.1.0] - 2025-08-20
### Added
- Order reference field support in `OrderData` and request payloads.
  - New setter: `setOrderReference(string $reference)`.
  - `CreateTransactionRequest` now includes `order.reference` when provided.
  - Tests updated to cover the new field and expected payload.

### Notes
- This is a backwards-compatible feature addition; thus, a MINOR version bump from 1.0.1 to 1.1.0 is appropriate per SemVer.

## [1.0.1] - 2025-??-??
- Previous patch release (prior to maintaining a formal changelog). Details may be found in Git history.

[1.1.0]: https://github.com/ibnnajjaar/mib-global-pay/compare/v1.0.1...v1.1.0

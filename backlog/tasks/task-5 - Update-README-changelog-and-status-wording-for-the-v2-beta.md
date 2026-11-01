---
id: TASK-5
title: 'Update README, changelog and status wording for the v2 beta'
status: To Do
assignee: []
created_date: '2026-11-01 11:16'
labels:
  - v2-beta
dependencies: []
references:
  - README.md
  - CHANGELOG.md
  - DESIGN-v2.md
priority: medium
type: docs
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The release docs describe a finished 2.0.0, which would mislead anyone installing `v2.0.0-beta.1`.

- `README.md:37` says `composer require ibnnajjaar/mib-global-pay:^2.0`, which will not install a beta on a project with the default stable-only setting.
- `CHANGELOG.md:11` says "Ready to be released as 2.0.0" and `DESIGN-v2.md:3` says "ready for 2.0.0", while the design doc lists gateway behaviour as still unverified.
- `CHANGELOG.md:24` says "The 1.x API is unchanged", but the same release removes it.
- `CHANGELOG.md:53` ("Included from the unreleased 1.2 work") lists additions such as `MIBGlobalPayConnector::refund()` as shipping in 2.0.0, although 2.0 removes those classes.

The unverified gateway items are in `DESIGN-v2.md`, §12 and `docs/mib-questions.md` (not tracked in git).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The README install command works for the beta, and has been tried in a clean Composer project
- [ ] #2 The README and `DESIGN-v2.md` state it is a beta, and which gateway behaviour is still unverified
- [ ] #3 The changelog has a beta heading, its date, and a working compare link at the bottom
- [ ] #4 The changelog no longer says the 1.x API is unchanged
- [ ] #5 The 1.2 section no longer presents removed APIs as shipping, while its fixes that still apply to v2 stay listed
<!-- AC:END -->

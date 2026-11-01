---
id: TASK-6
title: Exclude development files from the package archive
status: To Do
assignee: []
created_date: '2026-11-01 11:16'
updated_date: '2026-11-01 11:43'
labels:
  - v2-beta
dependencies: []
references:
  - composer.json
priority: medium
type: chore
ordinal: 6000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The repository has no `.gitattributes`, so Composer dist installs include everything tracked in git: `tests/`, `.github/`, the tooling configs including `rector.php`, `backlog/`, `WORKFLOW.md` and the 103 KB `DESIGN-v2.md`. Hosts only need the runtime files.

The tests and tooling configs still need to be available from a source checkout, because CI runs from one.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A clean install from the archive boots the service provider, publishes the config and migration, and renders the checkout component
- [ ] #2 CI still passes from a source checkout
- [ ] #3 Links in the README to excluded files are absolute URLs, or are removed
- [ ] #4 `git archive HEAD` contains `src`, `config`, `database`, `resources`, `composer.json`, `README.md`, `UPGRADE.md`, `CHANGELOG.md` and the licence text if one is added, and no tests, tooling or CI files
<!-- AC:END -->

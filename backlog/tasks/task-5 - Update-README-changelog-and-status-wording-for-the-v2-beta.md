---
id: TASK-5
title: 'Update README, changelog and status wording for the v2 beta'
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-01 11:16'
updated_date: '2026-11-02 02:13'
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
- [x] #1 The README install command works for the beta, and has been tried in a clean Composer project
- [x] #2 The README and `DESIGN-v2.md` state it is a beta, and which gateway behaviour is still unverified
- [ ] #3 The changelog has a beta heading, its date, and a working compare link at the bottom
- [x] #4 The changelog no longer says the 1.x API is unchanged
- [x] #5 The 1.2 section no longer presents removed APIs as shipping, while its fixes that still apply to v2 stay listed
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Build a zip at 2.0.0-beta.1 from a temp index and test the install command in clean Composer projects (fresh Laravel 13, core-only), including plain ^2.0 and no --with-all-dependencies.
2. Update README install command and add the beta status, naming the unverified gateway behaviour from DESIGN-v2.md. Update UPGRADE.md.
3. Update DESIGN-v2.md status lines and check the unverified list against src and section 12.
4. Update CHANGELOG: beta heading, compare link, tag-day instruction, remove the 1.x unchanged sentence, check each 1.2 item against src and keep only what still applies, restated on v2 classes.
5. Probe the kept 1.2 items with a script, script a link check over the shipped docs, rerun the install from the final README text.
6. Run pest, pint, phpstan, rector and composer validate. Leave #3 open until tag day.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Install tests used a local Composer artifact repository, not Packagist: nothing is on Packagist at this version. Zip built from a temp index (tree 541c1e8c72d2a1afd72956b76d1e070e609cd31b) with version 2.0.0-beta.1 added to composer.json. Composer 2.10.2, PHP 8.5.11.

#1: the command taken from README.md, 'composer require ibnnajjaar/mib-global-pay:^2.0@beta --with-all-dependencies', exits 0 and installs 2.0.0-beta.1 with minimum-stability stable in a fresh Laravel 13.39.0 app (Guzzle 8.3.1 down to 7.16.1; artisan about ok, POST mib/webhook route, mib:reconcile and mib:prune-notifications listed) and in a core-only project (no illuminate or laravel package). Plain ^2.0 exits 2 in both: 'does not match your minimum-stability'. ^2.0@beta without --with-all-dependencies exits 2 on Laravel 13: Guzzle fixed to 8.3.1. On Laravel 12.70.2 it exits 2 with the conflict message and leaves composer.json unchanged; the README quotes that message.

#2: README.md and DESIGN-v2.md state the beta and name the unverified behaviour near the top. Added session lifetime (question 4), which the DESIGN list had left out. Production without a host throws ConfigurationException, as stated.

#3 open: heading '## [2.0.0-beta.1] - Unreleased' and link compare/v1.1.0...v2.0.0-beta.1 are in place, but the link returns 404 because the tag does not exist, and the date is not known. Tag day: set the date in the heading, confirm the link opens, remove the 'not released' wording from CHANGELOG.md, README.md, UPGRADE.md and DESIGN-v2.md and the tag-day paragraph itself, and check that main holds the updated DESIGN-v2.md, since the shipped links point at main. Then check #3 and finish.

#4: 'The 1.x API is unchanged' removed; rg finds no match in the docs.

#5: each 1.2 item checked against src. Kept items restated on v2 classes and exercised by a script against the core-only archive install with warnings turned into exceptions: 41 checks pass. rg finds none of the removed 1.x names in the section.

Links: scripted check over the archive's Markdown: 0 failures, 1 pending (the beta compare link). The other compare links and the absolute links return 200. The same check on the HEAD docs reports 5 failures, so it does detect bad links.

Checks: pest 580 passed, 32 skipped; pint, phpstan, rector and composer validate --strict clean.

Correction to the #5 note: the script runs 40 checks, not 41, and all 40 pass.
<!-- SECTION:NOTES:END -->

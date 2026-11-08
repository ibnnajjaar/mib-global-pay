---
id: TASK-6
title: Exclude development files from the package archive
status: In Progress
assignee:
  - '@claude'
created_date: '2026-11-01 11:16'
updated_date: '2026-11-02 02:04'
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
- [x] #1 A clean install from the archive boots the service provider, publishes the config and migration, and renders the checkout component
- [ ] #2 CI still passes from a source checkout
- [x] #3 Links in the README to excluded files are absolute URLs, or are removed
- [x] #4 `git archive HEAD` contains `src`, `config`, `database`, `resources`, `composer.json`, `README.md`, `UPGRADE.md`, `CHANGELOG.md` and the licence text if one is added, and no tests, tooling or CI files
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add .gitattributes deny list covering tests, CI, tooling configs, backlog, agent files, WORKFLOW.md, DESIGN-v2.md and .gitattributes itself; keep src/ (including the documented src/Laravel/Testing helpers), config/, database/, resources/, composer.json, README, UPGRADE, CHANGELOG.
2. Make README, UPGRADE and CHANGELOG links to excluded files absolute; add a CHANGELOG line; script a check that no relative link in shipped Markdown misses the archive.
3. Build a tree through a temporary index (no commit), list its archive.
4. Build a zip from that archive, install it into a fresh Laravel 13 app, then check provider boot, routes, mib:* commands, vendor:publish, migration and a real component render.
5. Extract the tree without export-ignore into a clean directory, composer install and run each CI step by hand. #2 stays open unless real CI ran.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified on a temporary tree, not literally HEAD, because nothing is committed: GIT_INDEX_FILE temp index, read-tree HEAD, add -A, write-tree (tree a5c877404b59f9c0969cc3705c27a9d724cf6196, includes the untracked test files and .gitattributes, no ignored files).

#4: git archive of that tree has CHANGELOG.md README.md UPGRADE.md composer.json config database resources src, 134 files, no tests, tooling or CI files. No licence file exists.

#3: scripted check over the shipped Markdown: 3 relative links, 0 missing; the same check on the HEAD versions of the docs reports 5 missing. The three absolute targets return 200 on main today, but they serve what main holds now.

#1: zip of that archive installed through a Composer package repository with a file:// dist into a fresh Laravel 13.39.0 app (--with-all-dependencies, Guzzle 8.3.1 down to 7.16.1). vendor/ibnnajjaar/mib-global-pay is identical to the archive (diff -r) and has no excluded files. Provider loaded from vendor, artisan about ok, POST mib/webhook route, mib:prune-notifications and mib:reconcile listed, vendor:publish copied config and migration, migration ran, component rendered through Blade::render and through a real HTTP route (200) with dummy merchant settings.

#2 open: real CI has not run on these changes (branch not pushed). Local equivalent from a clean extract of the tree, with composer update and every workflow step, MySQL 8.4 and PostgreSQL 17 throwaway containers on the CI ports: PHP 8.5.11 and PHP 8.4.27 each passed validate, pint, rector, phpstan and pest (612 passed, 0 skipped). Not covered: GitHub Actions itself, the ubuntu runner, the coverage badge step.

Repo checks without database servers: pest 580 passed, 32 skipped; pint, phpstan, rector and composer validate --strict clean.
<!-- SECTION:NOTES:END -->

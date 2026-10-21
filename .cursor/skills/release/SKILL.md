---
name: release
description: >-
  Cut a predictable SemVer release of the vastsverige-events plugin: classify changes
  against the public contract, choose the version, update the plugin header and
  CHANGELOG.md, and prepare the unprefixed git tag. Use when asked to release, bump the
  version, tag, or publish vastsverige-events.
---

# Release vastsverige-events

The version is decided by rules below, not by feel. Two people (or agents) given the same commits must pick the same number.

Run all Git commands inside the plugin directory. Do not tag from the monorepo.

## Single source of truth

| Where | Rule |
|---|---|
| `Version:` in `vastsverige-events.php` header | The version. Only the release step edits it. |
| `composer.json` | No `version` field. Composer reads the git tag. Do not add one. |
| `CHANGELOG.md` | Records every release; `[Unreleased]` collects changes between releases. |
| Git tag | Unprefixed and identical to the header: `0.2.0`, never `v0.2.0`. |

If the header and the latest tag disagree before you start, stop and ask.

## Classify the change

Look at the diff since the last tag, not only the commit subjects. Subjects (`feat:`, `fix:`) are a hint. The diff wins.

The **public contract** is defined in `AGENTS.md`: CLI command and flags, `vastsverige_events/*` hooks, `vastsverige_events_*` options, `_vastsverige_*` post meta, event identity (post id stable across runs), the shape of written `event` posts, minimum PHP and Event Manager major.

| Change | Level |
|---|---|
| Removes or renames anything in the public contract | **breaking** |
| Changes the meaning or default of a contract item (for example a different trash default, a different identity rule) | **breaking** |
| Changes which post an existing event maps to, so existing post ids would change | **breaking** |
| Raises minimum PHP or requires a different Event Manager major | **breaking** |
| Removes or changes the type of a field the transformer reads | **breaking** |
| Adds a CLI flag, filter, option, setting, or mapped field; bumps `EventMerger::MAPPING_VERSION` to add data | **feature** |
| Fixes wrong behaviour without changing the contract | **fix** |
| Performance, refactor, tests, docs, tooling with no behaviour change | **none** (goes in `[Unreleased]`; release only if the user asks, as a patch) |

When one release contains several levels, the highest wins.

## Bump table

Pre-1.0 (`0.y.z`, the current state):

| Level | Bump | Example |
|---|---|---|
| breaking | minor | `0.1.3` → `0.2.0` |
| feature | patch | `0.1.3` → `0.1.4` |
| fix | patch | `0.1.3` → `0.1.4` |

From `1.0.0` on, standard SemVer: breaking → major, feature → minor, fix → patch. Reset lower numbers to zero.

**Going to `1.0.0` is never decided by an agent.** Move to `1.0.0` only when the user says the plugin is running in production and the contract is stable.

Never use pre-release suffixes (`-rc.1`, `-beta`) unless asked. Never skip or reuse a version number.

## Procedure

1. **Preflight.** All must hold, otherwise stop and report:
   ```bash
   cd wp-content/plugins/vastsverige-events
   git branch --show-current        # main
   git status --short               # empty (no uncommitted changes)
   git fetch origin --tags
   git status -sb                   # not behind origin/main
   vendor/bin/phpunit --testdox     # green, zero warnings
   ```
2. **Find the base.** `git describe --tags --abbrev=0`. If there is no tag yet, the base is the root commit and the current header version is the first release candidate: ask the user whether to tag it as-is.
3. **Collect changes.** `git log <base>..HEAD --oneline` and `git diff <base>..HEAD --stat`. Read the diff of anything touching the public contract.
4. **Classify and pick the version** with the tables above. State the level, the reason (cite the file or commit that forced it), and the resulting number before editing anything.
5. **Update the files.**
   - Set `Version:` in `vastsverige-events.php`.
   - In `CHANGELOG.md`, move `[Unreleased]` entries under `## [X.Y.Z] - YYYY-MM-DD` (today's date) and leave an empty `[Unreleased]` above. Use the groups `Added`, `Changed`, `Fixed`, `Removed`. Mark breaking items with `**Breaking:**` and say what an operator must do.
   - Update the compare links at the bottom if the file has them.
6. **Verify consistency.**
   ```bash
   rg -n '^ \* Version:' vastsverige-events.php
   rg -n '^## \[' CHANGELOG.md | head -3
   ```
   Header, top changelog entry, and the planned tag must be the same number.
7. **Commit.** One commit, subject `chore(release): X.Y.Z`, touching only the header and `CHANGELOG.md`.
8. **Tag.** `git tag -a X.Y.Z -m "X.Y.Z"` on that commit.
9. **Stop and ask before publishing.** Pushing `main` and the tag is outward-facing. Only run `git push origin main && git push origin X.Y.Z` after the user says so. Then offer `gh release create X.Y.Z --notes-file <changelog section>` if requested.

Steps 7 and 8 are local and reversible. Do not run them unless the user asked for a release.

## After a release

Tell the user the monorepo pin is separate. `composer.local.json` in the monorepo (not this repo) would need the new version for deploys. Do not edit it from here.

## Do not

- Edit the version in a feature or fix change.
- Tag from a branch other than `main`, or with uncommitted changes.
- Delete or move a pushed tag. Cut a new patch instead.
- Release with a failing or warning-producing test suite.
- Bump a major for a mapping bump alone. Bumping `MAPPING_VERSION` only forces one rewrite and is not breaking unless fields the transformer reads change shape.

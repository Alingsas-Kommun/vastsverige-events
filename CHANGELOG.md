# Changelog

All notable changes to this plugin are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/), with the rules in `.cursor/skills/release/SKILL.md`.

## [Unreleased]

## [0.1.1] - 2026-10-27

### Fixed

- Encode `locationAddress` as JSON before passing it to `update_field`, so third-party `acf/update_value/type=google_map` filters that type-hint `string` input (such as `modularity-frontend-form` `0.83.14`) do not throw a `TypeError` before ACF decodes the map array.

## [0.1.0] - 2026-10-21

### Added

- Initial import of Västsverige events into Event Manager `event` posts, with `wp vastsverige import`, hourly cron, a settings page, and trash safety limits.

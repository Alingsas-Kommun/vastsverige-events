# AGENTS.md

Guidance for AI agents working in `vastsverige-events`. Read `README.md` for behaviour and `.cursor/skills/` for the two workflows below.

## What this plugin is

Imports Västsverige events into Event Manager 3.x `event` posts on the events subsite. It does not render anything. A separate process (`schema.org-transformer`) reads those posts over REST into Typesense, so **the post id is the event's identity downstream**. Anything that changes which post an event lands on creates a duplicate event on the public site.

- PHP `>=8.1`, `declare(strict_types=1)`, PSR-4 `VastsverigeEvents\` → `src/`, tests `VastsverigeEvents\Tests\` → `tests/`.
- No runtime Composer dependencies. `vastsverige-events.php` registers its own autoloader.
- Active only on the events subsite, next to `api-event-manager` 3.x. Never activate Event Manager 1.5.5 beside it.

## Skills (follow them, do not improvise)

| Task | Skill |
|---|---|
| Add a feature or fix a bug | `.cursor/skills/create-test/SKILL.md` — tests are part of the change, not a follow-up |
| Cut a version | `.cursor/skills/release/SKILL.md` — the only place version numbers are decided |

## Commands

Run from the plugin directory.

```bash
composer install
vendor/bin/phpunit --testdox     # whole suite, no WordPress needed
vendor/bin/phpunit --filter TrashGuardTest
```

`phpunit.xml` has `failOnWarning` and `failOnRisky`. A passing run means zero warnings.

## Deploy

A source install runs as-is. `vastsverige-events.php` autoloads `src/` and there are no runtime Composer or npm dependencies, so do not add a `composer dump-autoload` or an asset build.

`build.php` exists only so the site build can pass `--cleanup` and strip dev files (`tests/`, `vendor/`, `phpunit.xml`, `.cursor/`, `AGENTS.md`, Composer files). It ignores `--no-composer` and `--install-npm`. Do not run it locally without `--cleanup`, and never commit the result of `--cleanup`.

## Architecture rules

- **Logic lives in pure classes** (`Api/Payload`, `Import/*`, `Mapping/PostSelector`, …) that take plain arrays and return values. These are unit tested.
- **WordPress calls live in thin adapters** (`Mapping/EventWriter`, `Mapping/MediaSideloader` WP calls, `Settings`, `Cron`, `Cli/ImportCommand`, `Plugin`). They are not unit tested. Do not move logic into them to avoid writing a test; move logic out of them instead.
- The seams are the interfaces `Api\EventSource` and `Mapping\EventRepository`. Tests use the fakes in `tests/Support/`.
- Changing what is written to a post (fields, occasion shape, term mapping) requires bumping `EventMerger::MAPPING_VERSION`, so unchanged events are rewritten once.

## Never do

- Edit `wp-content/plugins/api-event-manager`, `wp-content/themes/municipio`, `vendor/`, or `schema.org-transformer` to make this plugin work. Fix the writer so the REST document matches what they already expect.
- Match events by title similarity. Identity is the stored source ids.
- Trash anything after a partial run (`--limit`, `--heading`, a failed list, detail or save). Keep `Import/TrashGuard` and `Runner` safety rules intact.
- Fire `EventManager/OrganizationCreated` (it creates a WordPress user per organizer).
- Touch the live API from tests. Use `tests/fixtures`.
- Add inline comments that explain your reasoning or the chat. Use docblocks for the contract (see the workspace `comments-docblocks` rule).

## Public contract (what SemVer protects)

These are what other code and operators depend on. The release skill classifies changes against this list.

- WP-CLI: `wp vastsverige import` and its flags (`--limit`, `--heading`, `--allow-mass-trash`).
- Filter `vastsverige_events/max_trash_ratio` and any other `vastsverige_events/*` hook.
- Option names `vastsverige_events_*` and the cron hook `vastsverige_events_import`.
- Post meta `_vastsverige_*` (source ids, hash), because lookup of existing posts depends on them.
- Event identity: the same API event keeps the same post id across runs.
- The shape of the `event` posts written (ACF fields and values the transformer reads).
- Minimum PHP version and supported Event Manager major.

## Git

- The plugin is its own repository (`git@github.com:Alingsas-Kommun/vastsverige-events.git`). Run Git from this directory (`git -C wp-content/plugins/vastsverige-events …`), never from the monorepo root.
- Integration branch: `main`. Work on `feat/<name>` or `fix/<name>` branches.
- Do not commit, push, tag, or open a PR unless the user asks.
- Commit subjects use Conventional Commits so the bump is derivable: `feat:`, `fix:`, `perf:`, `refactor:`, `test:`, `docs:`, `chore:`. Append `!` or a `BREAKING CHANGE:` footer for incompatible changes.
- Versions and tags are set only through the release skill. Do not edit the `Version:` header by hand in a feature or fix change.

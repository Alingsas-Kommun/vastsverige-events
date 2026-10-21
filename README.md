# Västsverige Events

Imports events from the Västsverige API (`https://www.vastsverige.com/api`, config `2671`) into Event Manager 3.x `event` posts. Activate it only on the events subsite, next to `api-event-manager`.

## Running

```bash
ddev wp --url=https://event.alingsas-se.ddev.site vastsverige import
ddev wp --url=... vastsverige import --limit=3          # never trashes
ddev wp --url=... vastsverige import --heading="Quiz"  # never trashes
ddev wp --url=... vastsverige import --allow-mass-trash # override the safety limit
```

Cron (hourly) is switched on under Settings → Västsverige. The settings page also shows the outcome of the last run. A lock option stops two imports from running at once.

## How events are matched

- Details with the same heading (case and spacing ignored) become one event.
- The source ids are stored on the post (`_vastsverige_source_id`). Later runs find the post by any of its ids, so the post id (and the `WPH-` id the transformer derives from it) stays the same.
- A post an editor trashed is left alone. A post the importer trashed is restored if its event returns.
- If two posts end up holding the same source ids, the lowest id wins and the other is trashed.
- Each post stores a fingerprint of what was written (`_vastsverige_hash`). Unchanged events are skipped.

## What is mapped

| API | Event Manager |
|---|---|
| `Heading`, `Description`, `Tagline`, `FacebookLink`, `InstagramLink` | title, content (tagline first, social links last) |
| `Dates[].Date`, `Times`, `EndDate`, `ExcludeWeekDay`, `End` | `occasions` (see below) |
| `Contact.BookingLink` | `url` on every occasion row |
| `Url` | `url` |
| `City` | `locationName`, `locationAddress.address` |
| `Position` | `locationAddress` lat/lng |
| `Contact` | `organization` term (name, email, phone, website, street) |
| `Image` | `images` gallery (alt from `Image.Alt`) |
| `Categories.Event` (group 73) | `category` terms |

Occasions: each `Times` slot is one occasion. Three or more dates on the same weekday and clock time, a whole number of weeks apart, become one `byWeek` row. A single date with a later `End` is one period. The repeater holds ten rows: weekly rows are kept first, then the soonest dates. Dropped dates are logged.

## Safety

Trashing only happens after a full run where the id list, every detail and every save succeeded, the API's `TotalCount` matches, and no more than 30% of imported events (at least 5) would be trashed. An empty or malformed API answer never trashes anything. Filter `vastsverige_events/max_trash_ratio` changes the 30%.

## Tests

```bash
composer install
vendor/bin/phpunit --testdox
```

The suite does not need WordPress. Fixtures under `tests/fixtures` are trimmed live API payloads.

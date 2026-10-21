---
name: create-test
description: >-
  Write PHPUnit tests for vastsverige-events whenever new functionality or a bug fix is
  added. Covers where tests go, the fakes and fixtures to reuse, the regression-first rule
  for bugs, and how to verify. Use when adding a feature, fixing a bug, or changing import,
  mapping, merge, occasion, or trash behaviour.
---

# Create tests for vastsverige-events

Every feature and every bug fix ships with a test in the same change. Do not report a change as done without one, or without saying precisely why a test is impossible.

## Decide what to write

| Change | Required test |
|---|---|
| New behaviour in a pure class | A test per rule, including the boundary and the "nothing happens" case |
| Bug fix | A **regression test that fails before the fix** and passes after |
| New mapping from the API payload | A test using a trimmed real payload from `tests/fixtures` |
| New safety rule (trash, lock, abort) | A test that proves nothing is written or trashed |
| Change inside a WordPress adapter (`EventWriter`, `Settings`, `Cron`, `Cli/ImportCommand`, `Plugin`) | Extract the decision into a pure class and test that. If the change is only WordPress glue, say so and list the manual check instead |
| Refactor with no behaviour change | Existing tests must stay green. Add a test only where coverage was missing |

## Where tests go

Mirror `src/` under `tests/`, suffix `Test.php`:

| Source | Test |
|---|---|
| `src/Import/OccasionBuilder.php` | `tests/Import/OccasionBuilderTest.php` |
| `src/Api/Payload.php` | `tests/Api/PayloadTest.php` |
| `src/Mapping/PostSelector.php` | `tests/Mapping/PostSelectorTest.php` |

Namespace `VastsverigeEvents\Tests\<Folder>`. Add to the existing test class if one exists.

## Conventions

- `declare(strict_types=1);`, `final class XTest extends PHPUnit\Framework\TestCase`.
- Method names are sentences: `testAnEmptyApiListNeverTrashes`, `testRatioBoundaryIsInclusive`. They state the rule, not the method under test.
- Arrange, act, assert, with `self::assert…`. One rule per test. Use a data provider only when the same rule runs over several inputs.
- Use `setUp()` for shared objects (see `ImporterTest`).
- The suite must not need WordPress, a database, the network, or the clock. Pass dates in. `bootstrap.php` fixes the timezone to `Europe/Stockholm`.
- No inline comments explaining your reasoning. A docblock is fine on helpers.

## Reuse the seams

- `Tests\Support\FakeSource` implements `EventSource`: set `details`, `listFails`, `failingDetails`, `categoriesFail`, `total`. `FakeSource::event($id, $heading, $date)` builds a minimal valid event.
- `Tests\Support\FakeRepository` implements `EventRepository`: set `managed`, `failing`, `unchanged`, `available`; inspect `saved` and `trashed`.
- `Tests\Support\Fixtures::detail($id)` and `categoriesBody()` return real payloads from `tests/fixtures/*.json`.
- New fields on the fakes are fine. A new second fake for the same interface is not.

To add a fixture, copy a live detail, trim it to the keys the code reads, keep real values, and add it to `tests/fixtures/details.json` under its API id. Do not call the live API from a test.

## Bug fix workflow

1. Reproduce the bug as a failing test first. Use the real payload that triggered it when there is one.
2. Run it and confirm it fails **for the reason in the bug report**, not from a typo:
   ```bash
   vendor/bin/phpunit --filter testYourNewTest
   ```
3. Fix the code in `src/`.
4. Run it again: green. Then run the whole suite.
5. In the summary, state the test name and that it failed before the fix.

If you cannot make it fail, you have not understood the bug yet. Do not write a test that passes on the broken code.

## Feature workflow

1. List the rules the feature adds, one line each, including failure and boundary cases.
2. Write one test per rule. Cover at least: the normal case, the empty or missing input, the boundary, and any "must not write or trash" case.
3. Implement until green.
4. If the written post changes shape, bump `EventMerger::MAPPING_VERSION` and add a test that two otherwise equal events with different mapping versions do not share a hash, only if one does not already exist.

## Verify

Run from the plugin directory:

```bash
vendor/bin/phpunit --testdox
```

Must end `OK` with no warnings, no risky tests, no skipped tests added by you (`failOnWarning` and `failOnRisky` are on). If `vendor/` is missing, run `composer install` first.

Report the result as the real summary line, for example `OK (113 tests, 231 assertions)`, plus the names of the tests you added.

## Do not

- Weaken or delete an existing assertion to get green. If behaviour changed on purpose, update the test and say why.
- Mock WordPress functions globally to unit test an adapter.
- Assert on implementation details such as private call order. Assert on what was saved, trashed, or returned.
- Leave a `markTestIncomplete` or `markTestSkipped` behind.

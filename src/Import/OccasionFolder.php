<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Folds event moments into Event Manager occasion rows.
 *
 * Runs of at least three dates on the same weekday and clock time, a constant whole number of
 * weeks apart, become one `byWeek` row. Gaps are counted in calendar days, so a clock change
 * never breaks a series. The repeater holds at most ten rows.
 */
class OccasionFolder
{
    public const MAX_ROWS = 10;

    private const MIN_SERIES = 3;

    /** @var array<int, string> */
    private const WEEKDAYS = [
        1 => 'https://schema.org/Monday',
        2 => 'https://schema.org/Tuesday',
        3 => 'https://schema.org/Wednesday',
        4 => 'https://schema.org/Thursday',
        5 => 'https://schema.org/Friday',
        6 => 'https://schema.org/Saturday',
        7 => 'https://schema.org/Sunday',
    ];

    /**
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable}> $dates
     * @param DateTimeImmutable|null                                        $today Reference for ranking dates when rows are dropped.
     * @return array{rows: list<array<string, mixed>>, dropped: list<string>}
     */
    public function fold(array $dates, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today', new DateTimeZone('Europe/Stockholm'));

        $series = [];
        $single = [];

        foreach ($this->groupByClock($dates) as $group) {
            $this->split($group, $series, $single);
        }

        usort($series, static fn (array $a, array $b): int => strcmp($a['row']['date'], $b['row']['date']));
        $single = $this->rank($single, $today->format('Y-m-d'));

        $kept    = array_slice($series, 0, self::MAX_ROWS);
        $room    = self::MAX_ROWS - count($kept);
        $kept    = array_merge($kept, array_slice($single, 0, max(0, $room)));
        $dropped = array_merge(array_slice($series, self::MAX_ROWS), array_slice($single, max(0, $room)));

        $rows = array_map(static fn (array $entry): array => $entry['row'], $kept);
        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['startTime']] <=> [$b['date'], $b['startTime']]);

        return [
            'rows'    => $rows,
            'dropped' => array_map(static fn (array $entry): string => $entry['label'], $dropped),
        ];
    }

    /**
     * Buckets one-day moments by weekday and clock. Multi-day periods bypass weekly folding.
     *
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable}> $dates
     * @return list<list<array{day: string, start: DateTimeImmutable, end: DateTimeImmutable}>>
     */
    private function groupByClock(array $dates): array
    {
        $groups = [];

        foreach ($dates as $date) {
            $day = $date['start']->format('Y-m-d');
            $key = $date['start']->format('H:i:s') . '|' . $date['end']->format('H:i:s') . '|' . $date['start']->format('N');

            if ($date['end']->format('Y-m-d') > $day) {
                $key = 'period|' . $day . '|' . $date['end']->format('c');
            }

            $groups[$key][$day] = ['day' => $day, 'start' => $date['start'], 'end' => $date['end']];
        }

        return array_map(static function (array $group): array {
            ksort($group);
            return array_values($group);
        }, array_values($groups));
    }

    /**
     * Cuts a sorted group into weekly runs and loose dates.
     *
     * @param list<array{day: string, start: DateTimeImmutable, end: DateTimeImmutable}> $group
     * @param list<array{row: array<string, mixed>, label: string}>                     $series
     * @param list<array{row: array<string, mixed>, label: string}>                     $single
     */
    private function split(array $group, array &$series, array &$single): void
    {
        $count = count($group);
        $index = 0;

        while ($index < $count) {
            $run = $this->weeklyRunEnd($group, $index);

            if ($run > $index) {
                $series[] = $this->weeklyEntry($group[$index], $group[$run], $this->gapDays($group[$index]['day'], $group[$index + 1]['day']));
                $index    = $run + 1;
                continue;
            }

            $single[] = $this->singleEntry($group[$index]);
            $index++;
        }
    }

    /**
     * Last index of the weekly run starting at $from, or $from when no run of three exists.
     *
     * @param list<array{day: string, start: DateTimeImmutable, end: DateTimeImmutable}> $group
     */
    private function weeklyRunEnd(array $group, int $from): int
    {
        $count = count($group);
        if ($from + self::MIN_SERIES > $count) {
            return $from;
        }

        $gap = $this->gapDays($group[$from]['day'], $group[$from + 1]['day']);
        if ($gap < 7 || $gap % 7 !== 0) {
            return $from;
        }

        $last = $from + 1;
        while ($last + 1 < $count && $this->gapDays($group[$last]['day'], $group[$last + 1]['day']) === $gap) {
            $last++;
        }

        return $last - $from + 1 >= self::MIN_SERIES ? $last : $from;
    }

    private function gapDays(string $from, string $to): int
    {
        $utc = new DateTimeZone('UTC');
        $a   = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
        $b   = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);

        return (int) (($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * @param array{day: string, start: DateTimeImmutable, end: DateTimeImmutable} $first
     * @param array{day: string, start: DateTimeImmutable, end: DateTimeImmutable} $last
     * @return array{row: array<string, mixed>, label: string}
     */
    private function weeklyEntry(array $first, array $last, int $gap): array
    {
        return [
            'row'   => [
                'repeat'        => 'byWeek',
                'date'          => $first['day'],
                'untilDate'     => $last['day'],
                'weeksInterval' => intdiv($gap, 7),
                'weekDays'      => [self::WEEKDAYS[(int) $first['start']->format('N')]],
                'startTime'     => $first['start']->format('H:i:s'),
                'endTime'       => $first['end']->format('H:i:s'),
            ],
            'label' => sprintf('weekly %s..%s %s', $first['day'], $last['day'], $first['start']->format('H:i')),
        ];
    }

    /**
     * @param array{day: string, start: DateTimeImmutable, end: DateTimeImmutable} $date
     * @return array{row: array<string, mixed>, label: string}
     */
    private function singleEntry(array $date): array
    {
        return [
            'row'   => [
                'repeat'    => 'no',
                'date'      => $date['day'],
                'untilDate' => $date['end']->format('Y-m-d'),
                'startTime' => $date['start']->format('H:i:s'),
                'endTime'   => $date['end']->format('H:i:s'),
            ],
            'label' => $date['start']->format('Y-m-d H:i'),
        ];
    }

    /**
     * Upcoming dates first in date order, then the most recent past dates.
     *
     * @param list<array{row: array<string, mixed>, label: string}> $entries
     * @return list<array{row: array<string, mixed>, label: string}>
     */
    private function rank(array $entries, string $today): array
    {
        $future = [];
        $past   = [];
        foreach ($entries as $entry) {
            if ($entry['row']['untilDate'] >= $today) {
                $future[] = $entry;
            } else {
                $past[] = $entry;
            }
        }

        usort($future, static fn (array $a, array $b): int => strcmp($a['row']['date'], $b['row']['date']));
        usort($past, static fn (array $a, array $b): int => strcmp($b['row']['date'], $a['row']['date']));

        return array_merge($future, $past);
    }
}

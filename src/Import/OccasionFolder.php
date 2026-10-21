<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Folds API dates into Event Manager occasion rows.
 *
 * Weekly series become one byWeek row. The repeater holds at most ten rows.
 */
class OccasionFolder
{
    public const MAX_ROWS = 10;

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
     * @return array{rows: list<array<string, mixed>>, dropped: int}
     */
    public function fold(array $dates): array
    {
        $groups = [];
        foreach ($dates as $date) {
            $start = $date['start'];
            $end   = $date['end'];
            $key   = $start->format('H:i:s') . '|' . $end->format('H:i:s') . '|' . $start->format('N');
            $groups[$key][] = $date;
        }

        $weekly = [];
        $single = [];
        foreach ($groups as $group) {
            $series = $this->weeklyRow($group);
            if ($series !== null) {
                $weekly[] = $series;
                continue;
            }
            foreach ($group as $date) {
                $single[] = $this->singleRow($date['start'], $date['end']);
            }
        }

        usort($weekly, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));
        $single = $this->soonest($single);

        $rows = array_slice($weekly, 0, self::MAX_ROWS);
        $room = self::MAX_ROWS - count($rows);
        if ($room > 0) {
            $rows = array_merge($rows, array_slice($single, 0, $room));
        }

        $dropped = (count($weekly) + count($single)) - count($rows);

        return ['rows' => $rows, 'dropped' => max(0, $dropped)];
    }

    /**
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable}> $group
     * @return array<string, mixed>|null
     */
    private function weeklyRow(array $group): ?array
    {
        if (count($group) < 3) {
            return null;
        }

        usort($group, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $days = [];
        foreach ($group as $date) {
            $day = $date['start']->format('Y-m-d');
            $days[$day] = $date;
        }
        $unique = array_values($days);
        if (count($unique) < 3) {
            return null;
        }

        $gap = null;
        for ($i = 1, $count = count($unique); $i < $count; $i++) {
            $diff = (int) $unique[$i - 1]['start']->diff($unique[$i]['start'])->days;
            if ($gap === null) {
                $gap = $diff;
                continue;
            }
            if ($gap !== $diff) {
                return null;
            }
        }

        if ($gap === null || $gap < 7 || $gap % 7 !== 0) {
            return null;
        }

        $first = $unique[0];
        $last  = $unique[count($unique) - 1];
        $weekday = (int) $first['start']->format('N');

        return [
            'repeat'         => 'byWeek',
            'date'           => $first['start']->format('Y-m-d'),
            'untilDate'      => $last['start']->format('Y-m-d'),
            'weeksInterval'  => (int) ($gap / 7),
            'weekDays'       => [self::WEEKDAYS[$weekday]],
            'startTime'      => $first['start']->format('H:i:s'),
            'endTime'        => $first['end']->format('H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function singleRow(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            'repeat'    => 'no',
            'date'      => $start->format('Y-m-d'),
            'untilDate' => $start->format('Y-m-d'),
            'startTime' => $start->format('H:i:s'),
            'endTime'   => $end->format('H:i:s'),
        ];
    }

    /**
     * Future dates first, then the most recent past dates.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function soonest(array $rows): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Stockholm'));
        $todayKey = $today->format('Y-m-d');

        $future = [];
        $past   = [];
        foreach ($rows as $row) {
            if ((string) $row['date'] >= $todayKey) {
                $future[] = $row;
            } else {
                $past[] = $row;
            }
        }

        usort($future, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));
        usort($past, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return array_merge($future, $past);
    }
}

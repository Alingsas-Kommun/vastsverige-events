<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns the `Dates` of one API detail into start and end moments.
 *
 * Clock times are kept as the API writes them, so daylight saving never shifts them.
 * A date carries its opening hours in `Times` as `{Start: "H:i", End: "H:i"}`. Each slot
 * becomes one occasion. A date without usable slots keeps the clock time of the date itself.
 */
class OccasionBuilder
{
    private const MAX_RANGE_DAYS = 366;

    private DateTimeZone $zone;

    public function __construct()
    {
        $this->zone = new DateTimeZone('Europe/Stockholm');
    }

    /**
     * @param list<mixed> $dates        The `Dates` array of a detail.
     * @param string|null $eventEnd     The event level `End` of the same detail.
     * @param bool        $allowPeriod  Treat a single date with a later `End` as one continuous period.
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    public function build(array $dates, ?string $eventEnd, bool $allowPeriod = false): array
    {
        $end       = $this->parse($eventEnd);
        $occasions = [];

        $usable = array_values(array_filter($dates, static fn ($date): bool => is_array($date) && ! empty($date['Date'])));

        foreach ($usable as $date) {
            $base = $this->parse((string) $date['Date']);
            if ($base === null) {
                continue;
            }

            $slots = $this->slots($date['Times'] ?? null);

            if ($allowPeriod && count($usable) === 1 && $slots === [] && $end !== null && $this->day($end) > $this->day($base)) {
                $occasions[$base->format('c') . '|' . $end->format('c')] = ['start' => $base, 'end' => $end];
                continue;
            }

            foreach ($this->days($base, $date) as $day) {
                foreach ($slots === [] ? [null] : $slots as $slot) {
                    $start = $slot === null
                        ? $day->setTime((int) $base->format('H'), (int) $base->format('i'), (int) $base->format('s'))
                        : $day->setTime($slot['startHour'], $slot['startMinute'], 0);

                    $finish = $this->finish($day, $start, $slot, $date, $end);

                    $occasions[$start->format('c') . '|' . $finish->format('c')] = ['start' => $start, 'end' => $finish];
                }
            }
        }

        return array_values($occasions);
    }

    /**
     * @param array<string, mixed> $date
     * @return list<DateTimeImmutable> Midnight of every day the date entry covers.
     */
    private function days(DateTimeImmutable $base, array $date): array
    {
        $first = $base->setTime(0, 0, 0);
        $last  = $this->parse(isset($date['EndDate']) ? (string) $date['EndDate'] : null);
        if ($last === null || $this->day($last) <= $this->day($base)) {
            return [$first];
        }

        $excluded = array_map('intval', is_array($date['ExcludeWeekDay'] ?? null) ? $date['ExcludeWeekDay'] : []);
        $lastDay  = $last->setTime(0, 0, 0);
        $days     = [];

        for ($cursor = $first, $count = 0; $cursor <= $lastDay && $count < self::MAX_RANGE_DAYS; $cursor = $cursor->modify('+1 day'), $count++) {
            if (in_array((int) $cursor->format('w'), $excluded, true)) {
                continue;
            }
            $days[] = $cursor;
        }

        return $days === [] ? [$first] : $days;
    }

    /**
     * @param array{startHour: int, startMinute: int, endHour: int|null, endMinute: int|null}|null $slot
     * @param array<string, mixed>                                                                  $date
     */
    private function finish(DateTimeImmutable $day, DateTimeImmutable $start, ?array $slot, array $date, ?DateTimeImmutable $eventEnd): DateTimeImmutable
    {
        if ($slot !== null && $slot['endHour'] !== null) {
            $finish = $day->setTime($slot['endHour'], (int) $slot['endMinute'], 0);

            return $finish >= $start ? $finish : $start;
        }

        foreach (['End', 'end'] as $key) {
            $explicit = $this->parse(isset($date[$key]) ? (string) $date[$key] : null);
            if ($explicit !== null && $this->day($explicit) === $this->day($start) && $explicit >= $start) {
                return $explicit;
            }
        }

        if ($eventEnd !== null && $this->day($eventEnd) === $this->day($start) && $eventEnd >= $start) {
            return $eventEnd;
        }

        return $start;
    }

    /**
     * @return list<array{startHour: int, startMinute: int, endHour: int|null, endMinute: int|null}>
     */
    private function slots(mixed $times): array
    {
        if (! is_array($times)) {
            return [];
        }

        $slots = [];
        foreach ($times as $time) {
            if (! is_array($time)) {
                continue;
            }
            $start = $this->clock($time['Start'] ?? null);
            if ($start === null) {
                continue;
            }
            $end     = $this->clock($time['End'] ?? null);
            $slots[] = [
                'startHour'   => $start[0],
                'startMinute' => $start[1],
                'endHour'     => $end[0] ?? null,
                'endMinute'   => $end[1] ?? null,
            ];
        }

        return $slots;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function clock(mixed $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $matches)) {
            return null;
        }

        $hour   = (int) $matches[1];
        $minute = (int) $matches[2];

        return $hour > 23 || $minute > 59 ? null : [$hour, $minute];
    }

    private function day(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d');
    }

    /**
     * Parses an ISO moment and keeps its written wall clock in the site zone.
     */
    private function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return new DateTimeImmutable($parsed->format('Y-m-d H:i:s'), $this->zone);
    }
}

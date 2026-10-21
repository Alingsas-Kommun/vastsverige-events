<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Import;

use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Import\OccasionBuilder;
use VastsverigeEvents\Tests\Support\Fixtures;

final class OccasionBuilderTest extends TestCase
{
    private OccasionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new OccasionBuilder();
    }

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $occasions
     * @return list<string>
     */
    private function show(array $occasions): array
    {
        return array_map(
            static fn (array $o): string => $o['start']->format('Y-m-d H:i') . ' -> ' . $o['end']->format('Y-m-d H:i'),
            $occasions
        );
    }

    public function testTimesSlotSetsStartAndEnd(): void
    {
        $result = $this->builder->build(
            [['Date' => '2026-09-25T00:00:00+02:00', 'Times' => [['Start' => '09:00', 'End' => '19:00']]]],
            null
        );

        self::assertSame(['2026-09-25 09:00 -> 2026-09-25 19:00'], $this->show($result));
    }

    public function testEachSlotBecomesItsOwnOccasion(): void
    {
        $result = $this->builder->build(
            [['Date' => '2026-11-21T00:00:00+01:00', 'Times' => [['Start' => '10:00', 'End' => '12:00'], ['Start' => '14:00', 'End' => '16:00']]]],
            null
        );

        self::assertSame(['2026-11-21 10:00 -> 2026-11-21 12:00', '2026-11-21 14:00 -> 2026-11-21 16:00'], $this->show($result));
    }

    public function testSlotWithoutEndEndsWhereItStarts(): void
    {
        $result = $this->builder->build([['Date' => '2026-11-21T00:00:00+01:00', 'Times' => [['Start' => '10:00']]]], null);

        self::assertSame(['2026-11-21 10:00 -> 2026-11-21 10:00'], $this->show($result));
    }

    public function testEmptySlotObjectsAreIgnoredAndFallBackToDateClock(): void
    {
        $result = $this->builder->build([['Date' => '2026-11-21T19:30:00+01:00', 'Times' => [[]]]], null);

        self::assertSame(['2026-11-21 19:30 -> 2026-11-21 19:30'], $this->show($result));
    }

    public function testEmptyTimesKeepsClockOfTheDate(): void
    {
        $result = $this->builder->build([['Date' => '2026-10-02T19:00:00+02:00', 'Times' => []]], null);

        self::assertSame(['2026-10-02 19:00 -> 2026-10-02 19:00'], $this->show($result));
    }

    public function testSlotEndBeforeStartIsClampedToStart(): void
    {
        $result = $this->builder->build([['Date' => '2026-12-31T00:00:00+01:00', 'Times' => [['Start' => '22:00', 'End' => '02:00']]]], null);

        self::assertSame(['2026-12-31 22:00 -> 2026-12-31 22:00'], $this->show($result));
    }

    public function testInvalidClockValuesAreIgnored(): void
    {
        $result = $this->builder->build([['Date' => '2026-12-31T00:00:00+01:00', 'Times' => [['Start' => '25:99', 'End' => 'late']]]], null);

        self::assertSame(['2026-12-31 00:00 -> 2026-12-31 00:00'], $this->show($result));
    }

    public function testEventEndIsUsedWhenItFallsOnTheSameDayAfterStart(): void
    {
        $result = $this->builder->build(
            [['Date' => '2026-10-02T18:00:00+02:00']],
            '2026-10-02T21:00:00+02:00'
        );

        self::assertSame(['2026-10-02 18:00 -> 2026-10-02 21:00'], $this->show($result));
    }

    public function testEventEndOnAnotherDayIsNotCopiedOntoEveryDate(): void
    {
        $result = $this->builder->build(
            [['Date' => '2026-10-02T18:00:00+02:00'], ['Date' => '2026-10-09T18:00:00+02:00']],
            '2026-12-17T22:00:00+01:00'
        );

        self::assertSame(['2026-10-02 18:00 -> 2026-10-02 18:00', '2026-10-09 18:00 -> 2026-10-09 18:00'], $this->show($result));
    }

    public function testSingleDateWithLaterEndIsOnePeriodWhenAllowed(): void
    {
        $dates = [['Date' => '2026-10-31T08:00:00+01:00']];

        $period = $this->builder->build($dates, '2026-11-01T18:00:00+01:00', true);
        $plain  = $this->builder->build($dates, '2026-11-01T18:00:00+01:00', false);

        self::assertSame(['2026-10-31 08:00 -> 2026-11-01 18:00'], $this->show($period));
        self::assertSame(['2026-10-31 08:00 -> 2026-10-31 08:00'], $this->show($plain));
    }

    public function testPeriodIsNotUsedWhenDateHasTimes(): void
    {
        $result = $this->builder->build(
            [['Date' => '2026-10-31T00:00:00+01:00', 'Times' => [['Start' => '08:00', 'End' => '10:00']]]],
            '2026-11-30T18:00:00+01:00',
            true
        );

        self::assertSame(['2026-10-31 08:00 -> 2026-10-31 10:00'], $this->show($result));
    }

    public function testEndDateExpandsToEveryDayExceptExcludedWeekdays(): void
    {
        $result = $this->builder->build(
            [['Date' => '2026-11-26T00:00:00+01:00', 'EndDate' => '2026-11-30T00:00:00+01:00', 'ExcludeWeekDay' => [5], 'Times' => [['Start' => '16:00', 'End' => '18:00']]]],
            null
        );

        self::assertSame(
            [
                '2026-11-26 16:00 -> 2026-11-26 18:00',
                '2026-11-28 16:00 -> 2026-11-28 18:00',
                '2026-11-29 16:00 -> 2026-11-29 18:00',
                '2026-11-30 16:00 -> 2026-11-30 18:00',
            ],
            $this->show($result)
        );
    }

    public function testDuplicateMomentsAreMerged(): void
    {
        $result = $this->builder->build(
            [
                ['Date' => '2026-11-28T00:00:00+01:00', 'EndDate' => '2026-11-29T00:00:00+01:00', 'Times' => [['Start' => '16:00', 'End' => '18:00']]],
                ['Date' => '2026-11-29T00:00:00+01:00', 'EndDate' => '2026-11-29T00:00:00+01:00', 'Times' => [['Start' => '16:00', 'End' => '18:00']]],
            ],
            null
        );

        self::assertCount(2, $result);
    }

    public function testUnparseableDatesAreSkipped(): void
    {
        $result = $this->builder->build([['Date' => 'someday'], ['Date' => ''], 'junk', ['Date' => '2026-11-05T00:00:00+01:00']], null);

        self::assertCount(1, $result);
    }

    public function testWallClockIsKeptAcrossDaylightSavingChange(): void
    {
        $result = $this->builder->build(
            [
                ['Date' => '2026-03-26T00:00:00+01:00', 'Times' => [['Start' => '19:00', 'End' => '22:00']]],
                ['Date' => '2026-04-02T00:00:00+02:00', 'Times' => [['Start' => '19:00', 'End' => '22:00']]],
            ],
            null
        );

        self::assertSame(['2026-03-26 19:00 -> 2026-03-26 22:00', '2026-04-02 19:00 -> 2026-04-02 22:00'], $this->show($result));
    }

    public function testRealQuizPayloadYieldsFortyThreeEveningOccasions(): void
    {
        $detail = Fixtures::detail(576633);

        $result = $this->builder->build($detail['Dates'], $detail['End'], true);

        self::assertCount(43, $result);
        self::assertSame('19:00', $result[0]['start']->format('H:i'));
        self::assertSame('22:00', $result[0]['end']->format('H:i'));
    }
}

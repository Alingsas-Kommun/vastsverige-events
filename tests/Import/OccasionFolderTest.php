<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Import;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Import\OccasionBuilder;
use VastsverigeEvents\Import\OccasionFolder;
use VastsverigeEvents\Tests\Support\Fixtures;

final class OccasionFolderTest extends TestCase
{
    private OccasionFolder $folder;

    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->folder = new OccasionFolder();
        $this->today  = new DateTimeImmutable('2026-10-21', new DateTimeZone('Europe/Stockholm'));
    }

    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    private function moment(string $start, ?string $end = null): array
    {
        $zone = new DateTimeZone('Europe/Stockholm');
        $from = new DateTimeImmutable($start, $zone);

        return ['start' => $from, 'end' => $end === null ? $from : new DateTimeImmutable($end, $zone)];
    }

    /**
     * @param list<string> $starts
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    private function moments(array $starts): array
    {
        return array_map(fn (string $start): array => $this->moment($start), $starts);
    }

    public function testThreeWeeklyDatesFoldIntoOneWeeklyRow(): void
    {
        $result = $this->folder->fold($this->moments(['2026-11-05 19:00', '2026-11-12 19:00', '2026-11-19 19:00']), $this->today);

        self::assertCount(1, $result['rows']);
        self::assertSame(
            [
                'repeat'        => 'byWeek',
                'date'          => '2026-11-05',
                'untilDate'     => '2026-11-19',
                'weeksInterval' => 1,
                'weekDays'      => ['https://schema.org/Thursday'],
                'startTime'     => '19:00:00',
                'endTime'       => '19:00:00',
            ],
            $result['rows'][0]
        );
        self::assertSame([], $result['dropped']);
    }

    public function testTwoDatesStaySingleRows(): void
    {
        $result = $this->folder->fold($this->moments(['2026-11-05 19:00', '2026-11-12 19:00']), $this->today);

        self::assertSame(['no', 'no'], array_column($result['rows'], 'repeat'));
    }

    public function testSeriesAcrossTheSpringClockChangeStaysOneRow(): void
    {
        $result = $this->folder->fold($this->moments(['2026-03-19 19:00', '2026-03-26 19:00', '2026-04-02 19:00', '2026-04-09 19:00']), $this->today);

        self::assertCount(1, $result['rows']);
        self::assertSame('byWeek', $result['rows'][0]['repeat']);
        self::assertSame('2026-04-09', $result['rows'][0]['untilDate']);
    }

    public function testSeriesAcrossTheAutumnClockChangeStaysOneRow(): void
    {
        $result = $this->folder->fold($this->moments(['2026-10-15 19:00', '2026-10-22 19:00', '2026-10-29 19:00', '2026-11-05 19:00']), $this->today);

        self::assertCount(1, $result['rows']);
        self::assertSame('byWeek', $result['rows'][0]['repeat']);
    }

    public function testRealQuizPayloadFoldsIntoOneWeeklyRow(): void
    {
        $detail    = Fixtures::detail(576633);
        $occasions = (new OccasionBuilder())->build($detail['Dates'], $detail['End'], true);

        $result = $this->folder->fold($occasions, $this->today);

        self::assertCount(1, $result['rows']);
        self::assertSame('byWeek', $result['rows'][0]['repeat']);
        self::assertSame('https://schema.org/Thursday', $result['rows'][0]['weekDays'][0]);
        self::assertSame('2026-02-26', $result['rows'][0]['date']);
        self::assertSame('19:00:00', $result['rows'][0]['startTime']);
        self::assertSame('22:00:00', $result['rows'][0]['endTime']);
        self::assertSame([], $result['dropped']);
    }

    public function testFortnightlyGapSetsWeeksInterval(): void
    {
        $result = $this->folder->fold($this->moments(['2026-11-05 18:00', '2026-11-19 18:00', '2026-12-03 18:00']), $this->today);

        self::assertSame(2, $result['rows'][0]['weeksInterval']);
    }

    public function testMissingWeekSplitsIntoTwoSeries(): void
    {
        $dates = [
            '2026-11-05 19:00', '2026-11-12 19:00', '2026-11-19 19:00',
            '2026-12-10 19:00', '2026-12-17 19:00', '2026-12-24 19:00',
        ];

        $result = $this->folder->fold($this->moments($dates), $this->today);

        self::assertSame(['byWeek', 'byWeek'], array_column($result['rows'], 'repeat'));
        self::assertSame(['2026-11-05', '2026-12-10'], array_column($result['rows'], 'date'));
    }

    public function testStrayDateNextToASeriesBecomesASingleRow(): void
    {
        $dates = ['2026-11-05 19:00', '2026-11-12 19:00', '2026-11-19 19:00', '2026-12-24 19:00'];

        $result = $this->folder->fold($this->moments($dates), $this->today);

        self::assertSame(['byWeek', 'no'], array_column($result['rows'], 'repeat'));
    }

    public function testDifferentClockTimesAreNotFolded(): void
    {
        $dates = ['2026-11-05 19:00', '2026-11-12 20:00', '2026-11-19 19:00'];

        $result = $this->folder->fold($this->moments($dates), $this->today);

        self::assertSame(['no', 'no', 'no'], array_column($result['rows'], 'repeat'));
    }

    public function testDailyDatesAreNotFoldedIntoAWeeklyRow(): void
    {
        $result = $this->folder->fold($this->moments(['2026-11-05 10:00', '2026-11-06 10:00', '2026-11-07 10:00']), $this->today);

        self::assertSame(['no', 'no', 'no'], array_column($result['rows'], 'repeat'));
    }

    public function testMultiDayPeriodKeepsItsEndDate(): void
    {
        $result = $this->folder->fold([$this->moment('2026-10-31 08:00', '2026-11-01 18:00')], $this->today);

        self::assertSame('no', $result['rows'][0]['repeat']);
        self::assertSame('2026-10-31', $result['rows'][0]['date']);
        self::assertSame('2026-11-01', $result['rows'][0]['untilDate']);
        self::assertSame('18:00:00', $result['rows'][0]['endTime']);
    }

    public function testSingleRowUsesSameDayForUntilDate(): void
    {
        $result = $this->folder->fold([$this->moment('2026-11-05 18:00', '2026-11-05 20:00')], $this->today);

        self::assertSame('2026-11-05', $result['rows'][0]['untilDate']);
        self::assertSame('20:00:00', $result['rows'][0]['endTime']);
    }

    public function testRowsAreSortedChronologically(): void
    {
        $result = $this->folder->fold($this->moments(['2026-12-01 10:00', '2026-11-01 10:00', '2026-11-20 10:00']), $this->today);

        self::assertSame(['2026-11-01', '2026-11-20', '2026-12-01'], array_column($result['rows'], 'date'));
    }

    public function testMoreThanTenRowsKeepsUpcomingDatesFirstAndReportsTheRest(): void
    {
        $starts = [];
        for ($day = 1; $day <= 8; $day++) {
            $starts[] = sprintf('2026-09-%02d 10:00', $day * 3);
        }
        $starts[] = '2026-10-23 12:00';
        $starts[] = '2026-10-27 12:00';
        $starts[] = '2026-11-03 12:00';
        $starts[] = '2026-11-12 12:00';
        $starts[] = '2026-11-14 12:00';

        $result = $this->folder->fold($this->moments($starts), $this->today);

        self::assertCount(OccasionFolder::MAX_ROWS, $result['rows']);
        self::assertCount(3, $result['dropped']);
        self::assertContains('2026-11-14', array_column($result['rows'], 'date'));
        self::assertNotContains('2026-09-03', array_column($result['rows'], 'date'));
        self::assertContains('2026-09-03 10:00', $result['dropped']);
    }

    public function testWeeklyRowsOutrankSingleDatesWhenTrimming(): void
    {
        $starts = ['2026-11-05 19:00', '2026-11-12 19:00', '2026-11-19 19:00'];
        for ($day = 1; $day <= 12; $day++) {
            $starts[] = sprintf('2026-12-%02d 12:00', $day * 2);
        }

        $result = $this->folder->fold($this->moments($starts), $this->today);

        self::assertCount(10, $result['rows']);
        self::assertContains('byWeek', array_column($result['rows'], 'repeat'));
        self::assertCount(3, $result['dropped']);
    }

    public function testEmptyInputGivesNoRows(): void
    {
        self::assertSame(['rows' => [], 'dropped' => []], $this->folder->fold([], $this->today));
    }
}

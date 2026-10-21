<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Import;

use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Import\EventMerger;
use VastsverigeEvents\Import\Importer;
use VastsverigeEvents\Import\TrashGuard;
use VastsverigeEvents\Tests\Support\FakeRepository;
use VastsverigeEvents\Tests\Support\FakeSource;

final class ImporterTest extends TestCase
{
    private FakeSource $source;

    private FakeRepository $repository;

    protected function setUp(): void
    {
        $this->source     = new FakeSource(
            [
                1 => FakeSource::event(1, 'Alpha'),
                2 => FakeSource::event(2, 'Beta'),
                3 => FakeSource::event(3, 'Gamma'),
            ]
        );
        $this->repository = new FakeRepository();
    }

    private function importer(): Importer
    {
        return new Importer($this->source, $this->repository, new EventMerger(), new TrashGuard(), '2671', 'publish');
    }

    public function testWritesEveryEventAndCountsThem(): void
    {
        $report = $this->importer()->run();

        self::assertTrue($report->ok);
        self::assertSame(3, $report->created);
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->repository->savedTitles());
    }

    public function testFailsWithoutEventManager(): void
    {
        $this->repository->available = false;

        $report = $this->importer()->run();

        self::assertFalse($report->ok);
        self::assertSame([], $this->repository->saved);
    }

    public function testListFailureWritesAndTrashesNothing(): void
    {
        $this->repository->managed = [10 => ['1'], 20 => ['2']];
        $this->source->listFails   = true;

        $report = $this->importer()->run();

        self::assertFalse($report->ok);
        self::assertSame([], $this->repository->saved);
        self::assertSame([], $this->repository->trashed);
    }

    public function testEventsWithTheSameHeadingAreMergedIntoOnePost(): void
    {
        $this->source->details[4] = FakeSource::event(4, 'alpha ', '2026-11-12T00:00:00+01:00');

        $this->importer()->run();

        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->repository->savedTitles());
        $alpha = array_values(array_filter($this->repository->saved, static fn ($e): bool => $e->title === 'Alpha'))[0];
        self::assertSame(['1', '4'], $alpha->sourceIds);
        self::assertCount(2, $alpha->occasions);
    }

    public function testEmptyDetailsAndMissingHeadingsAreSkipped(): void
    {
        $this->source->details[4] = null;
        $this->source->details[5] = ['Id' => 5, 'Heading' => '   '];

        $report = $this->importer()->run();

        self::assertSame(2, $report->skipped);
        self::assertSame(3, $report->created);
    }

    public function testUnchangedEventsAreCountedSeparately(): void
    {
        $this->repository->unchanged = ['Beta'];

        $report = $this->importer()->run();

        self::assertSame(2, $report->created);
        self::assertSame(1, $report->unchanged);
    }

    public function testLimitWritesTheFirstEventsByIdAndNeverTrashes(): void
    {
        $this->repository->managed = [99 => ['777']];

        $report = $this->importer()->run(2);

        self::assertSame(['Alpha', 'Beta'], $this->repository->savedTitles());
        self::assertSame([], $this->repository->trashed);
        self::assertSame(0, $report->trashed);
    }

    public function testHeadingFilterImportsMatchesOnlyAndNeverTrashes(): void
    {
        $this->repository->managed = [99 => ['777']];

        $this->importer()->run(null, 'bet');

        self::assertSame(['Beta'], $this->repository->savedTitles());
        self::assertSame([], $this->repository->trashed);
    }

    public function testFullRunTrashesPostsWhoseSourceIdsLeftTheApi(): void
    {
        $this->repository->managed = [10 => ['1'], 20 => ['2'], 30 => ['3'], 99 => ['777']];

        $report = $this->importer()->run();

        self::assertTrue($report->ok);
        self::assertSame([99], $this->repository->trashed);
        self::assertSame(1, $report->trashed);
    }

    public function testFailedDetailRequestBlocksTrashingAndKeepsTheRunGoing(): void
    {
        $this->repository->managed   = [10 => ['1'], 99 => ['777']];
        $this->source->failingDetails = [2];

        $report = $this->importer()->run();

        self::assertSame(['Alpha', 'Gamma'], $this->repository->savedTitles());
        self::assertSame([], $this->repository->trashed);
        self::assertStringContainsString('Detail 2 failed', implode("\n", $report->messages));
    }

    public function testSaveFailureBlocksTrashingAndFailsTheRun(): void
    {
        $this->repository->managed = [99 => ['777']];
        $this->repository->failing = ['Beta'];

        $report = $this->importer()->run();

        self::assertFalse($report->ok);
        self::assertSame([], $this->repository->trashed);
        self::assertSame(2, $report->created);
    }

    public function testIncompleteIdListBlocksTrashing(): void
    {
        $this->repository->managed = [99 => ['777']];
        $this->source->total       = 50;

        $report = $this->importer()->run();

        self::assertSame([], $this->repository->trashed);
        self::assertStringContainsString('Trashing skipped', implode("\n", $report->messages));
        self::assertTrue($report->ok);
    }

    public function testEmptyApiAnswerDoesNotTrashTheCalendar(): void
    {
        $this->repository->managed = [10 => ['1'], 20 => ['2'], 30 => ['3']];
        $this->source->details     = [];

        $report = $this->importer()->run();

        self::assertSame([], $this->repository->trashed);
        self::assertFalse($report->ok);
    }

    public function testMassTrashIsRefusedThenAllowedWithTheFlag(): void
    {
        $managed = [];
        for ($i = 100; $i < 120; $i++) {
            $managed[$i] = [(string) $i];
        }
        $managed[10]               = ['1'];
        $this->repository->managed = $managed;

        $refused = $this->importer()->run();

        self::assertFalse($refused->ok);
        self::assertSame([], $this->repository->trashed);

        $allowed = $this->importer()->run(null, null, true);

        self::assertTrue($allowed->ok);
        self::assertCount(20, $this->repository->trashed);
    }

    public function testCategoryFailureStillImportsAndLeavesCategoriesAlone(): void
    {
        $this->source->categoriesFail = true;

        $report = $this->importer()->run();

        self::assertTrue($report->ok);
        self::assertSame(3, $report->created);
        self::assertNull($this->repository->saved[0]->categories);
        self::assertStringContainsString('Categories unavailable', implode("\n", $report->messages));
    }

    public function testCategoriesAreMappedWhenAvailable(): void
    {
        $this->importer()->run();

        self::assertSame(['Musik'], $this->repository->saved[0]->categories);
    }

    public function testDroppedOccasionsAreLoggedWithTheirDates(): void
    {
        $dates = [];
        for ($i = 0; $i < 13; $i++) {
            $dates[] = ['Date' => sprintf('2027-01-%02dT00:00:00+01:00', 1 + $i * 2), 'Times' => [['Start' => '10:00', 'End' => '11:00']]];
        }
        $this->source->details = [7 => ['Id' => 7, 'Heading' => 'Many dates', 'Dates' => $dates]];

        $report = $this->importer()->run();

        self::assertStringContainsString('dropped 3 occasion rows', implode("\n", $report->messages));
        self::assertStringContainsString('2027-01-', implode("\n", $report->messages));
    }

    public function testSecondRunSendsTheSameSourceIdsAndUid(): void
    {
        $this->importer()->run();
        $this->importer()->run();

        self::assertSame($this->repository->saved[0]->uid, $this->repository->saved[3]->uid);
        self::assertSame($this->repository->saved[0]->sourceIds, $this->repository->saved[3]->sourceIds);
    }
}

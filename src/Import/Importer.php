<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

use VastsverigeEvents\Api\ApiException;
use VastsverigeEvents\Api\EventSource;
use VastsverigeEvents\ImportReport;
use VastsverigeEvents\Mapping\EventRepository;
use VastsverigeEvents\Mapping\SaveResult;

/**
 * Fetches Västsverige events and writes them as Event Manager posts.
 *
 * Posts are only trashed after a full run in which every request succeeded.
 */
class Importer
{
    public function __construct(
        private EventSource $source,
        private EventRepository $repository,
        private EventMerger $merger,
        private TrashGuard $guard,
        private string $configId,
        private string $newPostStatus = 'publish',
    ) {
    }

    /**
     * @param int|null    $limit          Maximum merged events to write. Disables trashing.
     * @param string|null $heading        Case-insensitive title filter. Disables trashing.
     * @param bool        $allowMassTrash Skip the safety limit on how many events one run may trash.
     */
    public function run(?int $limit = null, ?string $heading = null, bool $allowMassTrash = false): ImportReport
    {
        $report = new ImportReport();

        if (! $this->repository->isAvailable()) {
            $report->fail('Event Manager is not active on this site.');
            return $report;
        }

        try {
            $list = $this->source->listIds($this->configId);
        } catch (ApiException $exception) {
            $report->fail('Id list failed: ' . $exception->getMessage());
            return $report;
        }

        $categories = $this->categories($report);
        $groups     = $this->fetchGroups($list->ids, $heading, $report, $failedIds);

        $events = [];
        foreach ($groups as $details) {
            $events[] = $this->merger->merge($details, $categories);
        }

        usort($events, static fn (MergedEvent $a, MergedEvent $b): int => (int) ($a->sourceIds[0] ?? 0) <=> (int) ($b->sourceIds[0] ?? 0));
        if ($limit !== null && $limit > 0) {
            $events = array_slice($events, 0, $limit);
        }

        foreach ($events as $event) {
            $this->write($event, $report);
        }

        $partial = ($limit !== null && $limit > 0) || ($heading !== null && $heading !== '');
        if ($partial) {
            return $report;
        }

        if ($failedIds !== []) {
            $report->add(sprintf('Trashing skipped: %d detail requests failed.', count($failedIds)));
            return $report;
        }

        if (! $list->isComplete()) {
            $report->add(sprintf('Trashing skipped: the API reports %d events but listed %d.', (int) $list->total, count($list->ids)));
            return $report;
        }

        if (! $report->ok) {
            $report->add('Trashing skipped: some events were not saved.');
            return $report;
        }

        $this->trashMissing($list->ids, $allowMassTrash, $report);

        return $report;
    }

    /**
     * @return array<int, string>|null Null when the labels could not be fetched.
     */
    private function categories(ImportReport $report): ?array
    {
        try {
            return $this->source->categories();
        } catch (ApiException $exception) {
            $report->add('Categories unavailable, existing categories are kept: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Fetches every detail and groups them by heading.
     *
     * @param list<int>         $ids
     * @param list<string>|null $failedIds Filled with ids whose detail request failed.
     * @return array<string, list<array<string, mixed>>>
     */
    private function fetchGroups(array $ids, ?string $heading, ImportReport $report, ?array &$failedIds): array
    {
        $failedIds = [];
        $groups    = [];
        $needle    = $heading !== null && $heading !== '' ? mb_strtolower($heading) : null;

        foreach ($ids as $id) {
            try {
                $detail = $this->source->detail($id);
            } catch (ApiException $exception) {
                $failedIds[] = (string) $id;
                $report->add(sprintf('Detail %d failed: %s', $id, $exception->getMessage()));
                continue;
            }

            $title = $detail === null ? '' : EventMerger::cleanTitle((string) ($detail['Heading'] ?? ''));
            if ($title === '') {
                $report->skipped++;
                continue;
            }

            if ($needle !== null && ! str_contains(mb_strtolower($title), $needle)) {
                continue;
            }

            $groups[EventMerger::groupKey($title)][] = $detail;
        }

        return $groups;
    }

    private function write(MergedEvent $event, ImportReport $report): void
    {
        try {
            $result = $this->repository->save($event, $this->newPostStatus);
        } catch (\RuntimeException $exception) {
            $report->fail(sprintf('"%s" was not saved: %s', $event->title, $exception->getMessage()));
            return;
        }

        match ($result->action) {
            SaveResult::CREATED   => $report->created++,
            SaveResult::UPDATED   => $report->updated++,
            SaveResult::UNCHANGED => $report->unchanged++,
            default               => $report->skipped++,
        };

        foreach ($result->warnings as $warning) {
            $report->add(sprintf('"%s": %s', $event->title, $warning));
        }

        if ($event->dropped !== []) {
            $report->add(
                sprintf(
                    '"%s" dropped %d occasion rows above the limit of %d: %s',
                    $event->title,
                    count($event->dropped),
                    OccasionFolder::MAX_ROWS,
                    implode(', ', $event->dropped)
                )
            );
        }
    }

    /**
     * @param list<int> $listedIds
     */
    private function trashMissing(array $listedIds, bool $allowMassTrash, ImportReport $report): void
    {
        $plan = $this->guard->plan($this->repository->managedPosts(), $listedIds, $allowMassTrash);

        if ($plan->refusal !== null) {
            $report->fail($plan->refusal);
            return;
        }

        foreach ($plan->postIds as $postId) {
            $this->repository->trash($postId);
            $report->trashed++;
        }
    }
}

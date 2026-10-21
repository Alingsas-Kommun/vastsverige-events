<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Support;

use VastsverigeEvents\Import\MergedEvent;
use VastsverigeEvents\Mapping\EventRepository;
use VastsverigeEvents\Mapping\SaveResult;

/**
 * In-memory event store for importer tests.
 */
final class FakeRepository implements EventRepository
{
    public bool $available = true;

    /** @var array<int, list<string>> */
    public array $managed = [];

    /** @var list<MergedEvent> */
    public array $saved = [];

    /** @var list<int> */
    public array $trashed = [];

    /** @var list<string> Titles that fail to save. */
    public array $failing = [];

    /** @var list<string> Titles reported as already up to date. */
    public array $unchanged = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function save(MergedEvent $event, string $newPostStatus): SaveResult
    {
        if (in_array($event->title, $this->failing, true)) {
            throw new \RuntimeException('database said no');
        }

        $this->saved[] = $event;

        if (in_array($event->title, $this->unchanged, true)) {
            return new SaveResult(SaveResult::UNCHANGED);
        }

        return new SaveResult(SaveResult::CREATED);
    }

    public function managedPosts(): array
    {
        return $this->managed;
    }

    public function trash(int $postId): void
    {
        $this->trashed[] = $postId;
    }

    /**
     * @return list<string>
     */
    public function savedTitles(): array
    {
        $titles = array_map(static fn (MergedEvent $event): string => $event->title, $this->saved);
        sort($titles);

        return $titles;
    }
}

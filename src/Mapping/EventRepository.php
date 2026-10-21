<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

use VastsverigeEvents\Import\MergedEvent;

/**
 * Storage for imported events.
 */
interface EventRepository
{
    /**
     * False when Event Manager is not active on this site.
     */
    public function isAvailable(): bool;

    /**
     * @throws \RuntimeException When the event cannot be stored.
     */
    public function save(MergedEvent $event, string $newPostStatus): SaveResult;

    /**
     * Source ids by post id for every live imported post.
     *
     * @return array<int, list<string>>
     */
    public function managedPosts(): array;

    public function trash(int $postId): void;
}

<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Which existing post, if any, an incoming event belongs to.
 */
final class PostChoice
{
    public const CREATE  = 'create';
    public const UPDATE  = 'update';
    public const RESTORE = 'restore';
    public const SKIP    = 'skip';

    /**
     * @param 'create'|'update'|'restore'|'skip' $action
     * @param list<int>                          $duplicates Other live posts that carry the same source ids.
     */
    public function __construct(
        public readonly string $action,
        public readonly int $postId = 0,
        public readonly array $duplicates = [],
    ) {
    }
}

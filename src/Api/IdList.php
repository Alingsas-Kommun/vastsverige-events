<?php

declare(strict_types=1);

namespace VastsverigeEvents\Api;

/**
 * Event ids returned by the API.
 */
final class IdList
{
    /**
     * @param list<int> $ids
     * @param int|null  $total    Count the API claims to hold, when it reports one.
     */
    public function __construct(
        public readonly array $ids,
        public readonly ?int $total = null,
    ) {
    }

    /**
     * True when the ids cover everything the API says it holds.
     */
    public function isComplete(): bool
    {
        return $this->total === null || count($this->ids) >= $this->total;
    }
}

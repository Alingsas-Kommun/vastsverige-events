<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

/**
 * Posts to trash after a complete run, or the reason trashing was refused.
 */
final class TrashPlan
{
    /**
     * @param list<int> $postIds
     */
    public function __construct(
        public readonly array $postIds,
        public readonly ?string $refusal = null,
    ) {
    }
}

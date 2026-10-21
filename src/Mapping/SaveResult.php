<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Outcome of writing one event.
 */
final class SaveResult
{
    public const CREATED   = 'created';
    public const UPDATED   = 'updated';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED   = 'skipped';

    /**
     * @param 'created'|'updated'|'unchanged'|'skipped' $action
     * @param list<string>                              $warnings Problems that did not stop the write.
     */
    public function __construct(
        public readonly string $action,
        public readonly array $warnings = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

/**
 * Decides which imported posts have left the API, and refuses implausibly large clean-ups.
 *
 * A trashed event disappears from Typesense on the next sync, so a bad API answer must not
 * be able to empty the calendar.
 */
class TrashGuard
{
    public function __construct(
        private float $maxRatio = 0.3,
        private int $minAllowance = 5,
    ) {
    }

    /**
     * @param array<int, list<string>> $managed   Source ids by post id for every live imported post.
     * @param list<int>                $listedIds Ids the API returned in this run.
     */
    public function plan(array $managed, array $listedIds, bool $allowMass = false): TrashPlan
    {
        if ($managed === []) {
            return new TrashPlan([]);
        }

        if ($listedIds === []) {
            return new TrashPlan([], 'The API listed no events, so nothing was trashed.');
        }

        $listed     = array_fill_keys(array_map('strval', $listedIds), true);
        $candidates = [];

        foreach ($managed as $postId => $sourceIds) {
            if ($sourceIds === []) {
                continue;
            }

            $present = false;
            foreach ($sourceIds as $sourceId) {
                if (isset($listed[(string) $sourceId])) {
                    $present = true;
                    break;
                }
            }

            if (! $present) {
                $candidates[] = (int) $postId;
            }
        }

        $allowance = max($this->minAllowance, (int) floor(count($managed) * $this->maxRatio));
        if (count($candidates) > $allowance && ! $allowMass) {
            return new TrashPlan(
                [],
                sprintf(
                    'Refusing to trash %d of %d imported events (limit %d). Check the API, then rerun with --allow-mass-trash.',
                    count($candidates),
                    count($managed),
                    $allowance
                )
            );
        }

        return new TrashPlan($candidates);
    }
}

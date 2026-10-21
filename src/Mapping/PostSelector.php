<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Picks the hub post an incoming event is written to.
 *
 * The lowest live post id wins so the identity the public site stores stays stable. A post the
 * importer trashed is restored when its event returns. A post an editor trashed is left alone.
 */
class PostSelector
{
    /**
     * @param list<array{id: int, trashed: bool, byImport: bool}> $candidates Posts holding at least one of the event's source ids.
     */
    public function choose(array $candidates): PostChoice
    {
        usort($candidates, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        $live = array_values(array_filter($candidates, static fn (array $c): bool => ! $c['trashed']));
        if ($live !== []) {
            $primary    = $live[0]['id'];
            $duplicates = array_values(array_map(static fn (array $c): int => $c['id'], array_slice($live, 1)));

            return new PostChoice(PostChoice::UPDATE, $primary, $duplicates);
        }

        foreach ($candidates as $candidate) {
            if ($candidate['byImport']) {
                return new PostChoice(PostChoice::RESTORE, $candidate['id']);
            }
        }

        if ($candidates !== []) {
            return new PostChoice(PostChoice::SKIP, $candidates[0]['id']);
        }

        return new PostChoice(PostChoice::CREATE);
    }
}

<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Query;

/**
 * Reciprocal Rank Fusion: a document scores Σ w / (k + rank) over the ranked
 * lists it appears in (rank from 1, w the list's weight, 1 by default). Only
 * ranks count, so scores of different kinds (ts_rank, cosine distance) never
 * have to be made comparable.
 */
final class ReciprocalRankFusion
{
    /**
     * @param  list<list<int|string>>  $rankings  ids, best first
     * @param  list<float>  $weights  one per ranking; missing ones are 1
     * @return array<int|string, float> id => score, best first; ties keep the
     *                                  order in which ids were first seen
     */
    public static function fuse(array $rankings, int $k = 60, array $weights = []): array
    {
        $scores = [];
        foreach ($rankings as $n => $ranking) {
            $weight = $weights[$n] ?? 1.0;
            foreach (array_values(array_unique($ranking)) as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + $weight / ($k + $rank + 1);
            }
        }

        $order = array_flip(array_keys($scores));
        uksort($scores, static fn (int|string $a, int|string $b): int => [$scores[$b], $order[$a]] <=> [$scores[$a], $order[$b]]);

        return $scores;
    }
}

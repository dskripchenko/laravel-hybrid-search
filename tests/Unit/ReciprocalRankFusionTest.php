<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Query\ReciprocalRankFusion;

it('scores by the sum of reciprocal ranks', function (): void {
    $scores = ReciprocalRankFusion::fuse([[1, 2, 3], [3, 4, 1]], 60);

    expect(array_keys($scores))->toBe([1, 3, 2, 4])
        ->and($scores[1])->toEqualWithDelta(1 / 61 + 1 / 63, 1e-12)
        ->and($scores[3])->toEqualWithDelta(1 / 63 + 1 / 61, 1e-12)
        ->and($scores[2])->toEqualWithDelta(1 / 62, 1e-12);
});

it('lets agreement beat a single first place', function (): void {
    // 9 is second in both lists, 7 and 8 first in one each.
    expect(array_key_first(ReciprocalRankFusion::fuse([[7, 9], [8, 9]], 60)))->toBe(9);
});

it('breaks ties by first appearance and ignores repeats', function (): void {
    expect(array_keys(ReciprocalRankFusion::fuse([['a', 'b'], ['b', 'a']])))->toBe(['a', 'b'])
        ->and(ReciprocalRankFusion::fuse([['a', 'a', 'b']], 0))->toBe(['a' => 1.0, 'b' => 0.5]);
});

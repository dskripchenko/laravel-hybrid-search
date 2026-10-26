<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Vector;

/**
 * Typed reads of `hybrid-search.vector.*`: env values arrive as strings, and
 * every reader of the settings (migration, indexer, query, command) must
 * agree on them.
 */
final class VectorConfig
{
    public static function enabled(): bool
    {
        return filter_var(config('hybrid-search.vector.enabled', false), FILTER_VALIDATE_BOOL);
    }

    public static function table(): string
    {
        $table = config('hybrid-search.vector.table');

        return is_string($table) && $table !== '' ? $table : 'search_chunks';
    }

    public static function dimensions(): int
    {
        return self::int('dimensions', 1024);
    }

    public static function chunkChars(): int
    {
        return self::int('chunk_chars', 1200);
    }

    public static function chunkOverlap(): int
    {
        return self::int('chunk_overlap', 150);
    }

    public static function candidates(): int
    {
        return max(1, self::int('candidates', 50));
    }

    public static function rrfK(): int
    {
        return max(0, self::int('rrf_k', 60));
    }

    public static function maxDistance(): ?float
    {
        $distance = config('hybrid-search.vector.max_distance');

        return is_numeric($distance) ? (float) $distance : null;
    }

    public static function iterativeScan(): ?string
    {
        $mode = config('hybrid-search.vector.iterative_scan');

        return is_string($mode) && $mode !== '' && $mode !== 'off' ? $mode : null;
    }

    private static function int(string $key, int $default): int
    {
        $value = config('hybrid-search.vector.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}

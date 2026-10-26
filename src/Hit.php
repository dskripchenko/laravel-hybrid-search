<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch;

/** A found document: its identity, a highlighted snippet and the rank. */
final readonly class Hit
{
    /** @param array<string, mixed> $facets */
    public function __construct(
        public string $type,
        public string $key,
        public string $title,
        public string $snippet,
        public ?string $url,
        public float $score,
        public array $facets,
    ) {}
}

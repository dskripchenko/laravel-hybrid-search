<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch;

/**
 * A found document: its identity, a highlighted snippet and the rank.
 * `byMeaning` — a hybrid query found it by meaning alone: none of its words
 * matched the text, its nearest chunk did.
 */
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
        public bool $byMeaning = false,
    ) {}
}

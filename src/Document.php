<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch;

/**
 * What the index keeps of a record. `accessKey` names the scope that may see
 * it (`team:12`, `group:4`); `facets` are filterable values; `language` is
 * a PostgreSQL text search configuration (russian, english, simple, …).
 */
final readonly class Document
{
    /** @param array<string, string|int|float|bool|null> $facets */
    public function __construct(
        public string $type,
        public string $key,
        public string $title,
        public string $body,
        public string $accessKey,
        public array $facets = [],
        public ?string $url = null,
        public ?string $language = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch;

use Dskripchenko\HybridSearch\Contracts\Searchable;
use Dskripchenko\HybridSearch\Query\SearchQuery;

/** The entry point: queries, and writing to the index by hand. */
final class Search
{
    public function __construct(private readonly Indexer $indexer) {}

    public function query(string $text): SearchQuery
    {
        return new SearchQuery($text);
    }

    public function index(Searchable $record): void
    {
        $this->indexer->index($record);
    }

    public function put(Document $document): void
    {
        $this->indexer->put($document);
    }

    public function remove(string $type, string $key): void
    {
        $this->indexer->remove($type, $key);
    }
}

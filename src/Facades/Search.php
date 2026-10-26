<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Dskripchenko\HybridSearch\Query\SearchQuery query(string $text)
 * @method static void index(\Dskripchenko\HybridSearch\Contracts\Searchable $record)
 * @method static void put(\Dskripchenko\HybridSearch\Document $document)
 * @method static void remove(string $type, string $key)
 *
 * @see \Dskripchenko\HybridSearch\Search
 */
final class Search extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Dskripchenko\HybridSearch\Search::class;
    }
}

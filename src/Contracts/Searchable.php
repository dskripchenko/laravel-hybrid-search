<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Contracts;

use Dskripchenko\HybridSearch\Document;

/**
 * A record that can be found. It describes itself as a Document; the access
 * key decides who may see it — queries filter by it in SQL, before ranking.
 */
interface Searchable
{
    public function toSearchDocument(): Document;

    /** The (type, key) the record's document is stored under, also after it is gone. */
    public function searchType(): string;

    public function searchKey(): string;
}

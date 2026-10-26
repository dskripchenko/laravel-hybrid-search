<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Tests\Fixtures;

use Dskripchenko\HybridSearch\Concerns\IndexedForSearch;
use Dskripchenko\HybridSearch\Contracts\Searchable;
use Dskripchenko\HybridSearch\Document;
use Illuminate\Database\Eloquent\Model;

final class Note extends Model implements Searchable
{
    use IndexedForSearch;

    public $timestamps = false;

    protected $guarded = [];

    public function searchType(): string
    {
        return 'note';
    }

    public function searchKey(): string
    {
        return 'N-'.$this->getKey();
    }

    public function toSearchDocument(): Document
    {
        return new Document(
            type: 'note',
            key: $this->searchKey(),
            title: (string) $this->getAttribute('title'),
            body: (string) $this->getAttribute('body'),
            accessKey: (string) $this->getAttribute('scope'),
            facets: ['status' => (string) $this->getAttribute('status')],
            url: '/notes/'.$this->getKey(),
            language: $this->getAttribute('lang'),
        );
    }
}

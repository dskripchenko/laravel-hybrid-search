<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch;

use Dskripchenko\HybridSearch\Contracts\Searchable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/** Writes documents into the index and takes them out. */
final class Indexer
{
    public function index(Searchable $record): void
    {
        $this->put($record->toSearchDocument());
    }

    public function put(Document $document): void
    {
        $language = $document->language ?? Config::string('hybrid-search.language', 'simple');

        $this->db()->table($this->table())->upsert([[
            'type' => $document->type,
            'key' => $document->key,
            'title' => $document->title,
            'body' => $document->body,
            'access_key' => $document->accessKey,
            'facets' => json_encode($document->facets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'url' => $document->url,
            'language' => $language,
            'indexed_at' => now(),
        ]], ['type', 'key'], ['title', 'body', 'access_key', 'facets', 'url', 'language', 'indexed_at']);
    }

    public function remove(string $type, string $key): void
    {
        $this->db()->table($this->table())->where('type', $type)->where('key', $key)->delete();
    }

    public function forgetType(string $type): void
    {
        $this->db()->table($this->table())->where('type', $type)->delete();
    }

    private function db(): ConnectionInterface
    {
        $name = config('hybrid-search.connection');

        return DB::connection(is_string($name) ? $name : null);
    }

    private function table(): string
    {
        return Config::string('hybrid-search.table', 'search_documents');
    }
}

# dskripchenko/laravel-hybrid-search

Search for Laravel on PostgreSQL alone — no search server to run and keep in
sync. Full-text: documents in a `tsvector` with the morphology of their
language, prefix matching while typing, highlighted snippets, facets. Vectors:
Markdown-aware chunks embedded into pgvector, and a hybrid query that fuses
both by Reciprocal Rank Fusion. Both sides do **access filtering inside the
SQL**, so a query can only ever see what the person may see.

## Install

```bash
composer require dskripchenko/laravel-hybrid-search
php artisan migrate
```

PostgreSQL 13+. The database needs a UTF-8 `LC_CTYPE` (e.g. `en_US.UTF-8`;
`LC_COLLATE` may stay `C`): text search lowercases through it, and with
`LC_CTYPE=C` non-Latin words never match their other cases. The migration
checks and refuses such a database.

## Index a model

```php
use Dskripchenko\HybridSearch\Concerns\IndexedForSearch;
use Dskripchenko\HybridSearch\Contracts\Searchable;
use Dskripchenko\HybridSearch\Document;

final class Issue extends Model implements Searchable
{
    use IndexedForSearch;            // saved → indexed, deleted → removed

    public function searchType(): string { return 'issue'; }
    public function searchKey(): string { return $this->key; }

    public function toSearchDocument(): Document
    {
        return new Document(
            type: 'issue',
            key: $this->key,
            title: $this->title,
            body: (string) $this->description,
            accessKey: 'project:'.$this->project_id,   // who may see it
            facets: ['project' => $this->project_id, 'status' => $this->status],
            url: '/issues/'.$this->key,
            language: 'russian',                        // a text search configuration
        );
    }
}
```

With `hybrid-search.queue` on (the default) indexing runs in a job after the
transaction that changed the record commits.

## Search

```php
use Dskripchenko\HybridSearch\Facades\Search;

$hits = Search::query('доска спринт')
    ->accessKeys(['project:12', 'project:15'])   // required: or ->everywhere()
    ->types('issue', 'page')
    ->where('status', 'open')
    ->limit(20)
    ->get();                                     // list<Hit>: type, key, title, snippet, url, score, facets

Search::query('доска')->accessKeys($keys)->countsByType();   // ['issue' => 7, 'page' => 2]
```

- The text is read as typed (websearch syntax: `"phrases"`, `-minus`, `or`)
  and as prefixes of its words, in the russian, english and simple
  configurations at once — one constant query, so the GIN index is used
  whatever language a document is in.
- An exact key (`GAL-12`) ranks first.
- **Access fails closed**: a query without `accessKeys()` throws, an empty
  key list finds nothing, only `everywhere()` lifts the filter.

`php artisan search:reindex` rebuilds the models listed in
`hybrid-search.models`.

## Vector search and hybrid ranking

Needs the [pgvector](https://github.com/pgvector/pgvector) extension in any
schema on the search_path (e.g. `CREATE EXTENSION vector SCHEMA extensions`
and `search_path = public, extensions`) and an embeddings endpoint.

```dotenv
HYBRID_SEARCH_VECTOR=true                                # before `migrate`: creates search_chunks
HYBRID_SEARCH_VECTOR_DIMENSIONS=1024
HYBRID_SEARCH_EMBEDDING_URL=http://127.0.0.1:11434/v1    # any OpenAI-compatible /embeddings
HYBRID_SEARCH_EMBEDDING_MODEL=bge-m3
HYBRID_SEARCH_EMBEDDING_KEY=                             # bearer token, if any
```

Indexing a document then also cuts its body into chunks (headings start
chunks, paragraphs packed to ~1200 characters, code blocks kept whole) and
embeds them — in the queued job, and only the chunks whose text changed.
`php artisan hybrid-search:embed {--type=} {--force}` fills in documents
indexed before.

```php
$query = Search::query('how are customers kept apart')
    ->accessKeys($keys)                          // the same fail-closed filter
    ->hybrid();
$hits = $query->get();                           // full-text ∪ vectors, fused by RRF
$query->degraded();                              // null, or why it ran on full-text alone
```

The package depends on no LLM client: bind your own
`Dskripchenko\HybridSearch\Contracts\Embedder` to use anything else, and
`Embedders\FakeEmbedder` in tests. More in [docs/en](docs/en/usage.md).

## Requirements

PHP 8.2–8.5, Laravel 11–13, PostgreSQL; pgvector 0.8+ for the vector half
(0.5+ with `vector.iterative_scan` set to null).

## License

MIT

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

final class Article extends Model implements Searchable
{
    use IndexedForSearch;            // saved → indexed, deleted → removed

    public function searchType(): string { return 'article'; }
    public function searchKey(): string { return $this->key; }

    public function toSearchDocument(): Document
    {
        return new Document(
            type: 'article',
            key: $this->key,
            title: $this->title,
            body: (string) $this->body,
            accessKey: 'team:'.$this->team_id,          // who may see it
            facets: ['team' => $this->team_id, 'status' => $this->status],
            url: '/articles/'.$this->key,
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

$hits = Search::query('возврат заказа')
    ->accessKeys(['team:12', 'team:15'])         // required: or ->everywhere()
    ->types('article', 'faq')
    ->where('status', 'published')
    ->limit(20)
    ->get();                                     // list<Hit>: type, key, title, snippet, url, score, facets

Search::query('возврат')->accessKeys($keys)->countsByType();   // ['article' => 7, 'faq' => 2]
```

- The text is read as typed (websearch syntax: `"phrases"`, `-minus`, `or`)
  and as prefixes of its words, in the russian, english and simple
  configurations at once — one constant query, so the GIN index is used
  whatever language a document is in.
- An exact key (`KB-12`) ranks first.
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
`Embedders\FakeEmbedder` in tests.

## Documentation

Configuration, the index, ranking, chunking, embedders, hybrid ranking,
access and multi-tenant hosts: [English](docs/en/usage.md) ·
[Русский](docs/ru/usage.md) · [Deutsch](docs/de/usage.md) ·
[中文](docs/zh/usage.md).

## Requirements

PHP 8.2–8.5 with `pdo_pgsql`, Laravel 11–13 (Laravel 13 needs PHP 8.3+),
PostgreSQL 13+; pgvector 0.8+ for the vector half (0.5+ with
`vector.iterative_scan` set to null).

## Testing

The suite needs PostgreSQL (and pgvector for the vector tests); it skips
what it cannot reach. Point it at a database with `HYBRID_SEARCH_TEST_DB_HOST`,
`_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD` (defaults: `127.0.0.1:5432`,
`hybrid_search_test`, `postgres`):

```bash
composer test      # Pest
composer analyse   # PHPStan, level max
composer format    # Pint
```

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

## License

MIT — see [LICENSE](LICENSE).

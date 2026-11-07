# Usage

## Configuration (`config/hybrid-search.php`)

| Key | Default | |
|---|---|---|
| `connection` | null (default) | the connection of the index; point it at a separate PostgreSQL when the index outgrows the application's |
| `table` | `search_documents` | |
| `language` | `simple` | the text search configuration of documents that name none |
| `queue` | `true` | index in a job after commit; `false` indexes inline |
| `queue_connection`, `queue_name` | null | where the jobs go |
| `models` | `[]` | what `search:reindex` walks through |
| `snippet.max_words`, `snippet.min_words` | 30, 12 | the highlighted fragment; `max_words` also trims the snippet of a vector-only hit |
| `vector.enabled` | `false` (`HYBRID_SEARCH_VECTOR`) | the chunks migration, embedding on index, `hybrid()` |
| `vector.table` | `search_chunks` | |
| `vector.dimensions` | 1024 (`HYBRID_SEARCH_VECTOR_DIMENSIONS`) | the size of `vector(N)`, 1–2000; must match the embedder |
| `vector.chunk_chars`, `vector.chunk_overlap` | 1200, 150 | chunk size and the overlap within a section, in characters |
| `vector.candidates` | 50 | candidates taken from each side before fusion |
| `vector.rrf_k` | 60 | the k of Reciprocal Rank Fusion |
| `vector.max_distance` | null | drop vector candidates farther than this cosine distance |
| `vector.weights.text`, `vector.weights.meaning` | 1, 1 (`HYBRID_SEARCH_WEIGHT_TEXT`, `HYBRID_SEARCH_WEIGHT_MEANING`) | how much each ranking counts in the fusion |
| `vector.iterative_scan` | `relaxed_order` | pgvector 0.8+ iterative index scans; null on older pgvector |
| `vector.embedder.url` | `http://127.0.0.1:11434/v1` (`HYBRID_SEARCH_EMBEDDING_URL`) | an OpenAI-compatible API base |
| `vector.embedder.model` | `bge-m3` (`HYBRID_SEARCH_EMBEDDING_MODEL`) | |
| `vector.embedder.key` | null (`HYBRID_SEARCH_EMBEDDING_KEY`) | sent as a bearer token |
| `vector.embedder.batch`, `vector.embedder.timeout` | 32, 60 | texts per request, seconds per request |
| `vector.embedder.query_prefix`, `vector.embedder.document_prefix` | empty (`HYBRID_SEARCH_QUERY_PREFIX`, `HYBRID_SEARCH_DOCUMENT_PREFIX`) | put before queries and chunks when embedded: `query: ` and `passage: ` for e5; none for bge-m3 and OpenAI. A new document prefix embeds the chunks again |

## The index

One row per document: `type`, `key` (unique together), `title`, `body`,
`access_key`, `facets` (jsonb), `url`, `language` (regconfig) and a generated
`tsv` — title weighted A, body B. Indexes: GIN on `tsv`, B-tree on
`(access_key, type)`, GIN on `facets`.

## Ranking

`ts_rank_cd` of the websearch query counts twice, the prefix query once; an
exact key adds 10. Ties go to the more recently indexed document.

## Vector search

### Storage

A second migration creates `search_chunks`: `document_id` (references the
documents, `on delete cascade`), `ordinal`, `content`, `content_hash`,
`embedding vector(N)`, `model`, `created_at`; an HNSW index with
`vector_cosine_ops`. It runs only with `vector.enabled` on — turn it on before
migrating, or roll that migration back and run it again — and it stops with a
message when the `vector` type is not reachable. The type is never
schema-qualified: install pgvector in any schema and put that schema on the
search_path (`ALTER DATABASE … SET search_path = public, extensions`).

### Chunking

`Chunking\MarkdownChunker` cuts the body: an ATX heading starts a chunk and
is repeated at the top of every chunk of its section; paragraphs are packed up
to `chunk_chars`; a chunk continuing a section begins with the last
`chunk_overlap` characters of the prose before it; a fenced code block is
kept whole while it fits in 1.5 × `chunk_chars`, otherwise cut between lines
and re-fenced; an over-long paragraph is cut between sentences, then words.
What is embedded is the title, a blank line and the chunk.

### Embedders

```php
interface Embedder
{
    /** @param list<string> $texts @return list<list<float>> */
    public function embed(array $texts): array;
    public function model(): string;      // stored with each chunk
    public function dimensions(): int;
}
```

`Embedders\OpenAiCompatibleEmbedder` posts `{model, input: [...]}` to
`{url}/embeddings` in batches and reorders the answer by `index` — OpenAI,
Ollama's `/v1`, vLLM, LocalAI. Bind your own implementation to replace it:

```php
$this->app->singleton(Embedder::class, MyEmbedder::class);
```

`Embedders\FakeEmbedder` is deterministic (a hashed bag of words, with an
optional synonym map standing in for meaning) and records what it embedded;
`failWith()` makes it throw.

### Indexing

With vector search on, `Indexer::put()` — and so `IndexRecord`, the
`IndexedForSearch` trait and `search:reindex` — rebuilds the document's chunks
after storing it. A chunk is identified by the hash of the model and its
embedded text: unchanged chunks keep their embeddings (copied inside the
database), only new ones go to the embedder, and a document with nothing
changed is not written at all. A changed title or model re-embeds every
chunk. Embedding is slow, so keep `queue` on; an embedder failure fails the
job, and the retry redoes the whole document.

`php artisan hybrid-search:embed` walks the stored documents (`--type=` one
type, `--force` re-embeds unchanged chunks too, `--chunk=` page size): after
turning vector search on, changing the chunk size or the model.

### Hybrid ranking

`SearchQuery::hybrid()` embeds the query once and takes the top `candidates`
of full-text and the top `candidates` documents by the cosine distance of
their nearest chunk, then fuses the two lists by Reciprocal Rank Fusion:
score = Σ w / (`rrf_k` + rank), with `w` from `vector.weights`. An exact key adds 10, so it still ranks first.
`Hit` keeps its shape: `score` is the fused score; the snippet is the
highlighted full-text fragment, or for a document found by meaning alone the
first `snippet.max_words` words of its best chunk. `limit()` and the offset
page within the fused list. `countsByType()` stays full-text.

Nearest neighbours always exist: without `max_distance` a hybrid query also
returns the documents least far from the question, however far that is.

If the embedder throws (or returns a vector of the wrong size), `get()` runs
the plain full-text query, `degraded()` returns the reason, and the exception
is reported to the application's exception handler. With vector search off,
`hybrid()` is plain full-text and `degraded()` says so.

## Access

Every document carries one access key; a query passes the keys of the person
asking. Build the keys from your own permission model — for a knowledge base,
`team:<id>` for every team whose articles the person may read. The filter is part of
the WHERE clause, applied before ranking and limiting, never afterwards — on
the vector side as well: chunks are joined to their documents and filtered by
the same access, type and facet conditions inside the nearest-neighbour scan
(pgvector's iterative scan keeps going until enough rows pass). No
`accessKeys()` throws, an empty list returns nothing without calling the
embedder.

## Multi-tenant hosts

Nothing is cached in the process. With a schema per tenant the tables live in
each tenant's schema (run the migrations per tenant; `vendor:publish
--tag=hybrid-search-migrations` copies them) and the queued jobs carry your
tenant context as any other job does. pgvector is installed once per database
in a shared schema (e.g. `extensions`); each tenant's search_path must include
it (`tenant_42, extensions`), at migration time and at query time.

## Roadmap

- chunking of OpenAPI by operations;
- `halfvec` for models over 2000 dimensions;
- answers with citations (retrieval-augmented generation) through
  dskripchenko/laravel-llm.

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
| `snippet.max_words`, `snippet.min_words` | 30, 12 | the highlighted fragment |

## The index

One row per document: `type`, `key` (unique together), `title`, `body`,
`access_key`, `facets` (jsonb), `url`, `language` (regconfig) and a generated
`tsv` — title weighted A, body B. Indexes: GIN on `tsv`, B-tree on
`(access_key, type)`, GIN on `facets`.

## Ranking

`ts_rank_cd` of the websearch query counts twice, the prefix query once; an
exact key adds 10. Ties go to the more recently indexed document.

## Access

Every document carries one access key; a query passes the keys of the person
asking. Build the keys from your own permission model — for a tracker,
`project:<id>` for every project the person may browse. The filter is part of
the WHERE clause, applied before ranking and limiting, never afterwards.

## Multi-tenant hosts

Nothing is cached in the process. With a schema per tenant the table lives in
each tenant's schema (run the migration per tenant) and the queued jobs carry
your tenant context as any other job does.

## Roadmap

- vector search with pgvector (HNSW, filtered iteratively), Reciprocal Rank
  Fusion with the full-text ranks;
- chunking of Markdown by headings and OpenAPI by operations;
- answers with citations (retrieval-augmented generation) through
  dskripchenko/laravel-llm.

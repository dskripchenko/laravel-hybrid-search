# Changelog

All notable changes to this project are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.1] — 2026-11-09

### Added
- `SearchQuery::prefixes(false)`: match words whole, not their beginnings —
  several times cheaper on a large index.

## [0.4.0] — 2026-11-09

### Added
- Filters: `whereIn()`, `whereBetween()` (numeric facets), `whereContains()`
  (list facets); `Document::$facets` may hold lists.
- Order: `orderByFacet()`; `orderByNewest()` by the order documents entered
  the index, with `fewMatches` to sort instead of walking the index.
- Bounded cost on large indexes: `counts()` (the total and several facets in
  one pass), `upTo` on `countsByType()` and `countsBy()`, `candidates()`,
  `withinNewest()`.

### Fixed
- A B-tree index on `lower(key)` (new migration): the exact-key match OR-ed
  with full-text made PostgreSQL read the whole table.

## [0.3.0] — 2026-11-09

### Added
- `Hit::$byMeaning`: true for a document a hybrid query found by meaning
  alone, none of its words matching.
- `SearchQuery::countsBy($facet)`: matches per value of a facet, under every
  filter but the one on that facet.
- `vector.embedder.class` (`HYBRID_SEARCH_EMBEDDER`): the embedder named by
  class, made by the container; the OpenAI-compatible client stays the
  default.

## [0.2.1] — 2026-11-07

### Fixed
- PHPStan on Laravel 11 and 12: `transaction()` returns `mixed` in their type
  stubs, so the vector query's result is taken out by reference.

## [0.2.0] — 2026-11-07

### Fixed
- A word excluded with a minus (`refund -card`) came back through the prefix
  half of the query, which took every word as a prefix to match. The prefix
  half now excludes those words too; a hyphen inside a word (`KB-12`) is not
  a minus.

### Added
- `vector.weights` (`text`, `meaning`): the weight of each ranking in the
  rank fusion; `ReciprocalRankFusion::fuse()` takes weights.
- `vector.embedder.query_prefix` and `document_prefix` for models trained
  with prefixes (e5). The document prefix is part of a chunk's hash, so a new
  one embeds the chunks again.

## [0.1.0] — 2026-10-27

First public release.

### Added
- Full-text search on PostgreSQL: documents with a generated, weighted
  `tsvector` in their own text search configuration; websearch and prefix
  matching across russian, english and simple at once; exact key first;
  highlighted snippets; facets; counts by type.
- Access filtering in SQL that fails closed: `accessKeys()` or an explicit
  `everywhere()`.
- `IndexedForSearch` keeps a model's document in step, in a job after commit.
- `search:reindex`.
- The migration refuses a database whose `LC_CTYPE` cannot lowercase
  non-Latin text.
- Vector search with pgvector, off by default (`hybrid-search.vector.*`): a
  separate migration for `search_chunks` (`vector(N)`, HNSW with
  `vector_cosine_ops`, chunks deleted with their document) that is skipped
  when vector search is off and refuses to run when the `vector` type is not
  on the search_path.
- `MarkdownChunker`: headings start chunks, paragraphs packed to
  `chunk_chars` with an overlap, code blocks kept whole while they fit, the
  title prefixed to the embedded text.
- `Contracts\Embedder`, `Embedders\OpenAiCompatibleEmbedder` (any
  OpenAI-compatible `/embeddings`, e.g. Ollama) and `Embedders\FakeEmbedder`
  for tests; the host may bind its own.
- Indexing a document (re)builds its chunks in the same job; only chunks whose
  text or model changed are embedded again.
- `SearchQuery::hybrid()`: full-text and vector candidates under the same
  fail-closed filter, fused by Reciprocal Rank Fusion; an exact key still
  first; a vector-only hit gets its best chunk as the snippet; falls back to
  full-text when the embedder fails and reports it in `degraded()`.
- `hybrid-search:embed {--type=} {--force}`.
- The migrations can be published (`hybrid-search-migrations`).

[Unreleased]: https://github.com/dskripchenko/laravel-hybrid-search/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/dskripchenko/laravel-hybrid-search/releases/tag/v0.1.0

# Changelog

## [Unreleased] — 0.1.0

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

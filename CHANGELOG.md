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

# Security policy

## What is guaranteed

- **A query sees only what its access keys cover.** The filter is part of the
  SQL, applied before ranking and limiting. A query without `accessKeys()`
  throws; an empty key list finds nothing; only an explicit `everywhere()`
  lifts the filter.
- **The query text is data.** It reaches PostgreSQL only as a bound
  parameter of `websearch_to_tsquery()` / `to_tsquery()`; the prefix query is
  built from letters and digits alone.

## Reporting a vulnerability

Please report privately through GitHub's
[security advisories](https://github.com/dskripchenko/laravel-hybrid-search/security/advisories/new),
not in a public issue.

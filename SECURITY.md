# Security policy

This package decides which documents a search may return, so a flaw in it can
show people what they are not allowed to see. Please report vulnerabilities
privately.

## Reporting

Use GitHub's private vulnerability reporting:
**Security → Report a vulnerability** on
[github.com/dskripchenko/laravel-hybrid-search](https://github.com/dskripchenko/laravel-hybrid-search/security/advisories/new),
or write to denskrp90@gmail.com. Please include the version, the PostgreSQL
and pgvector versions and the steps to reproduce. Do not open a public issue
for a vulnerability.

You will get an answer within five working days. A confirmed issue is fixed in
a patch release of every supported line, and published as a GitHub security
advisory once the release is out.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | ✅ |

## What is guaranteed

- **A query sees only what its access keys cover.** The filter is part of the
  SQL, applied before ranking and limiting, on the full-text and the vector
  side alike. A query without `accessKeys()` throws; an empty key list finds
  nothing; only an explicit `everywhere()` lifts the filter.
- **The query text is data.** It reaches PostgreSQL only as a bound
  parameter of `websearch_to_tsquery()` / `to_tsquery()`; the prefix query is
  built from letters and digits alone.

## Scope

In scope: the access filter, query building, indexing and chunking, the
bundled embedders and migrations. Out of scope: PostgreSQL and pgvector
themselves, the embeddings service, and the access keys a host assigns to its
documents and people.

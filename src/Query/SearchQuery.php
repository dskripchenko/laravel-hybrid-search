<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Query;

use Dskripchenko\HybridSearch\Contracts\Embedder;
use Dskripchenko\HybridSearch\Hit;
use Dskripchenko\HybridSearch\Vector\ChunkIndexer;
use Dskripchenko\HybridSearch\Vector\VectorConfig;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * A full-text query. Access is part of the SQL: without accessKeys() — or an
 * explicit everywhere() — a query finds nothing, so forgetting the filter
 * fails closed.
 *
 * The text is matched as typed (websearch syntax: "quotes", -minus, or) and
 * as prefixes of its words, in the russian, english and simple
 * configurations at once, so a constant query keeps the GIN index in use
 * whatever the language of a document. An exact key (GAL-12) ranks first.
 *
 * hybrid() adds the vector side: the text is embedded once, the top
 * candidates of full-text and of cosine distance over chunks — both under the
 * same access, type and facet filter — are fused by Reciprocal Rank Fusion.
 * When the embedder fails, the query falls back to full-text and degraded()
 * says why.
 */
final class SearchQuery
{
    /** @var list<string>|null */
    private ?array $accessKeys = null;

    /** @var list<string> */
    private array $types = [];

    /** @var array<string, string|int|float|bool> */
    private array $facets = [];

    private bool $everywhere = false;

    private bool $hybrid = false;

    private ?string $degraded = null;

    private int $limit = 20;

    private int $offset = 0;

    private const CONFIGS = ['russian', 'english', 'simple'];

    public function __construct(private readonly string $text) {}

    /** @param list<string> $keys */
    public function accessKeys(array $keys): self
    {
        $this->accessKeys = $keys;

        return $this;
    }

    /** No access filter — for administrators and maintenance. */
    public function everywhere(): self
    {
        $this->everywhere = true;
        $this->accessKeys = [];

        return $this;
    }

    public function types(string ...$types): self
    {
        $this->types = array_values($types);

        return $this;
    }

    public function where(string $facet, string|int|float|bool $value): self
    {
        $this->facets[$facet] = $value;

        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = max(1, min(200, $limit));
        $this->offset = max(0, $offset);

        return $this;
    }

    /** Fuse full-text with vector search (needs `hybrid-search.vector.enabled`). */
    public function hybrid(bool $hybrid = true): self
    {
        $this->hybrid = $hybrid;

        return $this;
    }

    /**
     * Why the last hybrid get() ran on full-text alone (the embedder failed,
     * vector search is off); null — it did not.
     */
    public function degraded(): ?string
    {
        return $this->degraded;
    }

    /** @return list<Hit> */
    public function get(): array
    {
        $this->degraded = null;
        if ($this->accessKeys === null) {
            throw new LogicException('A search needs accessKeys() or an explicit everywhere().');
        }
        $text = trim($this->text);
        if ($text === '' || ($this->accessKeys === [] && ! $this->unrestricted())) {
            return [];
        }

        if ($this->hybrid) {
            return $this->hybridHits($text);
        }

        return array_map(fn (array $row): Hit => $this->hit($row), $this->fullText($text, $this->limit, $this->offset));
    }

    /**
     * How many matches per type, for facets in the interface.
     *
     * @return array<string, int>
     */
    public function countsByType(): array
    {
        if ($this->accessKeys === null) {
            throw new LogicException('A search needs accessKeys() or an explicit everywhere().');
        }
        $text = trim($this->text);
        if ($text === '' || ($this->accessKeys === [] && ! $this->unrestricted())) {
            return [];
        }

        [$cte, $bindings] = $this->queries($text);
        $types = $this->types;
        $this->types = [];
        [$where, $whereBindings] = $this->conditions();
        $this->types = $types;
        $table = $this->table();

        $out = [];
        foreach ($this->db()->select(
            "with {$cte} select d.type, count(*) as total from {$table} d, q
             where (d.tsv @@ q.web or d.tsv @@ q.pre or lower(d.key) = lower(?)) {$where}
             group by d.type",
            [...$bindings, $text, ...$whereBindings],
        ) as $row) {
            $r = (array) $row;
            if (is_string($r['type'] ?? null)) {
                $out[$r['type']] = is_numeric($r['total'] ?? null) ? (int) $r['total'] : 0;
            }
        }

        return $out;
    }

    /**
     * Full-text matches, best first.
     *
     * @return list<array<string, mixed>>
     */
    private function fullText(string $text, int $limit, int $offset): array
    {
        [$cte, $bindings] = $this->queries($text);
        [$where, $whereBindings] = $this->conditions();
        $table = $this->table();
        $snippet = 'MaxWords='.Config::integer('hybrid-search.snippet.max_words', 30)
            .',MinWords='.Config::integer('hybrid-search.snippet.min_words', 12)
            .',ShortWord=2,StartSel=**,StopSel=**,HighlightAll=false';

        $rows = $this->db()->select(
            "with {$cte}
             select d.id, d.type, d.key, d.title, d.url, d.facets,
                    ts_headline(d.language, d.body, q.pre, '{$snippet}') as snippet,
                    ts_rank_cd(d.tsv, q.web) * 2 + ts_rank_cd(d.tsv, q.pre)
                      + case when lower(d.key) = lower(?) then 10 else 0 end as score,
                    lower(d.key) = lower(?) as exact
             from {$table} d, q
             where (d.tsv @@ q.web or d.tsv @@ q.pre or lower(d.key) = lower(?)) {$where}
             order by score desc, d.indexed_at desc
             limit ? offset ?",
            [...$bindings, $text, $text, $text, ...$whereBindings, $limit, $offset],
        );

        return self::rows($rows);
    }

    /**
     * The nearest documents by their best chunk, best first — under the same
     * filter as full-text, applied inside the scan (pgvector's iterative
     * scan keeps walking the graph until enough rows pass it).
     *
     * @return list<array<string, mixed>>
     */
    private function nearest(string $vector, int $limit): array
    {
        [$where, $whereBindings] = $this->conditions();
        $table = $this->table();
        $chunks = VectorConfig::table();
        // Several chunks of one document may come first; take more of them.
        $chunkLimit = $limit * 4;
        $maxDistance = VectorConfig::maxDistance();
        $cutoff = $maxDistance === null ? '' : 'where best.distance <= ?';
        $db = $this->db();
        // Taken out of the transaction by reference: transaction() returns
        // mixed in the type stubs of Laravel 11 and 12.
        $found = [];

        $db->transaction(static function () use ($db, $vector, $where, $whereBindings, $table, $chunks, $chunkLimit, $limit, $maxDistance, $cutoff, &$found): void {
            $scan = VectorConfig::iterativeScan();
            if ($scan !== null) {
                $db->select("select set_config('hnsw.iterative_scan', ?, true)", [$scan]);
            }
            $db->select("select set_config('hnsw.ef_search', ?, true)", [(string) max(40, min(1000, $chunkLimit))]);

            $rows = $db->select(
                "with n as (
                     select c.document_id, c.content, c.embedding <=> ?::vector as distance
                     from {$chunks} c join {$table} d on d.id = c.document_id
                     where true {$where}
                     order by c.embedding <=> ?::vector
                     limit ?
                 ), best as (
                     select distinct on (document_id) document_id, content, distance
                     from n order by document_id, distance
                 )
                 select d.id, d.type, d.key, d.title, d.url, d.facets, best.content, best.distance
                 from best join {$table} d on d.id = best.document_id
                 {$cutoff}
                 order by best.distance, d.id
                 limit ?",
                [$vector, ...$whereBindings, $vector, $chunkLimit, ...($maxDistance === null ? [] : [$maxDistance]), $limit],
            );

            $found = self::rows($rows);
        });

        return $found;
    }

    /** @return list<Hit> */
    private function hybridHits(string $text): array
    {
        $vector = $this->embedQuery($text);
        if ($vector === null) {
            return array_map(fn (array $row): Hit => $this->hit($row), $this->fullText($text, $this->limit, $this->offset));
        }

        $candidates = max(VectorConfig::candidates(), $this->offset + $this->limit);
        $rows = [];
        $exact = [];
        $byText = [];
        foreach ($this->fullText($text, $candidates, 0) as $row) {
            $id = self::id($row);
            $rows[$id] = $row;
            $byText[] = $id;
            if (($row['exact'] ?? false) === true) {
                $exact[$id] = true;
            }
        }
        $byMeaning = [];
        foreach ($this->nearest($vector, $candidates) as $row) {
            $id = self::id($row);
            $byMeaning[] = $id;
            if (! isset($rows[$id])) {
                $row['snippet'] = $this->trim(is_string($row['content'] ?? null) ? $row['content'] : '');
                $rows[$id] = $row;
            }
        }

        $weights = VectorConfig::weights();
        $scores = ReciprocalRankFusion::fuse([$byText, $byMeaning], VectorConfig::rrfK(), [$weights['text'], $weights['meaning']]);
        foreach (array_keys($exact) as $id) {
            $scores[$id] += 10.0;
        }
        $order = array_flip(array_keys($scores));
        uksort($scores, static fn (int|string $a, int|string $b): int => [$scores[$b], $order[$a]] <=> [$scores[$a], $order[$b]]);

        $hits = [];
        foreach (array_slice($scores, $this->offset, $this->limit, true) as $id => $score) {
            $hits[] = $this->hit([...$rows[$id], 'score' => $score]);
        }

        return $hits;
    }

    /** The query as a pgvector literal; null — degraded to full-text. */
    private function embedQuery(string $text): ?string
    {
        if (! VectorConfig::enabled()) {
            $this->degraded = 'Vector search is off (hybrid-search.vector.enabled).';

            return null;
        }
        try {
            $vectors = app(Embedder::class)->embed([VectorConfig::queryPrefix().$text]);
            if (count($vectors) !== 1) {
                throw new LogicException('The embedder returned '.count($vectors).' vectors for one text.');
            }

            return ChunkIndexer::literal($vectors[0]);
        } catch (Throwable $e) {
            $this->degraded = 'The embedder failed: '.$e->getMessage();
            if (app()->bound(ExceptionHandler::class)) {
                app(ExceptionHandler::class)->report($e);
            }

            return null;
        }
    }

    /** @param array<string, mixed> $r */
    private function hit(array $r): Hit
    {
        $decoded = is_string($r['facets'] ?? null) ? json_decode($r['facets'], true) : [];
        $facets = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            if (is_string($name)) {
                $facets[$name] = $value;
            }
        }

        return new Hit(
            type: is_string($r['type'] ?? null) ? $r['type'] : '',
            key: is_string($r['key'] ?? null) ? $r['key'] : '',
            title: is_string($r['title'] ?? null) ? $r['title'] : '',
            snippet: is_string($r['snippet'] ?? null) ? $r['snippet'] : '',
            url: is_string($r['url'] ?? null) ? $r['url'] : null,
            score: is_numeric($r['score'] ?? null) ? (float) $r['score'] : 0.0,
            facets: $facets,
        );
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ((array) $row as $column => $value) {
                if (is_string($column)) {
                    $r[$column] = $value;
                }
            }
            $out[] = $r;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function id(array $row): int
    {
        return is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    /** The first words of a chunk, for a hit found by meaning alone. */
    private function trim(string $content): string
    {
        $words = preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $max = Config::integer('hybrid-search.snippet.max_words', 30);

        return implode(' ', array_slice($words, 0, $max)).(count($words) > $max ? ' …' : '');
    }

    /** @return array{0: string, 1: list<string>} */
    private function queries(string $text): array
    {
        // The prefix half reads the words the way websearch_to_tsquery does: a
        // "-" at the start of a word excludes it (a hyphen inside "GAL-12" does
        // not), "or" is an operator. Excluded words stay excluded here too —
        // OR-ed with the websearch half, a prefix query that ignored them would
        // bring back exactly what the minus left out.
        $positive = [];
        $negative = [];
        preg_match_all('/(?<![\p{L}\p{N}])(-?)([\p{L}\p{N}]+)/u', mb_strtolower($text), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ($match[2] === 'or') {
                continue;
            }
            if ($match[1] === '-') {
                $negative[] = $match[2];
            } else {
                $positive[] = $match[2];
            }
        }
        $positive = array_slice($positive, 0, 8);
        $prefix = $positive === [] ? '' : implode(' & ', [
            ...array_map(static fn (string $w): string => $w.':*', $positive),
            ...array_map(static fn (string $w): string => '!'.$w, array_slice($negative, 0, 8)),
        ]);

        $web = implode(' || ', array_map(static fn (string $c): string => "websearch_to_tsquery('{$c}', ?)", self::CONFIGS));
        $pre = $prefix === ''
            ? "''::tsquery"
            : implode(' || ', array_map(static fn (string $c): string => "to_tsquery('{$c}', ?)", self::CONFIGS));

        $bindings = array_fill(0, count(self::CONFIGS), $text);
        if ($prefix !== '') {
            $bindings = [...$bindings, ...array_fill(0, count(self::CONFIGS), $prefix)];
        }

        return ["q as (select ({$web}) as web, ({$pre}) as pre)", $bindings];
    }

    /** @return array{0: string, 1: list<string|int|float|bool>} */
    private function conditions(): array
    {
        $sql = '';
        $bindings = [];
        if (! $this->unrestricted()) {
            $sql .= ' and d.access_key = any(?::text[])';
            $bindings[] = self::pgArray($this->accessKeys ?? []);
        }
        if ($this->types !== []) {
            $sql .= ' and d.type = any(?::text[])';
            $bindings[] = self::pgArray($this->types);
        }
        if ($this->facets !== []) {
            $sql .= ' and d.facets @> ?::jsonb';
            $bindings[] = (string) json_encode($this->facets, JSON_UNESCAPED_UNICODE);
        }

        return [$sql, $bindings];
    }

    /** Only everywhere() lifts the filter: an empty key list finds nothing. */
    private function unrestricted(): bool
    {
        return $this->everywhere;
    }

    /** @param list<string> $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values)).'}';
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

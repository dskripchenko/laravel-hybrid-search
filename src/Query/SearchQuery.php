<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Query;

use Dskripchenko\HybridSearch\Hit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A full-text query. Access is part of the SQL: without accessKeys() — or an
 * explicit everywhere() — a query finds nothing, so forgetting the filter
 * fails closed.
 *
 * The text is matched as typed (websearch syntax: "quotes", -minus, or) and
 * as prefixes of its words, in the russian, english and simple
 * configurations at once, so a constant query keeps the GIN index in use
 * whatever the language of a document. An exact key (GAL-12) ranks first.
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

    /** @return list<Hit> */
    public function get(): array
    {
        if ($this->accessKeys === null) {
            throw new LogicException('A search needs accessKeys() or an explicit everywhere().');
        }
        $text = trim($this->text);
        if ($text === '' || ($this->accessKeys === [] && ! $this->unrestricted())) {
            return [];
        }

        [$cte, $bindings] = $this->queries($text);
        [$where, $whereBindings] = $this->conditions();
        $table = $this->table();
        $snippet = 'MaxWords='.Config::integer('hybrid-search.snippet.max_words', 30)
            .',MinWords='.Config::integer('hybrid-search.snippet.min_words', 12)
            .',ShortWord=2,StartSel=**,StopSel=**,HighlightAll=false';

        $rows = $this->db()->select(
            "with {$cte}
             select d.type, d.key, d.title, d.url, d.facets,
                    ts_headline(d.language, d.body, q.pre, '{$snippet}') as snippet,
                    ts_rank_cd(d.tsv, q.web) * 2 + ts_rank_cd(d.tsv, q.pre)
                      + case when lower(d.key) = lower(?) then 10 else 0 end as score
             from {$table} d, q
             where (d.tsv @@ q.web or d.tsv @@ q.pre or lower(d.key) = lower(?)) {$where}
             order by score desc, d.indexed_at desc
             limit ? offset ?",
            [...$bindings, $text, $text, ...$whereBindings, $this->limit, $this->offset],
        );

        $hits = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $decoded = is_string($r['facets'] ?? null) ? json_decode($r['facets'], true) : [];
            $facets = [];
            foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
                if (is_string($name)) {
                    $facets[$name] = $value;
                }
            }
            $hits[] = new Hit(
                type: is_string($r['type'] ?? null) ? $r['type'] : '',
                key: is_string($r['key'] ?? null) ? $r['key'] : '',
                title: is_string($r['title'] ?? null) ? $r['title'] : '',
                snippet: is_string($r['snippet'] ?? null) ? $r['snippet'] : '',
                url: is_string($r['url'] ?? null) ? $r['url'] : null,
                score: is_numeric($r['score'] ?? null) ? (float) $r['score'] : 0.0,
                facets: $facets,
            );
        }

        return $hits;
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

    /** @return array{0: string, 1: list<string>} */
    private function queries(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, 8);
        $prefix = implode(' & ', array_map(static fn (string $w): string => $w.':*', $words));

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

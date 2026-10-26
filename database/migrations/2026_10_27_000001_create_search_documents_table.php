<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * One row per searchable record. The tsvector is a generated column: title
 * weighted A, body B, both in the document's own text search configuration.
 * `access_key` is what queries filter by before ranking (ADR: access in SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Config::string('hybrid-search.table', 'search_documents');

        // Text search lowercases through the database's LC_CTYPE. A database
        // created with LC_CTYPE=C leaves non-Latin words in their capitals,
        // and "Доски" never matches "доска" — silently. Refuse instead.
        $row = DB::selectOne("select to_tsvector('simple', 'ДЁ') = to_tsvector('simple', 'дё') as ok, (select datctype from pg_database where datname = current_database()) as ctype");
        if (! is_object($row) || ! property_exists($row, 'ok') || $row->ok !== true) {
            $ctype = is_object($row) && property_exists($row, 'ctype') && is_string($row->ctype) ? $row->ctype : '?';
            throw new RuntimeException("Full-text search needs a UTF-8 LC_CTYPE; this database has '{$ctype}'. Create it with e.g. `CREATE DATABASE … TEMPLATE template0 LC_CTYPE 'en_US.UTF-8'` (LC_COLLATE may stay 'C').");
        }

        DB::statement(<<<SQL
            create table {$table} (
                id bigserial primary key,
                type varchar(64) not null,
                key varchar(128) not null,
                title text not null default '',
                body text not null default '',
                access_key varchar(128) not null,
                facets jsonb not null default '{}',
                url text,
                language regconfig not null default 'simple',
                tsv tsvector generated always as (
                    setweight(to_tsvector(language, coalesce(title, '')), 'A')
                    || setweight(to_tsvector(language, coalesce(body, '')), 'B')
                ) stored,
                indexed_at timestamp(0) not null default now(),
                unique (type, key)
            )
        SQL);
        DB::statement("create index {$table}_tsv on {$table} using gin (tsv)");
        DB::statement("create index {$table}_access on {$table} (access_key, type)");
        DB::statement("create index {$table}_facets on {$table} using gin (facets)");
    }

    public function down(): void
    {
        $table = Config::string('hybrid-search.table', 'search_documents');
        DB::statement("drop table if exists {$table}");
    }
};

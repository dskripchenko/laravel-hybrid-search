<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Vector\VectorConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The vector half: documents cut into chunks, one embedding per chunk, an
 * HNSW index for cosine distance. Created only with
 * `hybrid-search.vector.enabled`; the `vector` type is taken from the
 * search_path (pgvector may live in any schema, e.g. `extensions`), never
 * from a hard-coded one. Chunks go with their document (on delete cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! VectorConfig::enabled()) {
            return;
        }

        $documents = Config::string('hybrid-search.table', 'search_documents');
        $table = VectorConfig::table();
        $dimensions = VectorConfig::dimensions();
        if ($dimensions < 1 || $dimensions > 2000) {
            throw new RuntimeException("hybrid-search.vector.dimensions must be between 1 and 2000 (the HNSW limit of `vector`); it is {$dimensions}.");
        }

        $row = DB::selectOne("select to_regtype('vector') is not null as ok, current_setting('search_path') as path");
        if (! is_object($row) || ! property_exists($row, 'ok') || $row->ok !== true) {
            $path = is_object($row) && property_exists($row, 'path') && is_string($row->path) ? $row->path : '?';
            throw new RuntimeException("Vector search is on (hybrid-search.vector.enabled) but the pgvector type `vector` is not reachable through the search_path '{$path}'. Install the extension (e.g. `CREATE EXTENSION vector SCHEMA extensions`) and put its schema on the search_path (`ALTER DATABASE … SET search_path = public, extensions`), or turn vector search off (HYBRID_SEARCH_VECTOR=false).");
        }

        DB::statement(<<<SQL
            create table {$table} (
                id bigserial primary key,
                document_id bigint not null references {$documents} (id) on delete cascade,
                ordinal integer not null,
                content text not null,
                content_hash varchar(64) not null,
                embedding vector({$dimensions}) not null,
                model varchar(128) not null,
                created_at timestamp(0) not null default now()
            )
        SQL);
        DB::statement("create index {$table}_document on {$table} (document_id, ordinal)");
        DB::statement("create index {$table}_embedding on {$table} using hnsw (embedding vector_cosine_ops)");
    }

    public function down(): void
    {
        DB::statement('drop table if exists '.VectorConfig::table());
    }
};

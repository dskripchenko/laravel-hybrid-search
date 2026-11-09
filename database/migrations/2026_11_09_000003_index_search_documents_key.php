<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Queries match the exact key (`lower(key) = lower(?)`) OR-ed with the
 * full-text conditions. Without an index on lower(key) that OR cannot be a
 * bitmap of indexes, and PostgreSQL reads the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Config::string('hybrid-search.table', 'search_documents');
        DB::statement("create index if not exists {$table}_key_lower on {$table} (lower(key))");
    }

    public function down(): void
    {
        $table = Config::string('hybrid-search.table', 'search_documents');
        DB::statement("drop index if exists {$table}_key_lower");
    }
};

<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Tests;

use Dskripchenko\HybridSearch\HybridSearchServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Base;
use PDO;
use Throwable;

/**
 * PostgreSQL from HYBRID_SEARCH_TEST_DB_* (defaults: 127.0.0.1:5432,
 * hybrid_search_test, postgres). The suite skips when none is reachable.
 * The search_path includes `extensions`, where VectorTestCase keeps pgvector.
 */
abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [HybridSearchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => self::env('HOST', '127.0.0.1'),
            'port' => self::env('PORT', '5432'),
            'database' => self::env('DATABASE', 'hybrid_search_test'),
            'username' => self::env('USERNAME', 'postgres'),
            'password' => self::env('PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public, extensions',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('hybrid-search.queue', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', self::env('HOST', '127.0.0.1'), self::env('PORT', '5432'), self::env('DATABASE', 'hybrid_search_test')),
                self::env('USERNAME', 'postgres'), self::env('PASSWORD', ''), [PDO::ATTR_TIMEOUT => 2]);
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL is not available: '.$e->getMessage());
        }

        DB::statement('drop table if exists search_chunks, search_documents, notes, migrations cascade');
        $this->prepareDatabase();
        $this->artisan('migrate')->assertSuccessful();
        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->text('body')->default('');
            $t->string('scope');
            $t->string('lang')->nullable();
            $t->string('status')->default('open');
        });
    }

    /** Before the migrations run. */
    protected function prepareDatabase(): void {}

    protected static function env(string $key, string $default): string
    {
        $value = getenv('HYBRID_SEARCH_TEST_DB_'.$key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

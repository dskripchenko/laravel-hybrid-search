<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Tests;

use Dskripchenko\HybridSearch\Contracts\Embedder;
use Dskripchenko\HybridSearch\Embedders\FakeEmbedder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Vector search on, with the fake embedder. pgvector goes into the schema
 * `extensions` of the test database (as on the hosts); the suite skips when
 * it cannot be installed there.
 */
abstract class VectorTestCase extends TestCase
{
    /** Words the fake embedder treats as one meaning. */
    public const SYNONYMS = [
        'tenant' => 'customer', 'tenants' => 'customer', 'customers' => 'customer', 'clients' => 'customer',
        'isolated' => 'separate', 'isolation' => 'separate', 'apart' => 'separate',
        'schemas' => 'schema', 'namespaces' => 'schema',
    ];

    protected FakeEmbedder $embedder;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('hybrid-search.vector.enabled', true);
        $this->embedder = new FakeEmbedder(1024, self::SYNONYMS);
        $app->instance(Embedder::class, $this->embedder);
    }

    protected function prepareDatabase(): void
    {
        try {
            DB::statement('create schema if not exists extensions');
            DB::statement('create extension if not exists vector schema extensions');
        } catch (Throwable $e) {
            $this->markTestSkipped('pgvector is not available: '.$e->getMessage());
        }
    }
}

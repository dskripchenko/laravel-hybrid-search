<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Contracts\Embedder;
use Dskripchenko\HybridSearch\Embedders\OpenAiCompatibleEmbedder;
use Dskripchenko\HybridSearch\Facades\Search;
use Dskripchenko\HybridSearch\Tests\Fixtures\Note;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/*
 * A real model through Ollama's OpenAI-compatible API, from
 * HYBRID_SEARCH_TEST_EMBEDDING_URL (default http://127.0.0.1:11434/v1) with
 * bge-m3 (1024 dimensions). Skipped when the endpoint does not answer.
 */
beforeEach(function (): void {
    $url = getenv('HYBRID_SEARCH_TEST_EMBEDDING_URL') ?: 'http://127.0.0.1:11434/v1';
    try {
        $models = Http::timeout(2)->get($url.'/models')->json('data.*.id');
    } catch (Throwable) {
        $models = null;
    }
    if (! is_array($models) || array_filter($models, fn ($id) => is_string($id) && str_starts_with($id, 'bge-m3')) === []) {
        $this->markTestSkipped("No bge-m3 behind {$url}.");
    }
    app()->instance(Embedder::class, new OpenAiCompatibleEmbedder(app(Factory::class), $url, 'bge-m3', 1024, timeout: 120));
});

it('finds by meaning across languages with a real model', function (): void {
    Note::query()->create(['title' => 'Арендаторы', 'body' => 'Каждый арендатор хранится в отдельной схеме базы данных PostgreSQL.', 'scope' => 'p', 'lang' => 'russian']);
    Note::query()->create(['title' => 'Sprint planning', 'body' => 'Plan the sprint on a Kanban board, then hold a retrospective.', 'scope' => 'p', 'lang' => 'english']);
    Note::query()->create(['title' => 'Release notes', 'body' => 'Version 2 adds dark mode and faster exports.', 'scope' => 'p', 'lang' => 'english']);

    $query = Search::query('how is customer data kept apart')->accessKeys(['p'])->hybrid();
    $hits = $query->get();

    expect($query->degraded())->toBeNull()
        ->and(Search::query('how is customer data kept apart')->accessKeys(['p'])->get())->toBe([])
        ->and($hits[0]->key)->toBe('N-1');
});

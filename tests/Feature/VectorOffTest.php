<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Embedders\OpenAiCompatibleEmbedder;
use Dskripchenko\HybridSearch\Facades\Search;
use Dskripchenko\HybridSearch\Tests\Fixtures\Note;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

it('creates no chunks table and keeps hybrid on full-text while vector search is off', function (): void {
    Note::query()->create(['title' => 'Boards and sprints', 'body' => 'Kanban.', 'scope' => 'project:1', 'lang' => 'english']);
    $query = Search::query('sprint')->accessKeys(['project:1'])->hybrid();

    expect(Schema::hasTable('search_chunks'))->toBeFalse()
        ->and(array_map(fn ($h) => $h->key, $query->get()))->toBe(['N-1'])
        ->and($query->degraded())->toContain('off')
        ->and(fn () => Search::query('sprint')->hybrid()->get())->toThrow(LogicException::class);
});

it('calls an OpenAI-compatible endpoint in batches and keeps the order of the texts', function (): void {
    Http::fake(function (Request $request) {
        $input = $request->data()['input'];
        // Answer in reverse order: the client must sort by index.
        $data = [];
        foreach (array_reverse(array_keys($input), true) as $i) {
            $data[] = ['index' => $i, 'embedding' => [(float) strlen($input[$i]), 0.0, 1.0]];
        }

        return Http::response(['data' => $data]);
    });
    $embedder = new OpenAiCompatibleEmbedder(app(Factory::class), 'http://llm.test/v1/', 'bge-m3', 3, 'secret', batch: 2);

    expect($embedder->embed(['a', 'bb', 'ccc']))->toBe([[1.0, 0.0, 1.0], [2.0, 0.0, 1.0], [3.0, 0.0, 1.0]]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://llm.test/v1/embeddings'
        && $r->hasHeader('Authorization', 'Bearer secret')
        && $r->data()['model'] === 'bge-m3');
});

it('fails loudly on an error answer or a vector of the wrong size', function (): void {
    Http::fake(['bad.test/*' => Http::response('model not found', 404), 'short.test/*' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0]]]])]);

    expect(fn () => (new OpenAiCompatibleEmbedder(app(Factory::class), 'http://bad.test', 'm', 3))->embed(['x']))
        ->toThrow(RuntimeException::class, '404')
        ->and(fn () => (new OpenAiCompatibleEmbedder(app(Factory::class), 'http://short.test', 'm', 3))->embed(['x']))
        ->toThrow(RuntimeException::class, '3 expected');
});

it('builds the default embedder from config', function (): void {
    config(['hybrid-search.vector.embedder.model' => 'nomic-embed-text', 'hybrid-search.vector.dimensions' => '768']);
    $embedder = OpenAiCompatibleEmbedder::fromConfig(app(Factory::class));

    expect($embedder->model())->toBe('nomic-embed-text')
        ->and($embedder->dimensions())->toBe(768);
});

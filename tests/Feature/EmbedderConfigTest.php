<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Contracts\Embedder;
use Dskripchenko\HybridSearch\Embedders\FakeEmbedder;
use Dskripchenko\HybridSearch\Embedders\OpenAiCompatibleEmbedder;
use Dskripchenko\HybridSearch\Tests\Fixtures\Note;

it('uses the OpenAI-compatible embedder unless the config names another', function (): void {
    expect(app(Embedder::class))->toBeInstanceOf(OpenAiCompatibleEmbedder::class);
});

it('takes the embedder class named in the config from the container', function (): void {
    config(['hybrid-search.vector.embedder.class' => FakeEmbedder::class]);

    expect(app(Embedder::class))->toBeInstanceOf(FakeEmbedder::class);
});

it('refuses a class that is not an embedder', function (): void {
    config(['hybrid-search.vector.embedder.class' => Note::class]);

    expect(fn () => app(Embedder::class))->toThrow(InvalidArgumentException::class, 'does not implement');
});

<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Embedders;

use Dskripchenko\HybridSearch\Contracts\Embedder;
use Throwable;

/**
 * A deterministic embedder for tests: a hashed bag of words, normalized, so
 * texts sharing words are near. `$synonyms` maps words to a common concept
 * (`['tenant' => 'customer']`) — the stand-in for what a real model knows,
 * so tests can find a document by meaning rather than by its words.
 */
final class FakeEmbedder implements Embedder
{
    /** @var list<string> Every text embedded so far, in order. */
    public array $embedded = [];

    private ?Throwable $failure = null;

    /** @param array<string, string> $synonyms */
    public function __construct(
        private readonly int $dimensions = 1024,
        private readonly array $synonyms = [],
        private readonly string $model = 'fake',
    ) {}

    /** Make the next calls throw (null — work again). */
    public function failWith(?Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function embed(array $texts): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $vectors = [];
        foreach ($texts as $text) {
            $this->embedded[] = $text;
            $vectors[] = $this->vector($text);
        }

        return $vectors;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /** @return list<float> */
    private function vector(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = $this->synonyms[$word] ?? $word;
            $hash = crc32($word);
            $vector[($hash & 0x7FFFFFFF) % $this->dimensions] += ($hash >> 31) === 1 ? -1.0 : 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));
        if ($norm === 0.0) {
            // A zero vector has no cosine distance; point empty texts somewhere.
            $vector[0] = 1.0;

            return array_values($vector);
        }

        return array_values(array_map(static fn (float $v): float => $v / $norm, $vector));
    }
}

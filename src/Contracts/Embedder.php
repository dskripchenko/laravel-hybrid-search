<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Contracts;

/**
 * Turns texts into vectors. The package ships an OpenAI-compatible client;
 * bind your own implementation in the container to use anything else.
 */
interface Embedder
{
    /**
     * One vector per text, in the order of the texts, each of dimensions()
     * floats. Throws when the texts cannot be embedded.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;

    /** The model name, stored with every chunk: a new model re-embeds. */
    public function model(): string;

    public function dimensions(): int;
}

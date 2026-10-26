<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Embedders;

use Dskripchenko\HybridSearch\Contracts\Embedder;
use Dskripchenko\HybridSearch\Vector\VectorConfig;
use Illuminate\Http\Client\Factory;
use RuntimeException;

/**
 * Any OpenAI-compatible embeddings endpoint: POST {url}/embeddings with
 * {model, input: [...]}, answered with {data: [{index, embedding}]}. OpenAI,
 * Ollama (http://127.0.0.1:11434/v1), vLLM, LocalAI and others speak it.
 */
final class OpenAiCompatibleEmbedder implements Embedder
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $url,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly ?string $key = null,
        private readonly int $batch = 32,
        private readonly int $timeout = 60,
    ) {}

    /** Built from `hybrid-search.vector.*`. */
    public static function fromConfig(Factory $http): self
    {
        $url = config('hybrid-search.vector.embedder.url');
        $model = config('hybrid-search.vector.embedder.model');
        $key = config('hybrid-search.vector.embedder.key');
        $batch = config('hybrid-search.vector.embedder.batch');
        $timeout = config('hybrid-search.vector.embedder.timeout');

        return new self(
            http: $http,
            url: is_string($url) ? $url : 'http://127.0.0.1:11434/v1',
            model: is_string($model) ? $model : 'bge-m3',
            dimensions: VectorConfig::dimensions(),
            key: is_string($key) && $key !== '' ? $key : null,
            batch: is_numeric($batch) ? (int) $batch : 32,
            timeout: is_numeric($timeout) ? (int) $timeout : 60,
        );
    }

    public function embed(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk($texts, max(1, $this->batch)) as $batch) {
            array_push($vectors, ...$this->request($batch));
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

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function request(array $texts): array
    {
        $request = $this->http->acceptJson()->asJson()->timeout($this->timeout);
        if ($this->key !== null) {
            $request = $request->withToken($this->key);
        }
        $response = $request->post(rtrim($this->url, '/').'/embeddings', ['model' => $this->model, 'input' => $texts]);
        if (! $response->successful()) {
            throw new RuntimeException("The embeddings endpoint answered {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        $data = $response->json('data');
        if (! is_array($data) || count($data) !== count($texts)) {
            throw new RuntimeException('The embeddings endpoint returned '.(is_array($data) ? count($data) : 'no').' vectors for '.count($texts).' texts.');
        }

        $vectors = [];
        foreach ($data as $position => $item) {
            $index = is_array($item) && is_int($item['index'] ?? null) ? $item['index'] : $position;
            $embedding = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (! is_array($embedding) || count($embedding) !== $this->dimensions) {
                throw new RuntimeException('The embeddings endpoint returned a vector of '.(is_array($embedding) ? count($embedding) : 0)." dimensions; {$this->dimensions} expected (hybrid-search.vector.dimensions).");
            }
            $vectors[$index] = array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, array_values($embedding));
        }
        ksort($vectors);

        return array_values($vectors);
    }
}

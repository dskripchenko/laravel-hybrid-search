<?php

declare(strict_types=1);

return [

    // The connection of the index; null — the default one. Point it at a
    // separate PostgreSQL when the index outgrows the application's.
    'connection' => env('HYBRID_SEARCH_CONNECTION'),

    'table' => 'search_documents',

    // The text search configuration of documents that name none: 'russian',
    // 'english', 'simple' (no morphology) or your own.
    'language' => env('HYBRID_SEARCH_LANGUAGE', 'simple'),

    // Index in a queued job after the model's transaction commits; false
    // indexes inline (tests, small apps).
    'queue' => env('HYBRID_SEARCH_QUEUE', true),
    'queue_connection' => env('HYBRID_SEARCH_QUEUE_CONNECTION'),
    'queue_name' => env('HYBRID_SEARCH_QUEUE_NAME'),

    // Models `search:reindex` walks through.
    'models' => [],

    // The highlighted fragment of a hit.
    'snippet' => [
        'max_words' => 30,
        'min_words' => 12,
    ],

    // The vector half: chunks of documents with embeddings in pgvector, and
    // SearchQuery::hybrid() fusing them with full-text by Reciprocal Rank
    // Fusion. Needs the `vector` extension reachable through the search_path.
    'vector' => [
        // Off by default: the chunks migration and embedding run only when on.
        'enabled' => env('HYBRID_SEARCH_VECTOR', false),

        'table' => 'search_chunks',

        // The size of the embedding column; must match the embedder (bge-m3
        // and text-embedding-3-small with dimensions=1024: 1024). HNSW indexes
        // up to 2000.
        'dimensions' => env('HYBRID_SEARCH_VECTOR_DIMENSIONS', 1024),

        // Chunking of Markdown bodies: target size and the overlap carried
        // into the next chunk of the same section, in characters.
        'chunk_chars' => 1200,
        'chunk_overlap' => 150,

        // Candidates taken from each side before fusion, and the k of RRF.
        'candidates' => 50,
        'rrf_k' => 60,

        // How much each ranking counts in the fusion. Raise `text` when exact
        // words (names, codes, keys) should win over related meaning.
        'weights' => [
            'text' => env('HYBRID_SEARCH_WEIGHT_TEXT', 1.0),
            'meaning' => env('HYBRID_SEARCH_WEIGHT_MEANING', 1.0),
        ],

        // Vector candidates farther than this cosine distance (0 — same
        // direction, 2 — opposite) are dropped; null keeps the nearest ones
        // however far. Nearest neighbours always exist, so set it (tuned to
        // your model) when unrelated documents should not show up.
        'max_distance' => null,

        // pgvector 0.8+: keep scanning the HNSW graph until enough rows pass
        // the access filter ('relaxed_order', 'strict_order'); null — off
        // (older pgvector).
        'iterative_scan' => 'relaxed_order',

        // The built-in embedder: any OpenAI-compatible /embeddings endpoint
        // (OpenAI, Ollama's /v1, vLLM, LocalAI…). Bind your own
        // Contracts\Embedder in the container to replace it.
        'embedder' => [
            'url' => env('HYBRID_SEARCH_EMBEDDING_URL', 'http://127.0.0.1:11434/v1'),
            'model' => env('HYBRID_SEARCH_EMBEDDING_MODEL', 'bge-m3'),
            'key' => env('HYBRID_SEARCH_EMBEDDING_KEY'),
            'batch' => 32,
            'timeout' => 60,
            // Some models are trained with prefixes: e5 wants `query: ` and
            // `passage: `. bge-m3 and OpenAI models want none. A new
            // document prefix embeds every chunk again on the next sync.
            'query_prefix' => env('HYBRID_SEARCH_QUERY_PREFIX', ''),
            'document_prefix' => env('HYBRID_SEARCH_DOCUMENT_PREFIX', ''),
        ],
    ],
];

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

    // Weights of the parts of a document in the rank.
    'snippet' => [
        'max_words' => 30,
        'min_words' => 12,
    ],
];

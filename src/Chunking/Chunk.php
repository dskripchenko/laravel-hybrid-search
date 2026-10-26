<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Chunking;

/**
 * A piece of a document. `content` is the piece itself (stored, shown as a
 * snippet); `text` is what gets embedded — the document's title, then the
 * content, so a chunk deep inside a page still knows what the page is about.
 */
final readonly class Chunk
{
    public function __construct(
        public int $ordinal,
        public string $content,
        public string $text,
    ) {}
}

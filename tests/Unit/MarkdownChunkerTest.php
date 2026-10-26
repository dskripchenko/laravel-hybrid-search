<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Chunking\Chunk;
use Dskripchenko\HybridSearch\Chunking\MarkdownChunker;

/** @return list<string> */
function contents(array $chunks): array
{
    return array_map(fn (Chunk $c) => $c->content, $chunks);
}

function sentence(int $n): string
{
    return "Sentence number {$n} talks about something worth reading here.";
}

it('starts a chunk at every heading and keeps empty headings together', function (): void {
    $body = "Intro line.\n\n# Install\n## Requirements\nPHP 8.2.\n\n## Usage\nCall it.";
    $chunks = (new MarkdownChunker)->chunk('Guide', $body);

    expect(contents($chunks))->toBe(['Intro line.', "# Install\n## Requirements\n\nPHP 8.2.", "## Usage\n\nCall it."])
        ->and(array_map(fn (Chunk $c) => $c->ordinal, $chunks))->toBe([0, 1, 2]);
});

it('prefixes the title to the embedded text, not to the content', function (): void {
    $chunks = (new MarkdownChunker)->chunk('Guide', "# Usage\nCall it.");

    expect($chunks[0]->content)->toBe("# Usage\n\nCall it.")
        ->and($chunks[0]->text)->toBe("Guide\n\n# Usage\n\nCall it.");
});

it('gives a body-less document one chunk of its title, and nothing for nothing', function (): void {
    $chunks = (new MarkdownChunker)->chunk('Only a title', '  ');

    expect(contents($chunks))->toBe([''])
        ->and($chunks[0]->text)->toBe('Only a title')
        ->and((new MarkdownChunker)->chunk('', ''))->toBe([]);
});

it('packs paragraphs up to the size and overlaps within a section', function (): void {
    $paragraphs = array_map(fn (int $n) => sentence($n).' '.sentence($n + 100), range(1, 12));
    $chunks = (new MarkdownChunker(400, 60))->chunk('', "## Section\n\n".implode("\n\n", $paragraphs));

    expect(count($chunks))->toBeGreaterThan(3);
    foreach ($chunks as $i => $chunk) {
        expect($chunk->content)->toStartWith('## Section')
            ->and(mb_strlen($chunk->content))->toBeLessThanOrEqual(400);
        if ($i > 0) {
            // The tail of the previous chunk's last paragraph comes first.
            $carried = explode("\n\n", $chunk->content)[1];
            expect($carried)->toStartWith('…')
                ->and($chunks[$i - 1]->content)->toEndWith(mb_substr($carried, 1));
        }
    }
    // Nothing is lost.
    $all = implode("\n", contents($chunks));
    foreach ($paragraphs as $paragraph) {
        expect($all)->toContain($paragraph);
    }
});

it('cuts a long paragraph between sentences', function (): void {
    $paragraph = implode(' ', array_map('sentence', range(1, 30)));
    $chunks = (new MarkdownChunker(300, 0))->chunk('', $paragraph);

    expect(count($chunks))->toBeGreaterThan(5);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(300)
            ->and($chunk->content)->toStartWith('Sentence')->toEndWith('.');
    }
});

it('keeps a code block whole while it fits, even with blank lines and hashes inside', function (): void {
    $code = "```bash\n# not a heading\n\necho one\necho two\n```";
    $body = str_repeat('Words before the code. ', 12)."\n\n".$code."\n\nAfter.";
    $chunks = (new MarkdownChunker(300, 0))->chunk('', $body);

    $withCode = array_values(array_filter(contents($chunks), fn (string $c) => str_contains($c, '```bash')));
    expect($withCode)->toHaveCount(1)
        ->and($withCode[0])->toContain($code);
});

it('cuts an oversized code block between lines and re-fences every piece', function (): void {
    $lines = array_map(fn (int $n) => "line_{$n}();", range(1, 200));
    $code = "~~~php\n".implode("\n", $lines)."\n~~~";
    $chunks = (new MarkdownChunker(300, 50))->chunk('', $code);

    expect(count($chunks))->toBeGreaterThan(5);
    $seen = [];
    foreach ($chunks as $chunk) {
        expect($chunk->content)->toStartWith("~~~php\n")->toEndWith("\n~~~")
            ->and($chunk->content)->not->toContain('…');
        foreach (array_slice(explode("\n", $chunk->content), 1, -1) as $line) {
            $seen[] = $line;
        }
    }
    expect($seen)->toBe($lines);
});

it('treats an unclosed fence as code to the end', function (): void {
    $chunks = (new MarkdownChunker)->chunk('', "Text.\n\n```\n# still code\n\nmore");

    expect(contents($chunks))->toBe(["Text.\n\n```\n# still code\n\nmore"]);
});

it('cuts by words when a sentence has no end, and handles multibyte text', function (): void {
    $text = trim(str_repeat('арендатор схема изоляция ', 60));
    $chunks = (new MarkdownChunker(200, 0))->chunk('', $text);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(200)
            ->and(mb_check_encoding($chunk->content, 'UTF-8'))->toBeTrue();
    }
    expect(implode(' ', contents($chunks)))->toBe($text);
});

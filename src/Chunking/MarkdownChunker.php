<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Chunking;

/**
 * Cuts a Markdown (or plain text) body into chunks for embedding.
 *
 * - An ATX heading (`#` … `######`) starts a new chunk; headings with nothing
 *   between them stay together. Every chunk of a section starts with its
 *   heading.
 * - Paragraphs are packed up to `maxChars`; a chunk that continues a section
 *   begins with the tail (`overlap` characters, cut at a word) of the prose
 *   that ended the previous one.
 * - A fenced code block is never split while it fits in 1.5 × `maxChars`; a
 *   longer one is cut between lines, each piece re-fenced.
 * - A paragraph longer than a chunk is cut between sentences, then words.
 *
 * Sizes are in characters, not tokens: a chunk may run over by a heading or
 * by a code block that was kept whole.
 */
final class MarkdownChunker
{
    private readonly int $max;

    private readonly int $overlap;

    public function __construct(int $maxChars = 1200, int $overlap = 150)
    {
        $this->max = max(100, $maxChars);
        $this->overlap = max(0, min($overlap, intdiv($this->max, 4)));
    }

    /** @return list<Chunk> */
    public function chunk(string $title, string $body): array
    {
        $title = trim($title);
        $contents = [];
        foreach ($this->sections($this->blocks($body)) as [$heading, $blocks]) {
            array_push($contents, ...$this->pack($heading, $blocks));
        }
        if ($contents === [] && $title !== '') {
            $contents = [''];
        }

        $chunks = [];
        foreach ($contents as $ordinal => $content) {
            $text = match (true) {
                $title === '' => $content,
                $content === '' => $title,
                default => $title."\n\n".$content,
            };
            $chunks[] = new Chunk($ordinal, $content, $text);
        }

        return $chunks;
    }

    /**
     * The body as headings, fenced code blocks and paragraphs.
     *
     * @return list<array{kind: string, text: string}>
     */
    private function blocks(string $body): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        $count = count($lines);
        $blocks = [];
        $paragraph = [];

        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            $fence = preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1 ? $m[1] : null;
            $heading = $fence === null && preg_match('/^ {0,3}#{1,6}(\s|$)/', $line) === 1;
            $blank = trim($line) === '';

            if (($fence !== null || $heading || $blank) && $paragraph !== []) {
                $blocks[] = ['kind' => 'text', 'text' => implode("\n", $paragraph)];
                $paragraph = [];
            }

            if ($fence !== null) {
                // Up to the closing fence of the same character and at least
                // the same length; an unclosed fence runs to the end.
                $close = '/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}\s*$/';
                $code = [$line];
                while (++$i < $count) {
                    $code[] = rtrim($lines[$i]);
                    if (preg_match($close, $lines[$i]) === 1) {
                        break;
                    }
                }
                $blocks[] = ['kind' => 'code', 'text' => implode("\n", $code)];
            } elseif ($heading) {
                $blocks[] = ['kind' => 'heading', 'text' => trim($line)];
            } elseif (! $blank) {
                $paragraph[] = $line;
            }
        }
        if ($paragraph !== []) {
            $blocks[] = ['kind' => 'text', 'text' => implode("\n", $paragraph)];
        }

        return $blocks;
    }

    /**
     * Blocks grouped under their heading ('' before the first one).
     *
     * @param  list<array{kind: string, text: string}>  $blocks
     * @return list<array{0: string, 1: list<array{kind: string, text: string}>}>
     */
    private function sections(array $blocks): array
    {
        $sections = [];
        $heading = '';
        $body = [];
        foreach ($blocks as $block) {
            if ($block['kind'] !== 'heading') {
                $body[] = $block;

                continue;
            }
            if ($body === []) {
                $heading = $heading === '' ? $block['text'] : $heading."\n".$block['text'];

                continue;
            }
            $sections[] = [$heading, $body];
            $heading = $block['text'];
            $body = [];
        }
        if ($heading !== '' || $body !== []) {
            $sections[] = [$heading, $body];
        }

        return $sections;
    }

    /**
     * @param  list<array{kind: string, text: string}>  $blocks
     * @return list<string>
     */
    private function pack(string $heading, array $blocks): array
    {
        $start = $heading === '' ? [] : [$heading];
        $budget = max(intdiv($this->max, 2), $this->max - self::length($heading) - $this->overlap - 4);

        $chunks = [];
        $parts = $start;
        $hasBody = false;
        $lastProse = null;
        foreach ($blocks as $block) {
            foreach ($this->pieces($block, $budget) as [$piece, $prose]) {
                if ($hasBody && self::length(implode("\n\n", [...$parts, $piece])) > $this->max) {
                    $chunks[] = implode("\n\n", $parts);
                    $parts = $start;
                    $tail = $lastProse === null ? '' : $this->tail($lastProse);
                    if ($tail !== '') {
                        $parts[] = $tail;
                    }
                }
                $parts[] = $piece;
                $hasBody = true;
                $lastProse = $prose ? $piece : null;
            }
        }
        if ($parts !== []) {
            $chunks[] = implode("\n\n", $parts);
        }

        return $chunks;
    }

    /**
     * A block cut to fit `$budget`, each piece flagged as prose (true) or code.
     *
     * @param  array{kind: string, text: string}  $block
     * @return list<array{0: string, 1: bool}>
     */
    private function pieces(array $block, int $budget): array
    {
        $text = $block['text'];
        if ($block['kind'] === 'code') {
            if (self::length($text) <= intdiv($this->max * 3, 2)) {
                return [[$text, false]];
            }

            return array_map(static fn (string $piece): array => [$piece, false], $this->splitCode($text, $budget));
        }
        if (self::length($text) <= $budget) {
            return [[$text, true]];
        }

        return array_map(static fn (string $piece): array => [$piece, true], $this->splitProse($text, $budget));
    }

    /** @return list<string> */
    private function splitProse(string $text, int $budget): array
    {
        // Sentences, each remembering what separated it from the next one.
        $units = [];
        foreach (explode("\n", $text) as $line) {
            $sentences = preg_split('/(?<=[.!?…])\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $last = count($sentences) - 1;
            foreach ($sentences as $n => $sentence) {
                foreach (self::length($sentence) > $budget ? $this->splitWords($sentence, $budget) : [$sentence] as $unit) {
                    $units[] = [$unit, $n === $last ? "\n" : ' '];
                }
            }
        }

        $pieces = [];
        $current = '';
        $separator = '';
        foreach ($units as [$unit, $after]) {
            if ($current !== '' && self::length($current.$separator.$unit) > $budget) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $unit : $current.$separator.$unit;
            $separator = $after;
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /** @return list<string> */
    private function splitWords(string $sentence, int $budget): array
    {
        $budget = max(1, $budget);
        $pieces = [];
        $current = '';
        foreach (preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach (self::length($word) > $budget ? mb_str_split($word, $budget) : [$word] as $part) {
                if ($current !== '' && self::length($current.' '.$part) > $budget) {
                    $pieces[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $part : $current.' '.$part;
            }
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * A code block too long to keep, cut between lines; every piece gets the
     * opening and the closing fence back so it stays a code block.
     *
     * @return list<string>
     */
    private function splitCode(string $code, int $budget): array
    {
        $lines = explode("\n", $code);
        $open = array_shift($lines);
        preg_match('/^ {0,3}(`{3,}|~{3,})/', $open, $m);
        $fence = $m[1] ?? '```';
        $last = end($lines);
        $close = is_string($last) && preg_match('/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}\s*$/', $last) === 1
            ? (string) array_pop($lines)
            : $fence;

        $room = max(1, $budget - self::length($open) - self::length($close) - 2);
        $pieces = [];
        $current = [];
        $size = 0;
        foreach ($lines as $line) {
            foreach (self::length($line) > $room ? mb_str_split($line, $room) : [$line] as $part) {
                $length = self::length($part) + 1;
                if ($current !== [] && $size + $length > $room) {
                    $pieces[] = $open."\n".implode("\n", $current)."\n".$close;
                    $current = [];
                    $size = 0;
                }
                $current[] = $part;
                $size += $length;
            }
        }
        if ($current !== []) {
            $pieces[] = $open."\n".implode("\n", $current)."\n".$close;
        }

        return $pieces;
    }

    /** The end of a paragraph carried into the next chunk, cut at a word. */
    private function tail(string $prose): string
    {
        if ($this->overlap === 0) {
            return '';
        }
        $prose = trim(preg_replace('/\s+/u', ' ', $prose) ?? $prose);
        if (self::length($prose) <= $this->overlap) {
            return $prose;
        }
        $cut = mb_substr($prose, -$this->overlap);
        $space = mb_strpos($cut, ' ');

        return '…'.($space === false ? $cut : mb_substr($cut, $space + 1));
    }

    private static function length(string $text): int
    {
        return mb_strlen($text);
    }
}

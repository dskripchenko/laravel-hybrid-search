<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Vector;

use Dskripchenko\HybridSearch\Chunking\Chunk;
use Dskripchenko\HybridSearch\Chunking\MarkdownChunker;
use Dskripchenko\HybridSearch\Contracts\Embedder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keeps the chunks of a stored document in step with its text. A chunk is
 * identified by the hash of the model and the text it embeds: unchanged
 * chunks keep their embeddings, only new ones go to the embedder, and a
 * document whose chunks are all unchanged is not touched at all.
 */
final class ChunkIndexer
{
    public function __construct(private readonly Embedder $embedder) {}

    /**
     * @param  bool  $force  embed every chunk again, even unchanged ones
     * @return int the number of chunks sent to the embedder
     */
    public function sync(int $documentId, string $title, string $body, bool $force = false): int
    {
        $chunks = (new MarkdownChunker(VectorConfig::chunkChars(), VectorConfig::chunkOverlap()))->chunk($title, $body);
        $model = $this->embedder->model();
        $hashes = array_map(static fn (Chunk $chunk): string => hash('sha256', $model."\0".$chunk->text), $chunks);

        $db = $this->db();
        $table = VectorConfig::table();
        $stored = [];
        $reusable = [];
        foreach ($db->table($table)->where('document_id', $documentId)->orderBy('ordinal')->get(['id', 'content_hash']) as $row) {
            $r = (array) $row;
            $id = is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0;
            $hash = is_string($r['content_hash'] ?? null) ? $r['content_hash'] : '';
            $stored[] = ['id' => $id, 'hash' => $hash];
            $reusable[$hash] ??= $id;
        }
        if (! $force && array_column($stored, 'hash') === $hashes) {
            return 0;
        }
        if ($force) {
            $reusable = [];
        }

        $missing = [];
        foreach ($chunks as $n => $chunk) {
            if (! isset($reusable[$hashes[$n]])) {
                $missing[$hashes[$n]] = $chunk->text;
            }
        }
        $vectors = $missing === [] ? [] : array_combine(array_keys($missing), $this->embed(array_values($missing)));

        $documents = Config::string('hybrid-search.table', 'search_documents');
        $db->transaction(static function () use ($db, $table, $documents, $documentId, $chunks, $hashes, $vectors, $reusable, $model): void {
            // One writer per document at a time; a document deleted meanwhile
            // has nothing left to chunk.
            if ($db->selectOne("select id from {$documents} where id = ? for update", [$documentId]) === null) {
                return;
            }

            $written = [];
            foreach ($chunks as $n => $chunk) {
                $hash = $hashes[$n];
                $rows = isset($vectors[$hash])
                    ? $db->select(
                        "insert into {$table} (document_id, ordinal, content, content_hash, embedding, model)
                         values (?, ?, ?, ?, ?::vector, ?) returning id",
                        [$documentId, $chunk->ordinal, $chunk->content, $hash, $vectors[$hash], $model],
                    )
                    // Copied inside the database: the embedding never travels.
                    : $db->select(
                        "insert into {$table} (document_id, ordinal, content, content_hash, embedding, model)
                         select ?, ?, ?, content_hash, embedding, model from {$table} where id = ? returning id",
                        [$documentId, $chunk->ordinal, $chunk->content, $reusable[$hash]],
                    );
                $row = $rows[0] ?? null;
                if (! is_object($row) || ! property_exists($row, 'id') || ! is_numeric($row->id)) {
                    throw new RuntimeException("The chunks of search document {$documentId} changed while it was being embedded; run it again.");
                }
                $written[] = (int) $row->id;
            }

            $db->delete(
                "delete from {$table} where document_id = ? and not (id = any(?::bigint[]))",
                [$documentId, '{'.implode(',', $written).'}'],
            );
        });

        return count($missing);
    }

    /**
     * Texts as pgvector literals, checked against the column's size.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function embed(array $texts): array
    {
        $vectors = $this->embedder->embed($texts);
        if (count($vectors) !== count($texts)) {
            throw new RuntimeException('The embedder returned '.count($vectors).' vectors for '.count($texts).' texts.');
        }

        return array_map(static fn (array $vector): string => self::literal($vector), $vectors);
    }

    /**
     * '[0.1,0.2,…]', the text form of a pgvector value, after checking the
     * vector fits the column.
     *
     * @param  list<float>  $vector
     */
    public static function literal(array $vector): string
    {
        $dimensions = VectorConfig::dimensions();
        if (count($vector) !== $dimensions) {
            throw new RuntimeException('The embedder returned a vector of '.count($vector)." dimensions; the index holds {$dimensions} (hybrid-search.vector.dimensions).");
        }
        foreach ($vector as $value) {
            if (! is_finite($value)) {
                throw new RuntimeException('The embedder returned a vector with NaN or infinite values.');
            }
        }

        return '['.implode(',', array_map(static fn (float $v): string => (string) $v, $vector)).']';
    }

    private function db(): ConnectionInterface
    {
        $name = config('hybrid-search.connection');

        return DB::connection(is_string($name) ? $name : null);
    }
}

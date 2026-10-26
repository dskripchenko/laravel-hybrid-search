<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Console;

use Dskripchenko\HybridSearch\Vector\ChunkIndexer;
use Dskripchenko\HybridSearch\Vector\VectorConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * (Re)build the chunks and embeddings of documents already in the index —
 * after turning vector search on, changing the chunk size or the model.
 * Unchanged chunks are skipped unless --force.
 */
final class EmbedCommand extends Command
{
    protected $signature = 'hybrid-search:embed {--type= : Only documents of this type} {--force : Embed unchanged chunks too} {--chunk=100}';

    protected $description = 'Build the vector chunks of indexed documents';

    public function handle(ChunkIndexer $chunks): int
    {
        if (! VectorConfig::enabled()) {
            $this->error('Vector search is off (hybrid-search.vector.enabled).');

            return self::FAILURE;
        }

        $name = config('hybrid-search.connection');
        $query = DB::connection(is_string($name) ? $name : null)
            ->table(Config::string('hybrid-search.table', 'search_documents'))
            ->select(['id', 'title', 'body']);
        $type = $this->option('type');
        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }
        $size = $this->option('chunk');
        $force = (bool) $this->option('force');

        $documents = 0;
        $embedded = 0;
        $query->chunkById(max(1, is_numeric($size) ? (int) $size : 100), function ($rows) use ($chunks, $force, &$documents, &$embedded): void {
            foreach ($rows as $row) {
                $r = (array) $row;
                $embedded += $chunks->sync(
                    is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0,
                    is_string($r['title'] ?? null) ? $r['title'] : '',
                    is_string($r['body'] ?? null) ? $r['body'] : '',
                    $force,
                );
                $documents++;
            }
        });
        $this->line("Documents: {$documents}, chunks embedded: {$embedded}");

        return self::SUCCESS;
    }
}

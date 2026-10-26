<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Console;

use Dskripchenko\HybridSearch\Contracts\Searchable;
use Dskripchenko\HybridSearch\Indexer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/** Rebuild the documents of the configured models, in chunks. */
final class ReindexCommand extends Command
{
    protected $signature = 'search:reindex {model? : One model class; all configured ones when omitted} {--chunk=500}';

    protected $description = 'Rebuild the search index of searchable models';

    public function handle(Indexer $indexer): int
    {
        $argument = $this->argument('model');
        $configured = config('hybrid-search.models', []);
        $models = is_string($argument) ? [$argument] : array_values(array_filter(is_array($configured) ? $configured : [], 'is_string'));
        $chunk = $this->option('chunk');
        $size = max(1, is_numeric($chunk) ? (int) $chunk : 500);

        foreach ($models as $class) {
            if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Searchable::class)) {
                $this->error($class.' is not a searchable Eloquent model.');

                return self::FAILURE;
            }
            $count = 0;
            $class::query()->chunkById($size, function ($records) use ($indexer, &$count): void {
                foreach ($records as $record) {
                    /** @var Model&Searchable $record */
                    $indexer->index($record);
                    $count++;
                }
            });
            $this->line($class.': '.$count);
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Jobs;

use Dskripchenko\HybridSearch\Contracts\Searchable;
use Dskripchenko\HybridSearch\Indexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * (Re)index one record by class and key, after the transaction that changed
 * it commits; a record gone by then is taken out of the index.
 */
final class IndexRecord implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @param class-string<Model&Searchable> $class */
    public function __construct(
        public string $class,
        public string|int $id,
        public string $type,
        public string $key,
    ) {
        $this->afterCommit();
    }

    public function handle(Indexer $indexer): void
    {
        $record = $this->class::query()->find($this->id);

        $record instanceof Searchable
            ? $indexer->index($record)
            : $indexer->remove($this->type, $this->key);
    }
}

<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch\Concerns;

use Dskripchenko\HybridSearch\Contracts\Searchable;
use Dskripchenko\HybridSearch\Indexer;
use Dskripchenko\HybridSearch\Jobs\IndexRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a model's document in step: saved — (re)indexed, deleted — removed.
 * With `hybrid-search.queue` on, after the transaction commits, in a job.
 *
 * @mixin Model
 */
trait IndexedForSearch
{
    protected static function bootIndexedForSearch(): void
    {
        static::saved(static function (Model $model): void {
            if ($model instanceof Searchable) {
                self::dispatchIndexing($model);
            }
        });

        static::deleted(static function (Model $model): void {
            if ($model instanceof Searchable) {
                app(Indexer::class)->remove($model->searchType(), $model->searchKey());
            }
        });
    }

    /** Reindex this record now (in a job when queued). */
    public function searchable(): void
    {
        if ($this instanceof Searchable) {
            self::dispatchIndexing($this);
        }
    }

    private static function dispatchIndexing(Model&Searchable $model): void
    {
        if (! config('hybrid-search.queue', true)) {
            app(Indexer::class)->index($model);

            return;
        }

        $key = $model->getKey();
        $job = new IndexRecord($model::class, is_int($key) ? $key : (string) $key, $model->searchType(), $model->searchKey());
        $connection = config('hybrid-search.queue_connection');
        $queue = config('hybrid-search.queue_name');
        dispatch($job->onConnection(is_string($connection) ? $connection : null)->onQueue(is_string($queue) ? $queue : null));
    }
}

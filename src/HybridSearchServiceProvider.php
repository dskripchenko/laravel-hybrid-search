<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch;

use Dskripchenko\HybridSearch\Console\ReindexCommand;
use Illuminate\Support\ServiceProvider;

final class HybridSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hybrid-search.php', 'hybrid-search');
        $this->app->singleton(Indexer::class);
        $this->app->singleton(Search::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/hybrid-search.php' => config_path('hybrid-search.php')], 'hybrid-search-config');
            $this->commands([ReindexCommand::class]);
        }
    }
}

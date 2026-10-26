<?php

declare(strict_types=1);

namespace Dskripchenko\HybridSearch;

use Dskripchenko\HybridSearch\Console\EmbedCommand;
use Dskripchenko\HybridSearch\Console\ReindexCommand;
use Dskripchenko\HybridSearch\Contracts\Embedder;
use Dskripchenko\HybridSearch\Embedders\OpenAiCompatibleEmbedder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class HybridSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hybrid-search.php', 'hybrid-search');
        $this->app->singleton(Indexer::class);
        $this->app->singleton(Search::class);
        // The host may bind its own Embedder; this one is the fallback.
        $this->app->singletonIf(Embedder::class, static fn (Application $app): Embedder => OpenAiCompatibleEmbedder::fromConfig($app->make(Factory::class)));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/hybrid-search.php' => config_path('hybrid-search.php')], 'hybrid-search-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'hybrid-search-migrations');
            $this->commands([ReindexCommand::class, EmbedCommand::class]);
        }
    }
}

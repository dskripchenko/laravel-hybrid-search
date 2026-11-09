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
use InvalidArgumentException;

final class HybridSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hybrid-search.php', 'hybrid-search');
        $this->app->singleton(Indexer::class);
        $this->app->singleton(Search::class);
        // The host may bind its own Embedder, or name its class in the config;
        // the OpenAI-compatible client is the fallback.
        $this->app->singletonIf(Embedder::class, static function (Application $app): Embedder {
            $class = config('hybrid-search.vector.embedder.class');
            if (! is_string($class) || $class === '') {
                return OpenAiCompatibleEmbedder::fromConfig($app->make(Factory::class));
            }
            $embedder = $app->make($class);
            if (! $embedder instanceof Embedder) {
                throw new InvalidArgumentException("hybrid-search.vector.embedder.class: {$class} does not implement ".Embedder::class.'.');
            }

            return $embedder;
        });
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

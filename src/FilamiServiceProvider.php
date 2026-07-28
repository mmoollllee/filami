<?php

namespace Mmoollllee\Filami;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\Filami\Console\Commands\SyncCommand;

class FilamiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filami.php', 'filami');

        // bind(), not singleton(): the client is a thin config carrier, and a
        // shared instance would freeze credentials and TTLs at first resolution
        // — stale for the rest of an Octane worker's life, and out of step with
        // Filami::apiConfigured(), which keeps reading config live.
        $this->app->bind(UmamiClient::class, fn ($app) => UmamiClient::fromConfig($app['config']->get('filami', [])));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filami');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'filami');

        // <x-filami::tracking /> etc. resolve to class-based components.
        Blade::componentNamespace('Mmoollllee\\Filami\\View\\Components', 'filami');

        // Publishing and commands only matter to artisan — no reason to build
        // these arrays on every web request.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filami.php' => config_path('filami.php'),
            ], 'filami-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/filami'),
            ], 'filami-views');

            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/filami'),
            ], 'filami-lang');

            $this->commands([
                SyncCommand::class,
            ]);
        }
    }
}

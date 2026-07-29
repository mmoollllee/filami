<?php

namespace Mmoollllee\Filami;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\Filami\Console\Commands\SyncCommand;

class FilamiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Recursive, unlike mergeConfigFrom(): that one array_merges only the
        // top level, so an app whose published config predates a nested key
        // (tracking.consent, say) would silently lose every default under
        // `tracking` — and a missing consent category means "not gated".
        // Skipped once the app is config:cached — the cache already holds the
        // merged array, and re-requiring the file per request would both undo
        // that saving and re-evaluate env() with no .env loaded.
        if (! $this->app->configurationIsCached()) {
            $this->app['config']->set('filami', $this->mergeRecursive(
                require __DIR__.'/../config/filami.php',
                (array) $this->app['config']->get('filami', []),
            ));
        }

        // scoped(), not singleton(): one instance per REQUEST, which Octane
        // flushes between requests — so credentials and TTLs never freeze for a
        // worker's lifetime, the staleness singleton() would cause. bind() was
        // the previous compromise, but a dashboard render resolves this several
        // times and each resolution re-read the whole config array.
        $this->app->scoped(UmamiClient::class, fn ($app) => UmamiClient::fromConfig($app['config']->get('filami', [])));
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function mergeRecursive(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && is_array($defaults[$key] ?? null)
                // List values (e.g. tracking.environments) replace wholesale;
                // merging them would make a default impossible to remove.
                && ! array_is_list($value)
                ? $this->mergeRecursive($defaults[$key], $value)
                : $value;
        }

        return $defaults;
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

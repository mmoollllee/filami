<?php

namespace Mmoollllee\Filami\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\FilamiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The full Filament stack, so the widgets can be rendered rather than only
     * inspected. Order matters: Filament\Support has to register before
     * Livewire, otherwise Livewire's component resolution runs before Filament
     * has hooked into it and every Filament component fails to mount.
     */
    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            FilamiServiceProvider::class,
            Fixtures\TestPanelProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // "sites" exercises the attribute conventions (no trait involved).
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('primary_domain')->nullable();
            $table->string('umami_website_id')->nullable();
            $table->string('umami_url')->nullable();
            $table->boolean('umami_replay')->default(false);
            $table->timestamps();
        });

        // "tracked_sites" exercises the contract with overridden column mapping.
        Schema::create('tracked_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('host')->nullable();
            $table->string('analytics_id')->nullable();
            $table->string('endpoint')->nullable();
            $table->boolean('records')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Statics survive the per-test app rebuild.
        Filami::flush();
    }
}

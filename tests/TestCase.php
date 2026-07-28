<?php

namespace Mmoollllee\Filami\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\FilamiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FilamiServiceProvider::class,
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
            $table->timestamps();
        });

        // "tracked_sites" exercises the trait with overridden column mapping.
        Schema::create('tracked_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('host')->nullable();
            $table->string('analytics_id')->nullable();
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

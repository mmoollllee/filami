<?php

/**
 * Filami::autoProvision() attaches eloquent listeners instead of forcing a
 * trait onto host models: created records get a website, syncOn changes push
 * metadata, deletions only deprovision when explicitly enabled.
 */

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Jobs\DeprovisionUmamiWebsite;
use Mmoollllee\Filami\Jobs\ProvisionUmamiWebsite;
use Mmoollllee\Filami\Jobs\SyncUmamiWebsite;
use Mmoollllee\Filami\Tests\Fixtures\Site;

it('queues provisioning when a registered model is created', function () {
    configureUmami();
    Queue::fake();
    Filami::autoProvision(Site::class);

    Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);

    Queue::assertPushed(ProvisionUmamiWebsite::class, 1);
});

it('stays inert without credentials', function () {
    Queue::fake();
    Filami::autoProvision(Site::class);

    Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);

    Queue::assertNothingPushed();
});

it('provisions end to end on the sync queue', function () {
    configureUmami();
    fakeUmamiWebsites(created: ['id' => 'uuid-1', 'name' => 'Acme', 'domain' => 'acme.test']);
    Filami::autoProvision(Site::class);

    $site = Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);

    expect($site->fresh()->umami_website_id)->toBe('uuid-1');
});

it('adopts an existing website instead of creating a duplicate', function () {
    configureUmami();
    fakeUmamiWebsites(existing: [['id' => 'existing-1', 'name' => 'Acme', 'domain' => 'acme.test']]);
    Filami::autoProvision(Site::class);

    $site = Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);

    expect($site->fresh()->umami_website_id)->toBe('existing-1');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/websites'));
});

it('ignores lookup hits for a different domain', function () {
    configureUmami();
    fakeUmamiWebsites(
        created: ['id' => 'fresh-1'],
        existing: [['id' => 'other-1', 'name' => 'Other', 'domain' => 'other.test']],
    );
    Filami::autoProvision(Site::class);

    $site = Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);

    expect($site->fresh()->umami_website_id)->toBe('fresh-1');
});

it('skips provisioning without a domain', function () {
    configureUmami();
    Http::fake(['*/api/auth/login' => Http::response(['token' => 't'])]);
    Filami::autoProvision(Site::class);

    $site = Site::create(['name' => 'No Domain']);

    expect($site->fresh()->umami_website_id)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/websites'));
});

it('pushes metadata updates only for synced attributes', function () {
    configureUmami();
    Queue::fake();
    Filami::autoProvision(Site::class, syncOn: ['name', 'primary_domain']);

    $site = Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);
    $site->update(['name' => 'Acme GmbH']);
    $site->update(['umami_website_id' => 'w-1']); // not in syncOn

    Queue::assertPushed(SyncUmamiWebsite::class, 1);
});

it('respects the when filter', function () {
    configureUmami();
    Queue::fake();
    Filami::autoProvision(Site::class, when: fn (Site $site) => $site->name !== 'internal');

    Site::create(['name' => 'internal', 'primary_domain' => 'internal.test']);

    Queue::assertNothingPushed();
});

it('queues deprovisioning on delete when enabled', function () {
    configureUmami(['deprovision_on_delete' => true]);
    Queue::fake();
    Filami::autoProvision(Site::class);

    $site = Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);
    $site->forceFill(['umami_website_id' => 'w-9'])->saveQuietly();

    $site->delete();

    Queue::assertPushed(DeprovisionUmamiWebsite::class, fn ($job) => $job->websiteId === 'w-9');
});

it('keeps the website on delete by default', function () {
    configureUmami();
    Queue::fake();
    Filami::autoProvision(Site::class);

    $site = Site::create(['name' => 'Acme', 'primary_domain' => 'acme.test']);
    $site->forceFill(['umami_website_id' => 'w-9'])->saveQuietly();

    $site->delete();

    Queue::assertNotPushed(DeprovisionUmamiWebsite::class);
});

it('never deprovisions a record the when filter excludes', function () {
    // The id may have been entered by hand for a record filami does not manage.
    configureUmami(['deprovision_on_delete' => true]);
    Queue::fake();
    Filami::autoProvision(Site::class, when: fn (Site $site) => $site->name !== 'excluded');

    $site = Site::withoutEvents(fn () => Site::create([
        'name' => 'excluded',
        'primary_domain' => 'excluded.test',
        'umami_website_id' => 'hand-made',
    ]));

    $site->delete();

    Queue::assertNotPushed(DeprovisionUmamiWebsite::class);
});

it('deletes the website end to end when enabled', function () {
    configureUmami(['deprovision_on_delete' => true]);
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-9' => Http::response('ok'),
    ]);
    Filami::autoProvision(Site::class);

    // withoutEvents: this test is about the delete path, not provisioning.
    $site = Site::withoutEvents(fn () => Site::create([
        'name' => 'Acme',
        'primary_domain' => 'acme.test',
        'umami_website_id' => 'w-9',
    ]));

    $site->delete();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/api/websites/w-9'));
});

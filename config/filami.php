<?php

// Env-only config, mirroring the filament-cms philosophy: credentials and
// per-environment switches live here, all wiring happens in code (Filami::).
return [

    // Master switch. With a blank UMAMI_URL the whole integration stays inert.
    'enabled' => (bool) env('UMAMI_ENABLED', true),

    // Base URL of the Umami instance, e.g. https://a.moritz-graf.de — used for
    // the tracker script, dashboard links and (with '/api' appended) the API.
    'url' => env('UMAMI_URL'),

    // Optional API base override. Defaults to UMAMI_URL + '/api'. Umami Cloud
    // users would set https://api.umami.is/v1 here together with UMAMI_API_KEY.
    'api_url' => env('UMAMI_API_URL'),

    // Self-hosted auth: a dedicated Umami user whose login token is cached.
    'username' => env('UMAMI_USERNAME'),
    'password' => env('UMAMI_PASSWORD'),

    // Umami Cloud auth (x-umami-api-key). Self-hosted instances ignore this.
    'api_key' => env('UMAMI_API_KEY'),

    // Fallback website id for apps that track a single site and for panels
    // without a tenant context. Tenant models take precedence.
    'website_id' => env('UMAMI_WEBSITE_ID'),

    // Queue name for provisioning jobs (null = default queue).
    'queue' => env('UMAMI_QUEUE'),

    // Delete the Umami website (and all its data) when the linked model is
    // deleted. Off by default: analytics history usually outlives a tenant.
    'deprovision_on_delete' => (bool) env('UMAMI_DEPROVISION_ON_DELETE', false),

    'tracking' => [
        // Matches TRACKER_SCRIPT_NAME on the Umami server when renamed.
        'script_name' => env('UMAMI_TRACKER_SCRIPT', 'script.js'),

        // Environments in which <x-filami::tracking /> renders — production
        // only, so local clicks stay out of the real statistics. Comma
        // separated; '*' allows every environment.
        //
        //     UMAMI_TRACKING_ENVIRONMENTS=local,production
        //
        // Beware when widening this: Umami records whatever hostname sends the
        // event, so a .test domain lands in the same website as production.
        'environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('UMAMI_TRACKING_ENVIRONMENTS', 'production')),
        ))),
    ],

    'widgets' => [
        // Reporting window (in days) for the stats overview and top pages.
        'stats_period_days' => 7,
    ],

    'cache' => [
        // Cache store for API responses and the login token (null = default).
        'store' => env('UMAMI_CACHE_STORE'),
        // Stats/metrics cache in seconds; keeps dashboards snappy.
        'ttl' => 300,
        // "Active visitors" cache in seconds.
        'active_ttl' => 60,
        // Login tokens have no server-side expiry; re-login twice a day anyway.
        'token_ttl' => 43200,
        // How long a failed call is remembered, so a Umami that is down or
        // restarting is dialled once per window instead of on every render.
        'failure_ttl' => 30,
    ],

    'http' => [
        // Deliberately short: these calls sit in the dashboard render path.
        'timeout' => (int) env('UMAMI_TIMEOUT', 8),
    ],

];

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

        // Session replay (Umami 3.1+) and heatmaps (3.2+) ship as a second
        // script next to the tracker. Umami enables the feature per website,
        // so this is normally switched per model; the flag here is the
        // fallback for single-site apps that have no model to ask.
        //
        // Unlike TRACKER_SCRIPT_NAME there is NO server-side rename for the
        // recorder — only change this if you alias the file in a proxy.
        'recorder_script' => env('UMAMI_RECORDER_SCRIPT', 'recorder.js'),
        'recorder' => (bool) env('UMAMI_RECORDER', false),

        // Consent gating. With a category set, the script tag is emitted inert
        // (type="text/plain" plus the marker attribute) and a consent runtime
        // such as mmoollllee/laravel-consent-control swaps it for a live one
        // when that category is granted.
        //
        // Off by default for the tracker: Umami counts pageviews without
        // cookies and stores nothing on the device, so most setups do not
        // gate it — and gating costs a large share of the measurement.
        // Recording sessions is a different matter, hence its own key.
        'consent' => [
            'tracking' => env('UMAMI_CONSENT_CATEGORY'),
            // Falls back to the tracking category. Granting the recorder alone
            // achieves nothing: it waits for the tracker's session and gives
            // up after five seconds.
            'recorder' => env('UMAMI_RECORDER_CONSENT_CATEGORY'),
            // Attribute the consent runtime matches on. consent-control reads
            // script[type="text/plain"][data-consent="<category>"].
            'attribute' => env('UMAMI_CONSENT_ATTRIBUTE', 'data-consent'),
        ],

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

    // Custom events, via <x-filami::events />. Everything here needs the
    // tracker: with a consent gate in front of it, events wait for the same
    // opt-in the pageviews do.
    'events' => [
        // Track clicks on tel:/mailto: links anywhere on the page, including
        // the ones an editor writes into rich text — those are obfuscated
        // against scrapers and are labelled with data-filami-event instead.
        'links' => (bool) env('UMAMI_LINK_EVENTS', true),

        // Report "<name>-start" the first time a visitor touches a form marked
        // with data-filami-form="<name>". Paired with the "<name>-submit" the
        // form sends on success, that is a completion rate.
        'forms' => (bool) env('UMAMI_FORM_EVENTS', true),

        // Track clicks that leave the site — any http(s) link to another host.
        // Recorded with the target host, so the dashboard answers "where do we
        // send people" without a row per URL.
        'outbound' => (bool) env('UMAMI_OUTBOUND_EVENTS', true),

        // Hosts that are "us" even though they are not the current one, so a
        // click between your own domains does not count as leaving. The current
        // host is always internal and need not be listed.
        //
        //     UMAMI_INTERNAL_DOMAINS=example.com,shop.example.com
        //
        // Empty is a legitimate answer: a second domain of yours is often its
        // own destination (a jobs or shop site), and a visitor moving there is
        // a result worth counting rather than internal navigation to hide.
        //
        // Exact hostnames, not suffixes: "example.com" does NOT cover
        // "shop.example.com". Matching by registrable domain would need the
        // public suffix list to avoid treating co.uk as one site.
        'internal_domains' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('UMAMI_INTERNAL_DOMAINS', '')),
        ))),

        // Event names as they appear in Umami. Changing one starts a new
        // series — the old name keeps its recorded events.
        'phone_event' => env('UMAMI_PHONE_EVENT', 'phone-click'),
        'email_event' => env('UMAMI_EMAIL_EVENT', 'email-click'),
        'outbound_event' => env('UMAMI_OUTBOUND_EVENT', 'outbound-click'),
    ],

    'widgets' => [
        // Window the dashboard opens on: 24h, 7d, 30d or 90d. All three Umami
        // widgets share it, and the select in the stats overview changes it for
        // the whole section (remembered per panel for the session).
        //
        // filami <= 0.2 configured 'stats_period_days' here instead. A published
        // config still carrying that key keeps working — the day count is
        // widened to the nearest window that covers it.
        //
        // Deliberately NO '7d' default here: the service provider merges this
        // array over a published config, so a default would always win and the
        // legacy key could never be read. UmamiPeriod::default() holds the
        // fallback chain instead.
        'default_period' => env('UMAMI_DEFAULT_PERIOD'),

        // How many paths the top-pages table fetches in one go. Umami's
        // /metrics takes a limit but no offset, so this is the whole result set
        // the table pages through — raising it costs one bigger response, not
        // one request per page.
        'top_pages_limit' => 100,
    ],

    'cache' => [
        // Cache store for API responses and the login token (null = default).
        'store' => env('UMAMI_CACHE_STORE'),
        // Stats/metrics cache in seconds; keeps dashboards snappy.
        'ttl' => 300,
        // "Active visitors" cache in seconds. Deliberately LONGER than the
        // widgets' 60s poll: at exactly 60 the entry has always just expired
        // when the next poll asks for it, so it never absorbs a single request.
        // Umami defines the metric as a 5-minute rolling window anyway.
        'active_ttl' => 120,
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

<?php

/*
|--------------------------------------------------------------------------
| Consent categories — template
|--------------------------------------------------------------------------
|
| Copy-paste blueprint for mmoollllee/laravel-consent-control, taken from the
| wording agreed for the pernes-hebesysteme.de relaunch. Drop these entries
| into the project's config/consent-control.php.
|
| The stance it encodes:
|
|   Plain reach measurement runs WITHOUT consent and is only disclosed, as a
|   child of the mandatory category. Umami counts pageviews without cookies
|   and stores nothing on the device, so there is nothing to consent to — but
|   visitors should still see that it happens. Gating it anyway would cost a
|   large share of the measurement for no legal gain.
|
|   Session replay and heatmaps DO need an opt-in. They capture the DOM and
|   how a page is operated, which is not comparable to counting pageviews.
|   filami emits that recorder script inert until the category is granted:
|
|       UMAMI_RECORDER_CONSENT_CATEGORY=analytics
|
| Two things that bite if you forget them:
|
|   1. mergeConfigFrom() is a flat top-level merge, so a published `categories`
|      array REPLACES the package's default list wholesale — re-declare every
|      category you want, including `necessary` and `functional`.
|   2. Bump `version` whenever you add a category, or visitors who already
|      consented keep a cookie that never mentioned it and are never re-asked.
|
| On a multi-tenant engine also override `cookie.domain` (null = host-only).
| The vendor default derives it from APP_URL, which shares one consent across
| sibling tenants or breaks the write entirely on a non-matching host.
|
*/

return [
    'necessary' => [
        'label' => 'consent-control::consent.categories.necessary.label',
        'description' => 'consent-control::consent.categories.necessary.description',
        'checked' => true,
        'disabled' => true,
        'children' => [
            [
                'label' => 'consent-control::consent.categories.necessary.children.settings.label',
                'description' => 'consent-control::consent.categories.necessary.children.settings.description',
            ],
            // Listed rather than gated — see the note above.
            [
                'label' => 'Reichweitenmessung',
                'description' => 'Zählt Seitenaufrufe auf unserem eigenen Server, ohne Cookies und ohne IP-Adresse zu speichern. Ein Rückschluss auf einzelne Personen ist nicht möglich.',
            ],
        ],
    ],

    'functional' => [
        'label' => 'consent-control::consent.categories.functional.label',
        'description' => 'consent-control::consent.categories.functional.description',
    ],

    // Gates only Umami's recorder script, which filami emits as an inert
    // script[type="text/plain"][data-consent="analytics"] tag.
    'analytics' => [
        'label' => 'Analyse & Auswertung',
        'description' => 'Hilft uns zu verstehen, welche Inhalte genutzt werden. Wir betreiben die Auswertung auf einem eigenen Server; die Daten werden nicht an Dritte weitergegeben.',
        'checked' => false,
        'children' => [
            [
                'label' => 'Sitzungsaufzeichnung & Heatmaps',
                'description' => 'Zeichnet auf, wie Seiten bedient werden (Klicks, Mausbewegungen, Scrollen), damit wir Bedienprobleme erkennen. Deutlich detaillierter als die reine Zählung — deshalb nur mit Ihrer Einwilligung und nur dort, wo wir es eingeschaltet haben.',
            ],
        ],
    ],
];

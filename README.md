# filami

Umami analytics for Filament panels: provisions an Umami website per tenant
automatically, ships the tracking snippet and a statistics page. Built for
self-hosted Umami v3 (v2-tolerant), Filament 5 native.

- **Auto-provisioning** — `Filami::autoProvision(Tenant::class)` attaches
  model listeners: created records get an Umami website via the API (queued,
  idempotent), attribute changes push name/domain updates, deletions can
  optionally remove the website.
- **Tracking snippet** — `<x-filami::tracking :for="$tenant" />` renders
  dns-prefetch/preconnect plus the deferred script tag; renders nothing when
  disabled, without an id, or outside allowed environments.
- **Statistics page** — a panel page carrying the stats overview (live
  visitors, visitors, pageviews, visit time, bounce rate vs. previous period),
  the visitors/pageviews chart, top pages and recorded events, all sharing one
  reporting window. Tenant-aware and self-hiding, labels localized (de/en).
- **Backfill** — `php artisan filami:sync` provisions everything that existed
  before the integration. `--push` also re-sends name/domain for linked
  records and re-links any id the instance does not know (e.g. left over from
  a previous Umami server), which would otherwise be skipped forever.

Deploying Umami itself on Plesk? See [docs/deploy-plesk.md](docs/deploy-plesk.md).

## Installation

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/mmoollllee/filami" }
]
```

```bash
composer require mmoollllee/filami
```

```dotenv
UMAMI_URL=https://a.example.com
UMAMI_USERNAME=provisioner
UMAMI_PASSWORD=secret
```

Env is optional for tracking: a model that carries its own endpoint **and**
website id is tracked without any configuration at all — see
[Per-model endpoints](#per-model-endpoints). Credentials stay in env either
way; they gate only the API features (provisioning, widgets).

Self-hosted Umami has no API keys; filami logs in with a dedicated Umami user,
caches the token and re-authenticates on 401. (Umami Cloud works too:
`UMAMI_API_KEY` + `UMAMI_API_URL=https://api.umami.is/v1`.)

With a blank `UMAMI_URL` the whole integration stays inert — safe default for
local development.

## Wiring a tenant model

Register the model (e.g. in a service provider's `boot()`):

```php
use Mmoollllee\Filami\Filami;

Filami::autoProvision(
    Tenant::class,
    syncOn: ['name', 'primary_domain'],          // attributes that push updates
    when: fn (Tenant $t) => ! $t->isInternal(),  // optional filter
);
```

Storage/metadata resolution per model:

1. Models implementing **`UmamiTrackable`** answer for themselves. Add
   **`HasUmamiWebsite`** for the conventional bodies and override only what
   your schema names differently:

   ```php
   class Tenant extends Model implements UmamiTrackable
   {
       use HasUmamiWebsite;

       public function umamiWebsiteId(): ?string
       {
           return $this->analytics_umamiid;   // legacy column
       }
   }
   ```

2. Every other model falls back to the **attribute conventions** —
   `umami_website_id`, `umami_url`, `name`, and `primary_domain` / `domain` /
   host of `url`. The trait delegates to exactly these, so both paths always
   agree.

## Per-model endpoints

`umamiUrl()` (column `umami_url` by convention) lets each model name the Umami
instance it reports to; blank falls back to `UMAMI_URL`. Everything follows
that endpoint — the tracking snippet, provisioning, the widgets and the
"open in Umami" link:

```php
$tenant->update([
    'umami_url' => 'https://analytics.customer.example',
    'umami_website_id' => '94db1cb1-…',
]);
```

Those two values are all the snippet needs, so a site can be tracked purely
from the admin UI with **no env configuration** — useful when each customer
runs their own Umami. `filament-cms` exposes both as fields under
*Seiten-Einstellungen → Statistik*.

Credentials remain global (`UMAMI_USERNAME`/`UMAMI_PASSWORD` or
`UMAMI_API_KEY`): they are secrets, and without them a per-tenant endpoint
still tracks — it just cannot auto-provision or render widgets.

So a model with `umami_website_id`, `name` and `primary_domain` columns needs
no code at all besides `autoProvision()` — add that column yourself, filami
ships no migration:

```php
$table->string('umami_website_id')->nullable()->unique();
```

`filament-cms` wires itself up this way automatically when filami is installed
and brings its own migration for the column.

## Tracking snippet

In the site layout's `<head>`:

```blade
<x-filami::tracking :for="$tenant" />
{{-- or a fixed site: --}}
<x-filami::tracking website-id="94db1cb1-…" />
```

Extra attributes are forwarded to the tracker tag (`data-domains`, `data-tag`,
`data-exclude-search`, `fetchpriority`, …). The recorder tag below receives
only `data-website-id` and `data-host-url`, the two it reads — so a `nonce`
for a strict CSP has to reach it another way.

Rendering is limited to `config('filami.tracking.environments')`
(default `['production']`, `'*'` = everywhere). Without an explicit id the
component falls back to `UMAMI_WEBSITE_ID` — handy for single-site apps.

### Session replay & heatmaps

Umami records sessions — and builds heatmaps from them — via a **second**
script next to the tracker. Enable the feature for the website in Umami, then
switch it on for the model (`umami_replay` by convention, a toggle under
*Seiten-Einstellungen → Statistik* in filament-cms):

```blade
{{-- follows the model; or force it either way --}}
<x-filami::tracking :for="$tenant" />
<x-filami::tracking :for="$tenant" recorder />
<x-filami::tracking :for="$tenant" :recorder="false" />
```

Both tags share the website id and the model's endpoint. `UMAMI_RECORDER=true`
is the equivalent for apps that pass **no** model — a named model always
answers for itself, so the flag does nothing where `:for` is set (which
includes every filament-cms site).

Recording is far less anonymous than counting pageviews — check the site's
privacy policy before switching it on.

Three things worth knowing, none of them obvious:

- **Versions.** Replay needs Umami 3.1+, heatmaps 3.2+.
- **Heatmaps frame your site.** The overlay is a live `<iframe>` of the real
  page, not a replay screenshot. A site sending `X-Frame-Options: SAMEORIGIN`
  or a restrictive `frame-ancestors` renders a blank box — allow the Umami
  origin to frame it. Your CSP also needs that origin in `script-src` and
  `connect-src`.
- **The recorder cannot be renamed.** `TRACKER_SCRIPT_NAME` aliases only
  `script.js`, so adblock-evasion setups keep working for pageviews while the
  recorder silently fails.

filami never sends `replayConfig` when syncing a website, so a rename cannot
disturb what you configured in Umami — on 3.2+ the update is partial, and on
3.1 it would replace the whole object.

## Consent

Set a category and the script tags are emitted inert —
`<script type="text/plain" data-consent="analytics" …>` — for a consent
runtime to swap once that category is granted. Tested against
[`mmoollllee/laravel-consent-control`](https://github.com/mmoollllee/laravel-consent-control),
whose `analytics` category ships by default; `UMAMI_CONSENT_ATTRIBUTE` adapts
the marker for other runtimes.

```dotenv
# The usual setup: count freely, ask before recording.
UMAMI_RECORDER_CONSENT_CATEGORY=analytics

# Gate the plain tracker too, if your jurisdiction or policy calls for it.
UMAMI_CONSENT_CATEGORY=analytics
```

Counting pageviews and recording sessions are separate keys on purpose. Umami
counts without cookies and stores nothing on the device, so most setups do not
gate the tracker at all — and gating it costs a large share of the
measurement. List it in the banner's mandatory category instead, so visitors
still see that it happens. Session replay captures the DOM and is not
comparable; that one belongs behind an opt-in.

[`stubs/consent-categories.php`](stubs/consent-categories.php) is a ready-made
category block for `mmoollllee/laravel-consent-control` carrying this stance in
German wording — copy it into a project's `config/consent-control.php`.

Granting the recorder without the tracker does nothing: it waits for the
tracker's session and gives up after five seconds.

## The statistics page

The widgets live on a page of their own — "Statistiken" / "Statistics" — so a
panel's dashboard stays about the work:

```php
->plugin(FilamiPlugin::make())
{{-- or, explicitly: --}}
->pages([Dashboard::class, \Mmoollllee\Filami\Filament\Pages\UmamiStatistics::class])
```

The page hides itself from the navigation under exactly the conditions its
widgets do, so a panel without credentials or a website id shows no dead menu
entry. Its route path is `statistics`; subclass it to change that, the title
or which widgets appear.

Prefer them on the dashboard instead? Reference the widget classes in that
page's own widget list:

```php
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiEventsWidget;
```

The website id comes from the current Filament tenant. `UMAMI_WEBSITE_ID` is
used only *outside* tenancy: a tenant that has no website yet shows nothing
rather than another tenant's numbers. Responses are cached (default 300 s,
60 s for live visitors) with keys snapped to the cache window, so dashboards
stay off the API hot path; when Umami is unreachable the widgets degrade to a
placeholder instead of breaking the dashboard.

**One window, one control.** The reporting window (24h / 7d / 30d / 90d) is
shared: the select sits in the stats overview and the other two widgets follow
it, so the dashboard cannot show a week of visitors next to a month of pages.
The choice is remembered per panel for the session. Keep the stats widget on
the dashboard — it is the only one that renders the control.

The top-pages table pages through its rows client-side. Umami's `/metrics`
takes a limit but no offset, so one request fetches `widgets.top_pages_limit`
paths (default 100) and the table pages that; sites with a longer tail follow
the "open in Umami" link.

## Custom events

`<x-filami::events />` goes after the tracking snippet and adds three things:

```blade
<x-filami::tracking :for="$tenant" />
<x-filami::events :for="$tenant" />
```

1. **Phone and mail clicks** — a delegated listener reports every `tel:` and
   `mailto:` click, including the ones an editor typed into rich text (which
   cannot carry Umami's own `data-umami-event` attribute). Opt a link out with
   `data-umami-ignore`; switch the whole thing off with `links="false"` or
   `UMAMI_LINK_EVENTS=false`.

   It matches on the `href`, so links whose address is obfuscated against
   scrapers (`href="#"` plus an encrypted token, as `laravel-spamprotect`
   renders them) cannot be recognised that way. Label those
   `data-filami-event="phone-click"` where they are generated and the same
   listener picks them up — filament-cms does this in `SpamprotectHtml`.

   Use `data-filami-event`, **not** Umami's own `data-umami-event`, on links
   like these: Umami attaches a capture-phase handler to `[data-umami-event]`
   anchors that calls `preventDefault()` and then forces `location.href` back
   to the element's own href, which on an `href="#"` link races the handler
   decrypting the address. Put only the event *name* in the attribute — event
   data ends up in the markup and would undo the obfuscation.
2. **A Livewire bridge** — dispatch `filami-track` and it lands in Umami, with
   nothing Umami-shaped in your app code:

   ```php
   $this->dispatch('filami-track', name: 'contact-form-submit', data: ['type' => 'general']);
   ```
3. **Outbound clicks** — any `http(s)` link to another host reports
   `outbound-click` with the target host, so the dashboard answers "where do we
   send people" in one row per destination rather than one per link. Relative
   hrefs, `#anchor` and `javascript:` fall out on their own (the check reads the
   anchor's *resolved* protocol and hostname).

   A site spread over several of its own domains can declare the others, so a
   hop between them is not counted as leaving. Whether it should be is a
   judgement call, not a rule: a jobs or shop domain is often its own
   destination, and moving there is a result worth measuring.

   ```dotenv
   UMAMI_INTERNAL_DOMAINS=example.com,shop.example.com
   ```

   Exact hostnames, not suffixes — matching by registrable domain would need the
   public suffix list to avoid treating `co.uk` as one site.
4. **`window.filami.track(name, data)`** for plain JS and Alpine.

All three are no-ops while `window.umami` is absent — which is what makes them
correct behind a consent gate: no tracker, no events, and no second gate to
keep in sync. Never put personal data in an event; the payload is stored
alongside the pageview.

## Config reference

| env | default | |
| --- | --- | --- |
| `UMAMI_URL` | – | instance base URL (script, links, API base) |
| `UMAMI_USERNAME` / `UMAMI_PASSWORD` | – | dedicated Umami user for the API |
| `UMAMI_API_KEY` | – | Umami Cloud only |
| `UMAMI_API_URL` | `UMAMI_URL` + `/api` | API base override |
| `UMAMI_WEBSITE_ID` | – | static fallback website id |
| `UMAMI_ENABLED` | `true` | master switch |
| `UMAMI_QUEUE` | default queue | queue for provisioning jobs |
| `UMAMI_DEPROVISION_ON_DELETE` | `false` | delete websites with their models |
| `UMAMI_TRACKER_SCRIPT` | `script.js` | matches `TRACKER_SCRIPT_NAME` server-side |
| `UMAMI_RECORDER` | `false` | load the replay recorder (single-site fallback) |
| `UMAMI_RECORDER_SCRIPT` | `recorder.js` | filename of the recorder script |
| `UMAMI_TRACKING_ENVIRONMENTS` | `production` | comma separated, `*` for all |
| `UMAMI_CONSENT_CATEGORY` | – | gate the tracker behind this consent category |
| `UMAMI_RECORDER_CONSENT_CATEGORY` | – | gate the recorder; defaults to the above |
| `UMAMI_CONSENT_ATTRIBUTE` | `data-consent` | marker for a non-standard consent runtime |
| `UMAMI_LINK_EVENTS` | `true` | auto-track `tel:` / `mailto:` clicks |
| `UMAMI_FORM_EVENTS` | `true` | auto-track the `<name>-start` half of form funnels |
| `UMAMI_OUTBOUND_EVENTS` | `true` | auto-track clicks leaving the site |
| `UMAMI_INTERNAL_DOMAINS` | – | comma-separated own hosts that are not "outbound" |
| `UMAMI_PHONE_EVENT` | `phone-click` | event name for phone clicks |
| `UMAMI_EMAIL_EVENT` | `email-click` | event name for mail clicks |
| `UMAMI_OUTBOUND_EVENT` | `outbound-click` | event name for outbound clicks |
| `UMAMI_DEFAULT_PERIOD` | `7d` | window the dashboard opens on |
| `UMAMI_CACHE_STORE` | default store | cache for tokens + stats |
| `UMAMI_TIMEOUT` | `8` | HTTP timeout; these calls sit in the render path |

Publish the config for the non-env knobs (tracking environments, top-pages
limit, cache TTLs): `php artisan vendor:publish --tag=filami-config`.

A config published against filami ≤ 0.2 still carries `widgets.stats_period_days`.
It keeps working — the day count is widened to the nearest window that covers
it — but `widgets.default_period` replaces it.

## Testing

```bash
composer test
```

# filami

Umami analytics for Filament panels: provisions an Umami website per tenant
automatically, ships the tracking snippet and dashboard widgets. Built for
self-hosted Umami v3 (v2-tolerant), Filament 5 native.

- **Auto-provisioning** — `Filami::autoProvision(Tenant::class)` attaches
  model listeners: created records get an Umami website via the API (queued,
  idempotent), attribute changes push name/domain updates, deletions can
  optionally remove the website.
- **Tracking snippet** — `<x-filami::tracking :for="$tenant" />` renders
  dns-prefetch/preconnect plus the deferred script tag; renders nothing when
  disabled, without an id, or outside allowed environments.
- **Dashboard widgets** — stats overview (live visitors, visitors, pageviews,
  visit time, bounce rate vs. previous period), visitors/pageviews chart with
  range filter, top pages with a deep link into Umami. All tenant-aware and
  self-hiding, labels localized (de/en).
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

Extra attributes are forwarded to the script tag
(`data-domains`, `data-tag`, `data-exclude-search`, `fetchpriority`, …).
Rendering is limited to `config('filami.tracking.environments')`
(default `['production']`, `'*'` = everywhere). Without an explicit id the
component falls back to `UMAMI_WEBSITE_ID` — handy for single-site apps.

## Widgets

Panels with an explicit dashboard widget list reference the classes directly:

```php
use Mmoollllee\Filami\Filament\Widgets\UmamiStatsOverviewWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiTopPagesWidget;
use Mmoollllee\Filami\Filament\Widgets\UmamiVisitorsChartWidget;
```

Alternatively register all three at once: `->plugin(FilamiPlugin::make())`.

The website id comes from the current Filament tenant. `UMAMI_WEBSITE_ID` is
used only *outside* tenancy: a tenant that has no website yet shows nothing
rather than another tenant's numbers. Responses are cached (default 300 s,
60 s for live visitors) with keys snapped to the cache window, so dashboards
stay off the API hot path; when Umami is unreachable the widgets degrade to a
placeholder instead of breaking the dashboard.

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
| `UMAMI_CACHE_STORE` | default store | cache for tokens + stats |
| `UMAMI_TIMEOUT` | `8` | HTTP timeout; these calls sit in the render path |

Publish the config for the non-env knobs (tracking environments, widget
period, cache TTLs): `php artisan vendor:publish --tag=filami-config`.

## Testing

```bash
composer test
```

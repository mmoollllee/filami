<?php

namespace Mmoollllee\Filami;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Mmoollllee\Filami\Concerns\HasUmamiWebsite;
use Mmoollllee\Filami\Contracts\UmamiTrackable;
use Mmoollllee\Filami\Jobs\DeprovisionUmamiWebsite;
use Mmoollllee\Filami\Jobs\ProvisionUmamiWebsite;
use Mmoollllee\Filami\Jobs\SyncUmamiWebsite;
use Throwable;
use WeakMap;

/**
 * Static wiring seam for the Umami integration, mirroring the Cms registry
 * style: host apps register their tenant models in code, only credentials
 * live in config/env.
 *
 * Website id / name / domain resolution:
 *   1. models implementing {@see UmamiTrackable} answer for themselves —
 *      {@see HasUmamiWebsite} supplies the conventional bodies, so mapping
 *      onto an existing schema means overriding one accessor
 *   2. every other model falls back to the attribute conventions
 *      (umami_website_id, name, primary_domain/domain/url)
 */
class Filami
{
    /** @var array<class-string<Model>, array{syncOn: list<string>, when: Closure|null}> */
    protected static array $provisioned = [];

    /**
     * Listener bookkeeping per event-dispatcher instance: these statics survive
     * app rebuilds (tests, Octane) while listeners do not, so a fresh dispatcher
     * must re-attach even when the registration is already known.
     *
     * A WeakMap keyed on the dispatcher itself, NOT spl_object_id(): PHP recycles
     * object handles as soon as the previous dispatcher is freed, so an id-keyed
     * array would report "already attached" for a brand-new dispatcher and
     * silently stop provisioning. WeakMap entries also disappear with their
     * dispatcher instead of growing per boot.
     *
     * @var WeakMap<object, array<class-string<Model>, true>>|null
     */
    protected static ?WeakMap $listenersAttached = null;

    /**
     * Tracking-level switch: a URL is all the snippet needs. Every accessor
     * below takes an optional model, so a tenant carrying its own endpoint can
     * be tracked without any env configuration at all.
     */
    public static function enabled(mixed $model = null): bool
    {
        return (bool) config('filami.enabled') && filled(static::url($model));
    }

    /** API-level switch: provisioning and widgets additionally need credentials. */
    public static function apiConfigured(mixed $model = null): bool
    {
        return static::enabled($model) && static::hasCredentials();
    }

    /**
     * Whether API credentials exist. Always global — endpoints can be set per
     * model, but credentials are secrets and stay in env.
     */
    public static function hasCredentials(): bool
    {
        return filled(config('filami.api_key'))
            || (filled(config('filami.username')) && filled(config('filami.password')));
    }

    /** The Umami instance a model reports to: its own endpoint, else the configured one. */
    public static function url(mixed $model = null): ?string
    {
        $url = static::modelUrl($model) ?? config('filami.url');

        return filled($url) ? rtrim((string) $url, '/') : null;
    }

    public static function apiUrl(mixed $model = null): ?string
    {
        // A model-level endpoint owns its API path too; the configured
        // api_url override only applies to the configured endpoint.
        if (static::modelUrl($model) === null && filled($apiUrl = config('filami.api_url'))) {
            return rtrim((string) $apiUrl, '/');
        }

        return static::url($model) ? static::url($model).'/api' : null;
    }

    /** A client bound to the instance $model reports to. */
    public static function client(mixed $model = null): UmamiClient
    {
        $apiUrl = static::apiUrl($model);

        // Same instance as the configured default — reuse the container binding.
        return $apiUrl === static::apiUrl(null)
            ? app(UmamiClient::class)
            : UmamiClient::fromConfig((array) config('filami', []), apiUrl: $apiUrl);
    }

    public static function websiteDashboardUrl(?string $websiteId, mixed $model = null): ?string
    {
        $url = static::url($model);

        if (blank($websiteId) || $url === null) {
            return null;
        }

        return $url.'/websites/'.$websiteId;
    }

    protected static function modelUrl(mixed $model): ?string
    {
        if (! $model instanceof Model) {
            return null;
        }

        $url = $model instanceof UmamiTrackable
            ? $model->umamiUrl()
            : static::conventionalUrl($model);

        return filled($url) ? (string) $url : null;
    }

    public static function websiteId(mixed $model): ?string
    {
        if (! $model instanceof Model) {
            return null;
        }

        $websiteId = $model instanceof UmamiTrackable
            ? $model->umamiWebsiteId()
            : static::conventionalWebsiteId($model);

        return filled($websiteId) ? (string) $websiteId : null;
    }

    public static function storeWebsiteId(Model $model, ?string $websiteId): void
    {
        $model instanceof UmamiTrackable
            ? $model->setUmamiWebsiteId($websiteId)
            : static::storeConventionally($model, $websiteId);
    }

    /** @return array{name: string, domain: ?string} */
    public static function websiteMeta(Model $model): array
    {
        $name = $model instanceof UmamiTrackable
            ? $model->umamiWebsiteName()
            : static::conventionalName($model);

        $domain = $model instanceof UmamiTrackable
            ? $model->umamiWebsiteDomain()
            : static::conventionalDomain($model);

        return [
            'name' => filled($name) ? (string) $name : class_basename($model).' #'.$model->getKey(),
            'domain' => filled($domain) ? (string) $domain : null,
        ];
    }

    /**
     * The attribute conventions, in one place. {@see HasUmamiWebsite} delegates
     * here so a model with the trait and a model without behave identically —
     * having the rules written twice is how the two quietly drift apart.
     */
    public static function conventionalWebsiteId(Model $model): ?string
    {
        $websiteId = $model->getAttribute('umami_website_id');

        return filled($websiteId) ? (string) $websiteId : null;
    }

    public static function storeConventionally(Model $model, ?string $websiteId): void
    {
        // Quietly: storing the id must not re-enter the update listener.
        $model->forceFill(['umami_website_id' => $websiteId])->saveQuietly();
    }

    public static function conventionalName(Model $model): string
    {
        $name = $model->getAttribute('name');

        return filled($name) ? (string) $name : class_basename($model).' #'.$model->getKey();
    }

    public static function conventionalUrl(Model $model): ?string
    {
        $url = $model->getAttribute('umami_url');

        return filled($url) ? (string) $url : null;
    }

    public static function conventionalDomain(Model $model): ?string
    {
        $domain = $model->getAttribute('primary_domain') ?? $model->getAttribute('domain');

        if (blank($domain) && filled($url = $model->getAttribute('url'))) {
            $domain = parse_url((string) $url, PHP_URL_HOST) ?: null;
        }

        return filled($domain) ? (string) $domain : null;
    }

    /**
     * Register a model for automatic provisioning: created records get an
     * Umami website, changes to $syncOn attributes push name/domain updates,
     * deletions optionally remove the website (config filami.deprovision_on_delete).
     * $when filters records (e.g. exclude internal tenants).
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $syncOn
     */
    public static function autoProvision(string $model, array $syncOn = [], ?Closure $when = null): void
    {
        static::$provisioned[$model] = ['syncOn' => array_values($syncOn), 'when' => $when];

        $dispatcher = Event::getFacadeRoot();
        static::$listenersAttached ??= new WeakMap;
        $attached = static::$listenersAttached[$dispatcher] ?? [];

        if (isset($attached[$model])) {
            return;
        }

        $attached[$model] = true;
        static::$listenersAttached[$dispatcher] = $attached;

        Event::listen("eloquent.created: {$model}", fn (Model $record) => static::handleCreated($record));
        Event::listen("eloquent.updated: {$model}", fn (Model $record) => static::handleUpdated($record));
        Event::listen("eloquent.deleted: {$model}", fn (Model $record) => static::handleDeleted($record));
    }

    /** @return list<class-string<Model>> */
    public static function provisionedModels(): array
    {
        return array_keys(static::$provisioned);
    }

    /** Whether a record is registered and passes its autoProvision() filter. */
    public static function passesFilter(Model $record): bool
    {
        $registration = static::$provisioned[$record::class] ?? null;

        if ($registration === null) {
            return false;
        }

        return $registration['when'] === null || (bool) $registration['when']($record);
    }

    /**
     * The website id a given model tracks into. A named model is
     * authoritative even when it has no id yet: falling back to the configured
     * single-site id would show — and record — one tenant's traffic under
     * another. Only without a model does that fallback apply.
     */
    public static function websiteIdFor(mixed $model = null): ?string
    {
        return $model === null
            ? static::configuredWebsiteId()
            : static::websiteId($model);
    }

    /** Website id for the current Filament context (tenant, else single-site). */
    public static function currentWebsiteId(): ?string
    {
        try {
            $tenant = Filament::getTenant();
        } catch (Throwable) {
            $tenant = null;
        }

        return static::websiteIdFor($tenant);
    }

    /** The static single-site id from config, for apps without tenancy. */
    public static function configuredWebsiteId(): ?string
    {
        $websiteId = config('filami.website_id');

        return filled($websiteId) ? (string) $websiteId : null;
    }

    /** Whether the tracking snippet renders right now — for privacy pages etc. */
    public static function tracks(mixed $model = null): bool
    {
        return static::enabled($model)
            && static::environmentAllowed()
            && filled(static::websiteIdFor($model));
    }

    /** Whether filami.tracking.environments covers the current environment. */
    public static function environmentAllowed(): bool
    {
        $environments = static::trackingEnvironments();

        return in_array('*', $environments, true) || app()->environment($environments);
    }

    /** @return list<string> */
    public static function trackingEnvironments(): array
    {
        return array_values((array) config('filami.tracking.environments', ['production']));
    }

    /**
     * Why the snippet does not render for $model, as a translated sentence —
     * or null when it does. The gate is otherwise silent, which makes a
     * correctly configured site look broken.
     */
    public static function inactiveReason(mixed $model = null): ?string
    {
        if (static::tracks($model)) {
            return null;
        }

        if (! (bool) config('filami.enabled')) {
            return __('filami::status.disabled');
        }

        if (blank(static::url($model))) {
            return __('filami::status.no_endpoint');
        }

        if (blank(static::websiteIdFor($model))) {
            return __('filami::status.no_website_id');
        }

        return __('filami::status.wrong_environment', [
            'environments' => implode(', ', static::trackingEnvironments()),
            'current' => app()->environment(),
        ]);
    }

    protected static function handleCreated(Model $record): void
    {
        if (! static::apiConfigured($record) || ! static::passesFilter($record)) {
            return;
        }

        if (static::websiteId($record) !== null) {
            return;
        }

        ProvisionUmamiWebsite::dispatch($record)->onQueue(config('filami.queue'))->afterCommit();
    }

    protected static function handleUpdated(Model $record): void
    {
        if (! static::apiConfigured($record) || ! static::passesFilter($record)) {
            return;
        }

        $syncOn = static::$provisioned[$record::class]['syncOn'] ?? [];

        if ($syncOn === [] || array_intersect($syncOn, array_keys($record->getChanges())) === []) {
            return;
        }

        SyncUmamiWebsite::dispatch($record)->onQueue(config('filami.queue'))->afterCommit();
    }

    protected static function handleDeleted(Model $record): void
    {
        if (! config('filami.deprovision_on_delete') || ! static::apiConfigured($record)) {
            return;
        }

        // Same filter as create/update: a record the app excluded via when() is
        // not ours to delete — its website id may have been entered by hand.
        if (! static::passesFilter($record)) {
            return;
        }

        // Soft deletes keep the website; only a hard delete removes it.
        if (method_exists($record, 'isForceDeleting') && ! $record->isForceDeleting()) {
            return;
        }

        $websiteId = static::websiteId($record);

        if ($websiteId === null) {
            return;
        }

        // The endpoint travels with the id: by the time this job runs the record
        // is gone, so its own Umami instance can no longer be resolved from it.
        DeprovisionUmamiWebsite::dispatch($websiteId, static::apiUrl($record))
            ->onQueue(config('filami.queue'))
            ->afterCommit();
    }

    /**
     * Reset the registrations (tests). Already-attached listeners stay on their
     * dispatcher but no-op once the registration is gone; the per-dispatcher
     * bookkeeping is kept so re-registering on the same app never double-fires.
     */
    public static function flush(): void
    {
        static::$provisioned = [];
    }
}

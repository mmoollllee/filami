<?php

namespace Mmoollllee\Filami;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mmoollllee\Filami\Support\UmamiStats;
use RuntimeException;
use Throwable;

/**
 * Thin HTTP client for the Umami API (v3, v2-tolerant where responses differ).
 *
 * Self-hosted auth is a login token: Umami issues JWTs without expiry, so the
 * token is cached and refreshed once a request answers 401 (password or
 * APP_SECRET rotation). Umami Cloud auth uses the x-umami-api-key header.
 * Read endpoints are cached to keep dashboard widgets off the API hot path.
 */
class UmamiClient
{
    public function __construct(
        protected ?string $apiUrl,
        protected ?string $username = null,
        protected ?string $password = null,
        protected ?string $apiKey = null,
        protected int $timeout = 15,
        protected ?string $cacheStore = null,
        protected int $cacheTtl = 300,
        protected int $activeCacheTtl = 60,
        protected int $tokenTtl = 43200,
        protected int $failureTtl = 30,
    ) {}

    /** Sentinel for a cached failure; no API response can collide with it. */
    private const UNREACHABLE = '__filami_unreachable__';

    public static function fromConfig(array $config): self
    {
        return new self(
            // Filami owns the url/api_url derivation so the dashboard links and
            // the API calls can never disagree about the base URL.
            apiUrl: Filami::apiUrl(),
            username: $config['username'] ?? null,
            password: $config['password'] ?? null,
            apiKey: $config['api_key'] ?? null,
            timeout: (int) ($config['http']['timeout'] ?? 15),
            cacheStore: $config['cache']['store'] ?? null,
            cacheTtl: (int) ($config['cache']['ttl'] ?? 300),
            activeCacheTtl: (int) ($config['cache']['active_ttl'] ?? 60),
            tokenTtl: (int) ($config['cache']['token_ttl'] ?? 43200),
            failureTtl: (int) ($config['cache']['failure_ttl'] ?? 30),
        );
    }

    /** @return array{id: string, name: string, domain: string} */
    public function createWebsite(string $name, string $domain): array
    {
        $website = $this->send('post', '/websites', ['name' => $name, 'domain' => $domain])->throw()->json();

        if (blank($website['id'] ?? null)) {
            throw new RuntimeException('Umami did not return an id for the created website.');
        }

        return $website;
    }

    /** Returns null when the website no longer exists (deleted in the Umami UI). */
    public function updateWebsite(string $websiteId, array $attributes): ?array
    {
        return (array) $this->sendOrNull('post', "/websites/{$websiteId}", $attributes)?->json() ?: null;
    }

    /**
     * Look up a website by exact domain. Umami happily creates duplicates for
     * the same domain, so provisioning adopts an existing website instead of
     * adding a second one whenever a retry or a racing job comes back around.
     */
    public function findWebsiteByDomain(string $domain): ?array
    {
        $payload = (array) $this->sendOrNull('get', '/websites', ['query' => $domain, 'pageSize' => 100])?->json();

        // v3 paginates under "data"; older builds returned a bare array.
        return collect($payload['data'] ?? $payload)->first(fn ($website): bool => is_array($website)
            && ($website['domain'] ?? null) === $domain
            && filled($website['id'] ?? null));
    }

    /** Deleting is idempotent: a website that is already gone counts as success. */
    public function deleteWebsite(string $websiteId): void
    {
        $this->sendOrNull('delete', "/websites/{$websiteId}");
    }

    public function getWebsite(string $websiteId): ?array
    {
        return $this->sendOrNull('get', "/websites/{$websiteId}")?->json();
    }

    public function stats(string $websiteId, CarbonInterface $startAt, CarbonInterface $endAt): UmamiStats
    {
        $data = $this->remember(
            sprintf('stats:%s:%s', $websiteId, $this->cacheWindow($startAt, $endAt)),
            $this->cacheTtl,
            fn () => $this->send('get', "/websites/{$websiteId}/stats", $this->window($startAt, $endAt))->throw()->json(),
        );

        return UmamiStats::fromResponse(is_array($data) ? $data : []);
    }

    /** Unique visitors within the last 5 minutes. */
    public function activeVisitors(string $websiteId): int
    {
        $data = $this->remember(
            "active:{$websiteId}",
            $this->activeCacheTtl,
            fn () => $this->send('get', "/websites/{$websiteId}/active")->throw()->json(),
        );

        // v3 responds {visitors: n}; old v2 builds responded [{x: n}].
        return (int) (is_array($data) ? ($data['visitors'] ?? $data[0]['x'] ?? 0) : 0);
    }

    /**
     * @param  'year'|'month'|'day'|'hour'|'minute'  $unit
     * @return array{pageviews: list<array{x: string, y: int}>, sessions: list<array{x: string, y: int}>}
     */
    public function pageviewSeries(
        string $websiteId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        string $unit = 'day',
        ?string $timezone = null,
    ): array {
        $query = $this->window($startAt, $endAt) + [
            'unit' => $unit,
            'timezone' => $timezone ?? config('app.timezone', 'UTC'),
        ];

        $data = $this->remember(
            'pageviews:'.md5(implode('|', [$websiteId, $unit, $query['timezone'], $this->cacheWindow($startAt, $endAt)])),
            $this->cacheTtl,
            fn () => $this->send('get', "/websites/{$websiteId}/pageviews", $query)->throw()->json(),
        );

        return [
            'pageviews' => array_values($data['pageviews'] ?? []),
            'sessions' => array_values($data['sessions'] ?? []),
        ];
    }

    /**
     * @param  string  $type  v3 types: path|entry|exit|title|query|referrer|browser|os|device|country|region|city|language|event
     * @return list<array{x: string, y: int}>
     */
    public function metrics(
        string $websiteId,
        string $type,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        int $limit = 10,
    ): array {
        $query = $this->window($startAt, $endAt) + ['type' => $type, 'limit' => $limit];

        $data = $this->remember(
            'metrics:'.md5(implode('|', [$websiteId, $type, $limit, $this->cacheWindow($startAt, $endAt)])),
            $this->cacheTtl,
            fn () => $this->send('get', "/websites/{$websiteId}/metrics", $query)->throw()->json(),
        );

        return is_array($data) ? array_values($data) : [];
    }

    /** @return array{startAt: int, endAt: int} */
    protected function window(CarbonInterface $startAt, CarbonInterface $endAt): array
    {
        return ['startAt' => $startAt->getTimestampMs(), 'endAt' => $endAt->getTimestampMs()];
    }

    /**
     * Window identifier for cache keys, snapped to whole TTL buckets. Callers
     * pass a fresh now() on every render, so keying on the raw millisecond
     * boundaries would mint a new key each time and the cache could never hit.
     *
     * The bucket size IS the TTL on purpose: both boundaries then roll over at
     * the same instant for any window that is a whole multiple of it, so a key
     * stays valid for exactly as long as its entry lives.
     */
    protected function cacheWindow(CarbonInterface $startAt, CarbonInterface $endAt): string
    {
        $bucket = max(1, $this->cacheTtl) * 1000;

        return intdiv($startAt->getTimestampMs(), $bucket).'-'.intdiv($endAt->getTimestampMs(), $bucket);
    }

    /** Like send(), but folds a 404 into null — the shared "gone" contract. */
    protected function sendOrNull(string $method, string $uri, array $payload = []): ?Response
    {
        $response = $this->send($method, $uri, $payload);

        return $response->status() === 404 ? null : $response->throw();
    }

    protected function send(string $method, string $uri, array $payload = []): Response
    {
        $response = $this->performRequest($method, $uri, $payload);

        // A cached login token dies when the password or APP_SECRET rotates.
        // Umami also answers 401 for a website the account cannot see, so the
        // re-login is throttled: otherwise a single tenant holding a foreign
        // website id would evict the shared token and trigger a login storm on
        // every widget render.
        if ($response->status() === 401 && $this->usesLogin() && $this->mayRefreshToken()) {
            $this->cache()->forget($this->tokenCacheKey());
            $response = $this->performRequest($method, $uri, $payload);
        }

        return $response;
    }

    /** True at most once per cooldown window; add() only succeeds when unset. */
    protected function mayRefreshToken(): bool
    {
        return $this->cache()->add($this->tokenCacheKey().':cooldown', true, 60);
    }

    /** @param  'get'|'post'|'delete'  $method */
    protected function performRequest(string $method, string $uri, array $payload): Response
    {
        if (blank($this->apiUrl)) {
            throw new RuntimeException('Umami is not configured — set UMAMI_URL first.');
        }

        $request = Http::baseUrl($this->apiUrl)->timeout($this->timeout)->acceptJson();

        $request = $this->usesLogin()
            ? $request->withToken($this->token())
            : $request->withHeaders(['x-umami-api-key' => (string) $this->apiKey]);

        return match ($method) {
            'get' => $request->get($uri, $payload),
            'post' => $request->post($uri, $payload),
            'delete' => $request->delete($uri),
        };
    }

    protected function usesLogin(): bool
    {
        return blank($this->apiKey);
    }

    protected function token(): string
    {
        return $this->cache()->remember($this->tokenCacheKey(), $this->tokenTtl, function (): string {
            $response = Http::baseUrl((string) $this->apiUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('/auth/login', ['username' => $this->username, 'password' => $this->password])
                ->throw();

            $token = $response->json('token');

            if (blank($token)) {
                throw new RuntimeException('Umami login response did not contain a token.');
            }

            return (string) $token;
        });
    }

    protected function tokenCacheKey(): string
    {
        return 'filami:token:'.md5($this->apiUrl.'|'.$this->username);
    }

    /**
     * Cache::remember() stores nothing when the callback throws, so a dead
     * Umami would be re-dialled on every render — each attempt holding a
     * worker for the full timeout. Failures therefore get a short negative
     * entry of their own; the exception still propagates so widgets can show
     * their placeholder.
     */
    protected function remember(string $key, int $ttl, callable $callback): mixed
    {
        $key = 'filami:'.$key;
        $cache = $this->cache();
        $cached = $cache->get($key);

        if ($cached === self::UNREACHABLE) {
            throw new ConnectionException('Umami was unreachable moments ago (cached failure).');
        }

        if ($cached !== null) {
            return $cached;
        }

        try {
            $value = $callback();
        } catch (Throwable $exception) {
            $cache->put($key, self::UNREACHABLE, $this->failureTtl);

            throw $exception;
        }

        $cache->put($key, $value, $ttl);

        return $value;
    }

    protected function cache(): Repository
    {
        return Cache::store($this->cacheStore);
    }
}

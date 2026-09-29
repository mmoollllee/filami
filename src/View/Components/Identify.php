<?php

namespace Mmoollllee\Filami\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Mmoollllee\Filami\Filami;

/**
 * Names the visitor to Umami: a distinct id — a pseudonym of the app's
 * choosing, under which Umami keeps a visitor's sessions together across
 * devices — and session data, the properties the dashboard breaks sessions
 * down by (a customer, a plan, a role).
 *
 * Drop it after <x-filami::tracking />, with the same :for or website-id. It
 * renders under the same conditions, and not at all without an id or data, so
 * a layout can pass null for a guest.
 *
 * The tracker loads deferred. The call therefore waits for DOMContentLoaded:
 * an ungated tracker is always there by then and has not sent its first
 * pageview yet — it does so once the document is complete — so that pageview
 * carries the id. A tracker behind a consent gate may come later; the call
 * keeps looking for it for ten seconds, and what was sent before goes without
 * the id.
 *
 * Umami keeps these values beside every pageview of the session, readable in
 * its dashboard: a name or an address does not belong here.
 */
class Identify extends Component
{
    /** How often, and how long, a late tracker is looked for (ms). */
    public const RETRY_INTERVAL_MS = 200;

    public const RETRY_ATTEMPTS = 50;

    // Protected for the same reason as on Tracking: public properties would
    // leak into the view and shadow the normalized values passed to it.
    public function __construct(
        protected mixed $for = null,
        protected ?string $websiteId = null,
        protected ?string $distinctId = null,
        protected ?array $sessionData = null,
    ) {}

    public function shouldRender(): bool
    {
        return Filami::tracks($this->for, $this->websiteId)
            && (filled($this->distinctId) || $this->normalizedSessionData() !== []);
    }

    public function render(): View
    {
        return view('filami::components.identify', [
            'distinctId' => filled($this->distinctId) ? $this->distinctId : null,
            'sessionData' => $this->normalizedSessionData(),
            'retryInterval' => self::RETRY_INTERVAL_MS,
            'retryAttempts' => self::RETRY_ATTEMPTS,
        ]);
    }

    /**
     * Named scalar values only: Umami keeps session data flat, and a list
     * would encode as a JSON array, which it rejects.
     *
     * @return array<string, string|int|float|bool>
     */
    protected function normalizedSessionData(): array
    {
        return array_filter(
            $this->sessionData ?? [],
            fn (mixed $value, mixed $key): bool => is_string($key) && is_scalar($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}

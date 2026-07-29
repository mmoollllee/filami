<?php

namespace Mmoollllee\Filami\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;
use Mmoollllee\Filami\Filami;

/**
 * Renders the Umami tracker snippet (dns-prefetch + preconnect + script tag).
 *
 * Website id resolution: the explicit website-id prop wins; otherwise a named
 * :for model decides on its own (no fallback, so an unprovisioned tenant stays
 * untracked instead of borrowing another site's id); only without any model
 * does the static filami.website_id apply. Renders nothing when disabled,
 * without an id, or outside the allowed environments — safe to drop into any
 * layout. Extra attributes are forwarded to the tracker tag (e.g. data-domains,
 * data-tag, fetchpriority); the recorder receives only the two it reads,
 * data-website-id and data-host-url.
 *
 * Session replay and heatmaps need Umami's separate recorder script next to
 * the tracker; pass recorder / :recorder="false" to override what the model
 * says.
 */
class Tracking extends Component
{
    // Protected on purpose: public properties would leak into the view and
    // shadow the computed $websiteId passed to it. Blade hydrates constructor
    // parameters regardless of visibility.
    public function __construct(
        protected mixed $for = null,
        protected ?string $websiteId = null,
        // mixed, not ?bool: an unbound recorder="false" would coerce to true
        // under PHP's non-strict typing and switch recording ON — the wrong
        // direction to fail for a privacy feature. Normalized below.
        protected mixed $recorder = null,
    ) {}

    public function shouldRender(): bool
    {
        return filled($this->websiteId)
            ? Filami::enabled($this->for) && Filami::environmentAllowed()
            : Filami::tracks($this->for);
    }

    public function render(): View
    {
        $url = (string) Filami::url($this->for);
        // One resolution: the attribute bag and the "is it gated" flag below
        // must always agree, and each call re-reads config and re-validates.
        $trackingConsent = $this->safeCategory(Filami::trackingConsent());

        return view('filami::components.tracking', [
            'websiteId' => $this->resolvedWebsiteId(),
            'src' => $this->scriptUrl($url, 'script_name', 'script.js'),
            'recorderSrc' => $this->shouldRecord()
                ? $this->scriptUrl($url, 'recorder_script', 'recorder.js')
                : null,
            // Ready-made attribute bags: empty when ungated, so the template
            // needs no conditional and values stay escaped by the framework.
            'consentAttributes' => $this->consentBag($trackingConsent),
            'recorderConsentAttributes' => $this->consentBag(Filami::recorderConsent()),
            'gated' => $trackingConsent !== null,
            'origin' => $url,
            'host' => parse_url($url, PHP_URL_HOST),
        ]);
    }

    /** Named apart from Filami::recordsSessions(): this one honours the prop. */
    protected function shouldRecord(): bool
    {
        return $this->recorder === null
            ? Filami::recordsSessions($this->for)
            : filter_var($this->recorder, FILTER_VALIDATE_BOOL);
    }

    /** The inert-script markers for a category, or an empty bag when ungated. */
    protected function consentBag(?string $category): ComponentAttributeBag
    {
        $category = $this->safeCategory($category);

        return new ComponentAttributeBag($category === null ? [] : [
            'type' => 'text/plain',
            $this->consentAttribute() => $category,
        ]);
    }

    /**
     * The marker attribute name, restricted to what an HTML attribute name may
     * contain. A blank value would render `="analytics"` — a gate no runtime
     * can open — and a value with spaces would smuggle a second attribute
     * (say onload=…) that the consent runtime then copies onto the live tag.
     */
    protected function consentAttribute(): string
    {
        $attribute = config('filami.tracking.consent.attribute', 'data-consent');

        return is_string($attribute) && preg_match('/^[a-z][a-z0-9-]*$/i', $attribute)
            ? $attribute
            : 'data-consent';
    }

    /**
     * Consent categories end up inside a CSS attribute selector that the
     * consent runtime builds by string concatenation. A quote there makes
     * querySelectorAll throw, which aborts the runtime before it unblocks
     * anything — killing every other consent-gated element on the page too.
     */
    protected function safeCategory(?string $category): ?string
    {
        return $category !== null && preg_match('/^[\w.:-]+$/', $category)
            ? $category
            : null;
    }

    protected function scriptUrl(string $url, string $configKey, string $default): string
    {
        return $url.'/'.ltrim((string) config("filami.tracking.{$configKey}", $default), '/');
    }

    protected function resolvedWebsiteId(): ?string
    {
        return filled($this->websiteId)
            ? $this->websiteId
            : Filami::websiteIdFor($this->for);
    }
}

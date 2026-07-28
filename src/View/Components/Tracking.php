<?php

namespace Mmoollllee\Filami\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Mmoollllee\Filami\Filami;

/**
 * Renders the Umami tracker snippet (dns-prefetch + preconnect + script tag).
 *
 * Website id resolution: the explicit website-id prop wins; otherwise a named
 * :for model decides on its own (no fallback, so an unprovisioned tenant stays
 * untracked instead of borrowing another site's id); only without any model
 * does the static filami.website_id apply. Renders nothing when disabled,
 * without an id, or outside the allowed environments — safe to drop into any
 * layout. Extra attributes are forwarded to the script tag (e.g. data-domains,
 * data-tag, fetchpriority).
 */
class Tracking extends Component
{
    // Protected on purpose: public properties would leak into the view and
    // shadow the computed $websiteId passed to it. Blade hydrates constructor
    // parameters regardless of visibility.
    public function __construct(
        protected mixed $for = null,
        protected ?string $websiteId = null,
    ) {}

    public function shouldRender(): bool
    {
        return filled($this->websiteId)
            ? Filami::enabled() && Filami::environmentAllowed()
            : Filami::tracks($this->for);
    }

    public function render(): View
    {
        $url = (string) Filami::url();

        return view('filami::components.tracking', [
            'websiteId' => $this->resolvedWebsiteId(),
            'src' => $url.'/'.ltrim((string) config('filami.tracking.script_name', 'script.js'), '/'),
            'origin' => $url,
            'host' => parse_url($url, PHP_URL_HOST),
        ]);
    }

    protected function resolvedWebsiteId(): ?string
    {
        return filled($this->websiteId)
            ? $this->websiteId
            : Filami::websiteIdFor($this->for);
    }
}

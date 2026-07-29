<?php

namespace Mmoollllee\Filami\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Mmoollllee\Filami\Filami;

/**
 * Custom-event plumbing for the tracker: a `window.filami.track()` helper, a
 * `filami-track` browser-event bridge (so Livewire and Alpine can report events
 * without knowing about Umami), and optional click tracking for tel:/mailto:
 * links.
 *
 * Drop it after <x-filami::tracking />. It renders under exactly the same
 * conditions, and everything it installs degrades to a no-op while
 * `window.umami` is absent — which is also what makes it correct behind a
 * consent gate: no tracker, no events, without a second gate to keep in sync.
 *
 * Umami's own `data-umami-event` attribute still works and takes precedence;
 * the link tracking here exists for the markup you do not control, i.e. phone
 * and mail links an editor typed into rich text.
 */
class Events extends Component
{
    public function __construct(
        protected mixed $for = null,
        // mixed, not ?bool: an unbound links="false" would coerce to true under
        // PHP's non-strict typing. Same reasoning as Tracking::$recorder.
        protected mixed $links = null,
        protected mixed $forms = null,
        protected mixed $outbound = null,
    ) {}

    public function shouldRender(): bool
    {
        return Filami::tracks($this->for);
    }

    public function render(): View
    {
        return view('filami::components.events', [
            'links' => $this->switch($this->links, 'links'),
            'forms' => $this->switch($this->forms, 'forms'),
            'outbound' => $this->switch($this->outbound, 'outbound'),
            'phoneEvent' => Filami::phoneEvent(),
            'emailEvent' => Filami::emailEvent(),
            'outboundEvent' => Filami::outboundEvent(),
            'internalDomains' => Filami::internalDomains(),
        ]);
    }

    /** Prop wins over config; an unset prop is null, not false. */
    protected function switch(mixed $prop, string $key): bool
    {
        return $prop === null
            ? (bool) config("filami.events.{$key}", true)
            : filter_var($prop, FILTER_VALIDATE_BOOL);
    }
}

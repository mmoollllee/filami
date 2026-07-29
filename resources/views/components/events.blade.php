{{-- Installed once per page, guarded so a second <x-filami::events /> is a
     no-op rather than a second set of click listeners. --}}
<script {{ $attributes }}>
(function () {
    if (window.filami) {
        return;
    }

    /**
     * Every event goes through here. Umami loads deferred — and behind a
     * consent gate it may never load at all — so a missing window.umami is the
     * normal case, not an error: drop the event and stay quiet.
     */
    var track = function (name, data) {
        if (! name || ! window.umami || typeof window.umami.track !== 'function') {
            return;
        }

        try {
            // Umami validates event data as an object. PHP's array_filter on a
            // fully-filtered payload yields [], which json_encodes to a JSON
            // ARRAY and is rejected — and `data || {}` cannot catch it because
            // an empty array is truthy. Normalise at the boundary so every
            // caller, PHP or JS, is safe.
            window.umami.track(name, (data && ! Array.isArray(data)) ? data : {});
        } catch (error) {
            // Analytics must never break the page it measures.
        }
    };

    window.filami = { track: track };

    // Bridge for Livewire ($this->dispatch('filami-track', name: …, data: […]))
    // and plain JS. Keeps the app side free of any Umami API.
    window.addEventListener('filami-track', function (event) {
        var detail = event.detail || {};

        track(detail.name, detail.data);
    });

@if ($forms)
    // Forms report a "<name>-start" the first time someone touches them, so a
    // "<name>-submit" can be read as a completion rate rather than a bare
    // count. Tracked as a set of names rather than a flag on the element,
    // because Livewire re-renders the form on every keystroke and DOM morphing
    // does not reliably carry attributes across; a flag there would report a
    // start per field instead of per form.
    //
    // Object.create(null), not {}: a form named "constructor" or "toString"
    // would read back as already-started off Object.prototype and never report
    // a start at all, while its -submit kept firing.
    var startedForms = Object.create(null);

    // A page view, not a JS context: under wire:navigate the document-level
    // listener survives the body swap, so without this the set would carry
    // over and only the first page of an SPA session would report a start.
    document.addEventListener('livewire:navigated', function () {
        startedForms = Object.create(null);
    });

    document.addEventListener('focusin', function (event) {
        var target = event.target;

        if (! target || typeof target.closest !== 'function') {
            return;
        }

        var form = target.closest('form[data-filami-form]');
        var name = form && form.getAttribute('data-filami-form');

        if (! name || startedForms[name]) {
            return;
        }

        startedForms[name] = true;

        track(name + '-start', {});
    });
@endif

@if ($links || $outbound)
    // Hosts that count as "us". The current one is added at runtime, so a site
    // served from several domains only needs to list the others.
    var internalHosts = @json($internalDomains);

    // Delegated on document, so it covers links that arrive later — rich text
    // rendered by Livewire, wire:navigate page swaps, markup an editor typed.
    // Capture phase: a click that leaves the page (tel:/mailto: handing off to
    // another app, an outbound navigation) can outrun a bubbling listener.
    document.addEventListener('click', function (event) {
        var target = event.target;

        if (! target || typeof target.closest !== 'function') {
            return;
        }

        var link = target.closest(@json($links ? 'a[href], a[data-filami-event]' : 'a[href]'));

        // data-umami-event means Umami is already tracking this click itself;
        // data-umami-ignore is the opt-out for links that should not count.
        if (! link || link.hasAttribute('data-umami-event') || link.hasAttribute('data-umami-ignore')) {
            return;
        }

        var page = window.location.pathname;

@if ($links)
        // Address obfuscated against scrapers (href="#" plus an encrypted
        // token), so it cannot be recognised by href and is labelled
        // server-side instead.
        //
        // Deliberately NOT Umami's own data-umami-event for this: Umami's
        // tracker attaches a capture-phase handler to [data-umami-event]
        // anchors that calls preventDefault() and then forces location.href
        // back to the element's own href — which on an href="#" link races the
        // decrypting handler that is supposed to open the mailto:.
        var labelled = link.getAttribute('data-filami-event');

        if (labelled) {
            // No target: the address is hidden on purpose, and copying it into
            // the event payload would put it back into the page in clear text.
            track(labelled, { page: page });

            return;
        }
@endif

        var href = link.getAttribute('href') || '';

@if ($links)
        if (href.indexOf('tel:') === 0 || href.indexOf('mailto:') === 0) {
            track(href.indexOf('tel:') === 0 ? @json($phoneEvent) : @json($emailEvent), {
                // The site's own number/address, not the visitor's — and
                // without any ?subject= payload, which can carry page text.
                target: href.slice(href.indexOf(':') + 1).split('?')[0],
                page: page,
            });

            return;
        }
@endif

@if ($outbound)
        // link.hostname/protocol are the RESOLVED values, so relative hrefs and
        // "#anchor" report the current host and fall out here for free, and
        // "javascript:" is excluded by the protocol check rather than by
        // pattern-matching the href.
        var isWeb = link.protocol === 'http:' || link.protocol === 'https:';
        var isInternal = link.hostname === window.location.hostname
            || internalHosts.indexOf(link.hostname) !== -1;

        if (isWeb && ! isInternal && link.hostname !== '') {
            track(@json($outboundEvent), {
                // Host, not the full URL: it is what the question "where do we
                // send people" is asked about, and it keeps the breakdown to
                // one row per destination instead of one per link. The path
                // follows without its query, which often carries campaign ids.
                target: link.hostname,
                path: link.pathname,
                page: page,
            });
        }
@endif
    }, true);
@endif
})();
</script>

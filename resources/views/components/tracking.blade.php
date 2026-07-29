{{-- Umami counts pageviews without cookies and stores nothing on the device;
     visitors opt out via localStorage umami.disabled. A consent category is
     therefore optional here — set filami.tracking.consent.tracking to require
     one, which emits the tag inert until the runtime unblocks it. --}}
@unless ($gated)
    {{-- Only when the tracker loads right away. Behind a consent gate these
         would complete DNS, TCP and TLS to the analytics host at parse time,
         handing it the visitor's IP before any opt-in — and the socket idles
         out long before a banner click, so they would buy nothing either. --}}
    <link rel="dns-prefetch" href="//{{ $host }}">
    <link rel="preconnect" href="{{ $origin }}" crossorigin>
@endunless
<script {{ $consentAttributes }} defer src="{{ $src }}"
    data-website-id="{{ $websiteId }}" {{ $attributes }}></script>
@if ($recorderSrc)
    {{-- Session replay + heatmaps: a second script sharing the website id. It
         reads only data-website-id and data-host-url, so the remaining
         attributes are not repeated. Note data-domains is NOT inherited as an
         attribute — the recorder waits for the tracker's session, so a domain
         mismatch does silence recordings, but only after it has already
         fetched rrweb and called the Umami API from the excluded host. --}}
    <script {{ $recorderConsentAttributes }} defer src="{{ $recorderSrc }}"
        data-website-id="{{ $websiteId }}" {{ $attributes->only('data-host-url') }}></script>
@endif

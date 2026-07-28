{{-- Umami is cookie-less: no consent gate required, visitors can opt out via localStorage umami.disabled. --}}
<link rel="dns-prefetch" href="//{{ $host }}">
<link rel="preconnect" href="{{ $origin }}" crossorigin>
<script defer src="{{ $src }}" data-website-id="{{ $websiteId }}" {{ $attributes }}></script>

{{-- Umami's identify, once the deferred tracker is there — see
     Mmoollllee\Filami\View\Components\Identify for the timing. --}}
<script {{ $attributes }}>
(function () {
    var distinctId = @json($distinctId);
    var sessionData = @json((object) $sessionData);
    var attempts = 0;

    var identify = function () {
        if (! window.umami || typeof window.umami.identify !== 'function') {
            return false;
        }

        try {
            if (distinctId) {
                window.umami.identify(distinctId, sessionData);
            } else {
                window.umami.identify(sessionData);
            }
        } catch (error) {
            // Analytics must never break the page it measures.
        }

        return true;
    };

    var start = function () {
        if (identify()) {
            return;
        }

        // A tracker behind a consent gate arrives once the visitor agrees.
        var timer = setInterval(function () {
            if (identify() || ++attempts >= @json($retryAttempts)) {
                clearInterval(timer);
            }
        }, @json($retryInterval));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>

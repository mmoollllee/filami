{{-- Share of the busiest path in the window, as a bar. Decorative: the exact
     number sits in the column beside it, so this carries no accessible name of
     its own and is hidden from assistive tech rather than read out twice. --}}
@php
    $share = max(0, min(100, (float) $getState()));
@endphp

<div class="h-1.5 w-16 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" aria-hidden="true">
    <div class="h-full rounded-full bg-primary-500 dark:bg-primary-400" style="width: {{ $share }}%"></div>
</div>

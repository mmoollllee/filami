{{-- Values recorded with one event, grouped by property. Opened on demand:
     each property costs its own API call, so this never runs during a
     dashboard render. Shares are computed in PHP (UmamiTableWidget::withShare)
     so this view cannot drift from the bars in the tables behind it. --}}
<div class="grid gap-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $period }}</p>

    @forelse ($properties as $propertyName => $values)
        <div class="grid gap-2">
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $propertyName }}</h4>

            <ul class="grid gap-1">
                @foreach ($values as $value)
                    <li class="relative overflow-hidden rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                        <div
                            class="absolute inset-y-0 start-0 bg-primary-500/10 dark:bg-primary-400/10"
                            style="width: {{ $value['share'] }}%"
                            aria-hidden="true"
                        ></div>
                        <div class="relative flex items-center justify-between gap-3 text-sm">
                            <span class="truncate text-gray-950 dark:text-white">{{ $value['value'] }}</span>
                            <span class="shrink-0 text-gray-500 tabular-nums dark:text-gray-400">{{ $value['total'] }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filami::widgets.no_event_properties') }}</p>
    @endforelse
</div>

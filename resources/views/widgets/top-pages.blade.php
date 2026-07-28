<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('filami::widgets.top_pages') }}</x-slot>
        <x-slot name="description">{{ __('filami::widgets.period_days', ['days' => $days]) }}</x-slot>

        @if ($pages === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filami::widgets.unreachable') }}</p>
        @elseif ($pages === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filami::widgets.no_data') }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($pages as $page)
                    <li class="relative overflow-hidden rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                        <div
                            class="absolute inset-y-0 start-0 bg-primary-500/10 dark:bg-primary-400/10"
                            style="width: {{ round((($page['y'] ?? 0) / $max) * 100) }}%"
                        ></div>
                        <div class="relative flex items-center justify-between gap-3 text-sm">
                            <span class="truncate font-medium text-gray-950 dark:text-white">
                                {{ ($page['x'] ?? '') !== '' ? $page['x'] : '/' }}
                            </span>
                            <span class="shrink-0 text-gray-500 tabular-nums dark:text-gray-400">
                                {{ $page['y'] ?? 0 }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($umamiUrl)
            <a
                href="{{ $umamiUrl }}"
                target="_blank"
                rel="noopener"
                class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ __('filami::widgets.open_in_umami') }}
                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4" />
            </a>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

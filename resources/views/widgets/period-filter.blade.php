{{-- The one reporting-window control for the whole analytics section. Lives in
     the stats widget's section header; every other Umami widget follows it via
     the filami-period-updated event, so there is nothing to keep in sync here. --}}
<x-filament::input.wrapper
    inline-prefix
    wire:target="umamiPeriod"
    class="fi-filami-period-filter"
>
    <x-filament::input.select
        :aria-label="__('filami::widgets.period_label')"
        inline-prefix
        wire:model.live="umamiPeriod"
    >
        @foreach ($periodOptions as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </x-filament::input.select>
</x-filament::input.wrapper>

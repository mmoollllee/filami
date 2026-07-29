<?php

namespace Mmoollllee\Filami\Filament\Widgets\Concerns;

use Filament\Facades\Filament;
use Livewire\Attributes\On;
use Mmoollllee\Filami\Filami;
use Mmoollllee\Filami\Support\UmamiPeriod;
use Mmoollllee\Filami\UmamiClient;
use Throwable;

/**
 * Shared plumbing for the Umami widgets: the visibility rule, the reporting
 * window and the client. Kept in one place so a change to "when may this be
 * shown" cannot land on two of the three widgets.
 *
 * Everything is resolved against the current tenant, which may carry its own
 * Umami endpoint — the widgets then read from that instance.
 *
 * The reporting window is shared state: only the stats overview renders the
 * select (one control for the whole section), and it broadcasts every change
 * so the chart and the top-pages table re-render against the same window. The
 * choice is remembered per panel in the session, so a reload — or a widget
 * mounting later than the others — starts out in sync rather than snapping
 * back to the default.
 */
trait InteractsWithUmami
{
    /**
     * Client-writable by nature (public Livewire property), which is fine:
     * every read goes through UmamiPeriod::fromKey(), so an unknown value
     * falls back to the default instead of reaching the API.
     */
    public ?string $umamiPeriod = null;

    /**
     * boot, NOT booted: Livewire runs booted() after mount(), and both
     * ChartWidget::mount() and the table builders read the window while
     * assembling their data. Restoring the remembered window there left the
     * chart querying the default seven days while labelling itself with the
     * remembered one — the cross-window misreading this whole mechanism
     * exists to prevent. boot() runs before mount() and before hydration, so
     * a snapshot value still wins on later requests.
     */
    public function bootInteractsWithUmami(): void
    {
        $this->umamiPeriod ??= session(
            static::umamiPeriodSessionKey(),
            UmamiPeriod::default()->value,
        );
    }

    public static function canView(): bool
    {
        return Filami::apiConfigured(static::umamiContext()) && filled(Filami::currentWebsiteId());
    }

    /** Livewire calls this when the select changes; the listeners below do not re-broadcast. */
    public function updatedUmamiPeriod(): void
    {
        $period = UmamiPeriod::fromKey($this->umamiPeriod);

        $this->umamiPeriod = $period->value;

        session()->put(static::umamiPeriodSessionKey(), $period->value);

        $this->dispatch('filami-period-updated', period: $period->value);
    }

    /**
     * Nullable and defaulted on purpose: Livewire event payloads are
     * client-controlled, so a bare `Livewire.dispatch('filami-period-updated')`
     * would otherwise be an ArgumentCountError rather than a no-op. Every
     * other client-facing entry point in this trait is total too.
     */
    #[On('filami-period-updated')]
    public function syncUmamiPeriod(mixed $period = null): void
    {
        $resolved = UmamiPeriod::fromKey(is_string($period) ? $period : null)->value;

        // Livewire has no self-exclusion on a global dispatch, so the widget
        // that changed the select also receives its own broadcast. Bailing on
        // an unchanged value saves it a second, redundant round of API calls.
        if ($resolved === $this->umamiPeriod) {
            return;
        }

        // Assigning a property server-side does not fire updatedUmamiPeriod(),
        // so this cannot bounce the event back and forth between the widgets.
        $this->umamiPeriod = $resolved;

        $this->afterUmamiPeriodChanged();
    }

    /** Hook for widgets with state that outlives a window change (e.g. a table page). */
    protected function afterUmamiPeriodChanged(): void {}

    protected function umamiPeriod(): UmamiPeriod
    {
        return UmamiPeriod::fromKey($this->umamiPeriod);
    }

    /**
     * Per panel, not per tenant: the window is a viewing preference, and
     * carrying it across a tenant switch is what a reader expects.
     */
    protected static function umamiPeriodSessionKey(): string
    {
        try {
            $panelId = Filament::getCurrentOrDefaultPanel()?->getId();
        } catch (Throwable) {
            $panelId = null;
        }

        return 'filami.period.'.($panelId ?? 'default');
    }

    /** The model whose Umami endpoint applies here — the tenant, if any. */
    protected static function umamiContext(): mixed
    {
        try {
            return Filament::getTenant();
        } catch (Throwable) {
            return null;
        }
    }

    protected function umamiWebsiteId(): ?string
    {
        return Filami::currentWebsiteId();
    }

    protected function umami(): UmamiClient
    {
        return Filami::client(static::umamiContext());
    }

    protected function umamiDashboardUrl(?string $websiteId): ?string
    {
        return Filami::websiteDashboardUrl($websiteId, static::umamiContext());
    }
}

<?php

/**
 * The events widget lists custom events for the shared window and breaks one
 * down by its recorded properties on demand.
 *
 * The event-data endpoints are the least stable corner of the Umami API and
 * are missing entirely on older builds, so what is checked hardest here is
 * that their absence degrades quietly: the table still lists its events, the
 * breakdown action simply does not appear.
 */

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mmoollllee\Filami\Filament\Widgets\UmamiEventsWidget;

beforeEach(function () {
    configureUmami(['website_id' => 'w-1']);
});

it('lists events with their counts', function () {
    fakeUmamiMetrics([
        ['x' => 'contact-form-submit', 'y' => 34],
        ['x' => 'phone-click', 'y' => 12],
    ]);

    $widget = new UmamiEventsWidget;
    $events = widgetCall($widget, 'rows');

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBe(['event' => 'contact-form-submit', 'count' => 34, 'share' => 100.0])
        ->and($events[1]['share'])->toBe(35.3);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'type=event'));
});

it('breaks an event down by the properties it carries', function () {
    fakeUmamiMetrics(
        [['x' => 'contact-form-submit', 'y' => 34]],
        [
            ['eventName' => 'contact-form-submit', 'propertyName' => 'machine', 'total' => 12],
            // A property of a different event must not leak into this breakdown.
            ['eventName' => 'phone-click', 'propertyName' => 'target', 'total' => 5],
        ],
        [['value' => 'Teleskopbühne 22m', 'total' => 12]],
    );

    $widget = new UmamiEventsWidget;

    expect(widgetCall($widget, 'propertyNamesFor', 'contact-form-submit'))->toBe(['machine'])
        ->and(widgetCall($widget, 'breakdown', 'contact-form-submit'))
        ->toBe(['machine' => [['value' => 'Teleskopbühne 22m', 'total' => 12, 'share' => 100.0]]]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'event-data/values')
        && str_contains($request->url(), 'propertyName=machine'));
});

it('survives an umami without the event-data endpoints', function () {
    Http::fake([
        '*/api/auth/login' => Http::response(['token' => 't']),
        '*/api/websites/w-1/metrics*' => Http::response([['x' => 'phone-click', 'y' => 3]]),
        // Older builds have no event-data routes at all.
        '*/api/websites/w-1/event-data/*' => Http::response(['message' => 'Not found'], 404),
    ]);

    $widget = new UmamiEventsWidget;

    expect(widgetCall($widget, 'rows'))->toHaveCount(1)
        ->and(widgetCall($widget, 'propertyNamesFor', 'phone-click'))->toBe([])
        ->and(widgetCall($widget, 'breakdown', 'phone-click'))->toBe([]);
});

it('ignores rows that do not carry the promised keys', function () {
    fakeUmamiMetrics(
        [['x' => 'phone-click', 'y' => 3]],
        // A build that renamed the fields must yield nothing, not half-rows.
        [['event' => 'phone-click', 'property' => 'target']],
    );

    expect(widgetCall(new UmamiEventsWidget, 'propertyNamesFor', 'phone-click'))->toBe([]);
});

it('renders the events table and offers a breakdown', function () {
    fakeUmamiMetrics(
        [['x' => 'contact-form-submit', 'y' => 34]],
        [['eventName' => 'contact-form-submit', 'propertyName' => 'machine', 'total' => 12]],
        [['value' => 'Teleskopbühne 22m', 'total' => 12]],
    );

    Livewire::test(UmamiEventsWidget::class)
        ->assertOk()
        ->assertSee('contact-form-submit')
        ->assertSee(__('filami::widgets.breakdown'));
});

it('hides the breakdown for an event without properties', function () {
    fakeUmamiMetrics([['x' => 'phone-click', 'y' => 3]]);

    Livewire::test(UmamiEventsWidget::class)
        ->assertOk()
        ->assertSee('phone-click')
        ->assertDontSee(__('filami::widgets.breakdown'));
});

it('points at the tracking snippet when nothing was recorded', function () {
    fakeUmamiMetrics([]);

    Livewire::test(UmamiEventsWidget::class)
        ->assertOk()
        ->assertSee(__('filami::widgets.no_events'));
});

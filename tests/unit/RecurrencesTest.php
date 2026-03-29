<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Unit;

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FPEvents;
use WP_Post;

uses(\Yoast\WPTestUtils\WPIntegration\TestCase::class);

/** @return array{0: WP_Post, 1: WP_Post} */
function createTranslatedEvent(): array
{
    $languageTermIDs = array_combine(
        pll_languages_list(['fields' => 'slug']),
        pll_languages_list(['fields' => 'term_id']),
    );

    $de = createEvent('next saturday 10:00', [
        'post_title' => 'Event (de)',
        'tax_input' => ['language' => $languageTermIDs['de']],
    ]);

    $fr = createEvent('next saturday 10:00', [
        'post_title' => 'Event (fr)',
        'tax_input' => ['language' => $languageTermIDs['fr']],
    ]);

    pll_set_post_language($de->ID, 'de');
    pll_set_post_language($fr->ID, 'fr');

    $translations = pll_save_post_translations([
        'de' => $de->ID,
        'fr' => $fr->ID,
    ]);

    /** post IDs should be constant between tests */
    expect($translations)->toHaveKey('de');
    expect($translations)->toHaveKey('fr');

    return [$de, $fr];
}

test('has polylang languages active', function () {
    expect(pll_languages_list())->toEqual(['de', 'fr']);
});

test('has required plugins', function () {
    expect(function_exists('fpe'))->toBeTrue();
    expect(defined('ACF'))->toBeTrue();
    expect(function_exists('pll_get_post_language'))->toBeTrue();
});

test('creates recurrences', function () {
    $furtherDates = [
        '+30 days 12:00:00',
        '+40 days 13:00:00',
        '+60 days 16:00:00',
    ];
    [$event, $eventFR] = createTranslatedEvent();
    fpe()->setFurtherDates($event, $furtherDates);

    $recurrences = fpe()->recurrences->getRecurrences($event->ID);
    expect($furtherDates)->toHaveCount(count($recurrences));

    // Only a simple check for french :)
    expect(fpe()->recurrences->getRecurrences($eventFR->ID))->toHaveCount(3);

    /**
     * For each further date, a matching
     * recurrence should have been created, in both languages
     */
    collect($furtherDates)
        ->each(function ($furtherDate, $index) use ($recurrences, $event) {
            $r = get_post($recurrences[$index]);
            expect($r->post_parent)->toBe($event->ID);
            expect(\date(FPEvents::MYSQL_DATE_TIME_FORMAT, \strtotime($furtherDate)))
                ->toBe(get_post_meta($r->ID, EventFields::DATE_AND_TIME, true));
        });
});

test('does not create recurrences for events in the past', function () {
    [$event] = createTranslatedEvent();

    fpe()->setFurtherDates($event, [
        'yesterday',
        '+60 days 18:00:00',
        '+60 days 19:00:00',
    ]);

    $recurrences = fpe()->recurrences->getRecurrences($event->ID);
    expect(count($recurrences))->toBe(2);
});

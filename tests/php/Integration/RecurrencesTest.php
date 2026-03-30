<?php

declare(strict_types=1);

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FPEvents;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertTrue;

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
    assertArrayHasKey('de', $translations);
    assertArrayHasKey('fr', $translations);

    return [$de, $fr];
}

test('has polylang languages active', function () {
    assertEquals(['de', 'fr'], pll_languages_list());
});

test('has required plugins', function () {
    assertTrue(function_exists('fpe'));
    assertTrue(defined('ACF'));
    assertTrue(function_exists('pll_get_post_language'));
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
    assertCount(3, fpe()->recurrences->getRecurrences($eventFR->ID));

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

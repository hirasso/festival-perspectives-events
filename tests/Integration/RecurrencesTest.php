<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Integration;

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FPEvents;
use Hirasso\WP\FPEvents\PostTypes;
use WP_Post;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class RecurrencesTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_has_polylang_languages_active()
    {
        $this->assertSame(pll_languages_list(), ['de', 'fr']);
    }

    /** @return array{0: WP_Post, 1: WP_Post} */
    private function createEvent(array $furtherDates = []): array
    {
        $location = $this->factory()->post->create_and_get([
            'post_type' => PostTypes::LOCATION,
            'meta_input' => [
                'acfe_location_address' => "Test Street 1\n12345 Test City",
                'acfe_location_area' => 'Test Area',
            ],
        ]);

        $eventArgs = [
            'post_status' => 'publish',
            'post_type' => PostTypes::EVENT,
            'meta_input' => [
                EventFields::DATE_AND_TIME => \date(FPEvents::MYSQL_DATE_TIME_FORMAT, \strtotime('next saturday 10:00')),
                EventFields::LOCATION_ID => $location->ID,
            ],
        ];

        $languageTermIDs = array_combine(
            pll_languages_list(['fields' => 'slug']),
            pll_languages_list(['fields' => 'term_id']),
        );

        $de = $this->factory()->post->create_and_get(
            array_replace_recursive($eventArgs, [
                'post_title' => 'Event (de)',
                'tax_input' => ['language' => $languageTermIDs['de']],
            ]),
        );
        fp_events()->setFurtherDates($de, $furtherDates);

        $fr = $this->factory()->post->create_and_get(
            array_replace_recursive($eventArgs, [
                'post_title' => 'Event (fr)',
                'tax_input' => ['language' => $languageTermIDs['fr']],
            ]),
        );
        fp_events()->setFurtherDates($fr, $furtherDates);

        pll_set_post_language($de->ID, 'de');
        pll_set_post_language($fr->ID, 'fr');

        $translations = pll_save_post_translations([
            'de' => $de->ID,
            'fr' => $fr->ID,
        ]);

        /** post IDs should be constant between tests */
        $this->assertArrayHasKey('de', $translations);
        $this->assertArrayHasKey('fr', $translations);

        return [$de, $fr];
    }

    public function test_has_required_plugins(): void
    {
        $this->assertTrue(function_exists('fp_events'));
        $this->assertTrue(defined('ACF'));
        $this->assertTrue(function_exists('pll_get_post_language'));
    }

    public function test_creates_recurrences(): void
    {
        $furtherDates = [
            '+30 days 12:00:00',
            '+40 days 13:00:00',
            '+60 days 16:00:00',
        ];
        [$event, $eventFR] = $this->createEvent($furtherDates);

        $recurrences = fp_events()->recurrences->getRecurrences($event->ID);
        $this->assertSame(count($recurrences), 3);

        /**
         * For each further date, a matching
         * recurrence should have been created, in both languages
         */
        collect($furtherDates)
            ->each(function ($furtherDate, $index) use ($recurrences, $event) {
                $r = get_post($recurrences[$index]);
                $this->assertSame($r->post_parent, $event->ID);
                $this->assertSame(
                    \date(FPEvents::MYSQL_DATE_TIME_FORMAT, \strtotime($furtherDate)),
                    get_post_meta($r->ID, EventFields::DATE_AND_TIME, true),
                );
            });
    }

    public function test_does_not_create_recurrences_for_events_in_the_past(): void
    {
        [$event] = $this->createEvent([
            'yesterday',
            '+60 days 18:00:00',
            '+60 days 19:00:00',
        ]);

        $recurrences = fp_events()->recurrences->getRecurrences($event->ID);

        $this->assertSame(count($recurrences), 2);
    }
}

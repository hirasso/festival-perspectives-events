<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Integration;

use Hirasso\WP\FPEvents\Core;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FieldGroups\Fields;
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
    private function createEvent(array $eventArgs = []): array
    {
        $location = $this->factory()->post->create_and_get([
            'post_type' => PostTypes::LOCATION,
            'meta_input' => [
                'acfe_location_address' => "Test Street 1\n12345 Test City",
                'acfe_location_area' => 'Test Area',
            ],
        ]);

        $eventArgs = array_replace_recursive([
            'post_status' => 'publish',
            'post_type' => PostTypes::EVENT,
            'meta_input' => [
                EventFields::DATE_AND_TIME => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('next saturday 10:00')),
                EventFields::LOCATION_ID => $location->ID,
            ],
        ], $eventArgs);

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
        $fr = $this->factory()->post->create_and_get(
            array_replace_recursive($eventArgs, [
                'post_title' => 'Event (fr)',
                'tax_input' => ['language' => $languageTermIDs['fr']],
            ]),
        );

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

    /**
     * @return list<WP_Post>
     */
    private function getAllRecurrences(?string $lang = null): array
    {
        return get_posts([
            'lang' => $lang,
            'post_type' => PostTypes::RECURRENCE,
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);
    }

    public function test_has_required_plugins(): void
    {
        $this->assertTrue(function_exists('fp_events'));
        $this->assertTrue(defined('ACF'));
        $this->assertTrue(function_exists('pll_get_post_language'));
    }

    public function test_creates_recurrences(): void
    {
        $args = [
            'meta_input' => [
                Fields::key(EventFields::FURTHER_DATES) => fp_events()->core->getFurtherDatesRows([
                    '+30 days 19:00:00',
                    '+60 days 18:00:00',
                    '+60 days 19:00:00',
                ]),
            ],
        ];
        [$event, $eventFR] = $this->createEvent($args);

        $furtherDates = fp_events()->core->setFurtherDates($event, [
            '+30 days 19:00:00',
            '+60 days 18:00:00',
            '+60 days 19:00:00',
        ]);

        $recurrences = $this->getAllRecurrences();
        $this->assertSame(count($recurrences), 6);
        // $recurrencesFR = collect($recurrences)
        //     ->filter(fn($p) => pll_get_post_language($p->ID) === 'fr')
        //     ->dd();
        // $recurrencesFR = $this->getAllRecurrences('fr');
        // $this->assertSame(count($recurrencesDE), 3);
        // $this->assertSame(count($recurrencesFR), 3);

        /**
         * For eqach further date, a matching
         * recurrence should have been created, in both languages
         */
        collect($furtherDates)
            ->each(function ($furtherDate, $index) use ($recurrences, $event) {
                $r = $recurrences[$index];
                $this->assertSame($r->post_parent, $event->ID);
                $this->assertSame(
                    $furtherDate,
                    get_post_meta($r->ID, EventFields::DATE_AND_TIME, true),
                );
            });
    }

    public function test_does_not_create_recurrences_for_events_in_the_past(): void
    {
        $args = [
            'meta_input' => [
                Fields::key(EventFields::FURTHER_DATES) => fp_events()->core->getFurtherDatesRows([
                    'yesterday',
                    '+60 days 18:00:00',
                    '+60 days 19:00:00',
                ]),
            ],
        ];
        $this->createEvent($args);
        $recurrences = $this->getAllRecurrences();
        $this->assertSame(count($recurrences), 4);
    }
}

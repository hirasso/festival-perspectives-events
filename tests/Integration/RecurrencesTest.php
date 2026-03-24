<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Integration;

use Hirasso\WP\FPEvents\Core;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\PostTypes;
use WP_Post;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class RecurrencesTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->setupPolylangLanguages();
        fp_events();
    }

    /**
     * Set up Polylang for integration tests
     * - languages "de" (default) and "fr"
     */
    private function setupPolylangLanguages(): void
    {
        if (!empty(pll_languages_list())) {
            return;
        }

        PLL()->model->add_language([
            'name'       => 'Deutsch',
            'slug'       => 'de',
            'locale'     => 'de_DE',
            'rtl'        => false,
            'term_group' => 0,
        ]);

        PLL()->model->add_language([
            'name'       => 'Français',
            'slug'       => 'fr',
            'locale'     => 'fr_FR',
            'rtl'        => false,
            'term_group' => 1,
        ]);
    }

    public function test_has_polylang_languages_active()
    {
        $this->assertSame(pll_languages_list(), ['de', 'fr']);
    }

    private function createEvent(array $eventArgs = []): WP_Post
    {
        $location = $this->factory()->post->create_and_get([
            'post_type' => PostTypes::LOCATION,
            'meta_input' => [
                'acfe_location_address' => "Test Street 1\n12345 Test City",
                'acfe_location_area' => 'Test Area',
            ],
        ]);

        $eventArgs = array_replace_recursive([
            'post_type' => PostTypes::EVENT,
            'tax_input' => ['language' => 'en'], // no effect currently
            'meta_input' => [
                EventFields::DATE_AND_TIME => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('next saturday 10:00')),
                EventFields::LOCATION_ID => $location->ID,
            ],
        ], $eventArgs);

        return $this->factory()->post->create_and_get($eventArgs);
    }

    private function getAllRecurrences(): array
    {
        return get_posts([
            'post_type' => PostTypes::RECURRENCE,
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);
    }

    public function test_has_required_plugins(): void
    {
        $this->assertTrue(function_exists('fp_events'));
        $this->assertTrue(defined('ACF'));
        $this->assertTrue(defined('POLYLANG'));
    }

    public function test_creates_recurrences(): void
    {
        $event = $this->createEvent();

        $furtherDates = fp_events()->core->setFurtherDates($event, [
            '+30 days 19:00:00',
            '+60 days 18:00:00',
            '+60 days 19:00:00',
        ]);

        do_action('fp_events_create_recurrences', $event->ID);
        $recurrences = $this->getAllRecurrences();
        $this->assertSame(count($recurrences), 3);

        /**
         * For eqach further date, a matching
         * recurrence should have been created
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
        $event = $this->createEvent();

        fp_events()->core->setFurtherDates($event, [
            'yesterday',
            '+60 days 18:00:00',
            '+60 days 19:00:00',
        ]);

        do_action('fp_events_create_recurrences', $event->ID);
        $recurrences = $this->getAllRecurrences();
        $this->assertSame(count($recurrences), 2);
    }
}

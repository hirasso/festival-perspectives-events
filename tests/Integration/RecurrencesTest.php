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
        // $this->setupPolylangLanguages();
        fp_events();
    }

    private function setupPolylangLanguages(): void
    {
        require_once WP_PLUGIN_DIR . '/polylang/src/api.php';

        if (!empty(pll_languages_list())) {
            return;
        }

        PLL()->model->add_language([
            'name'       => 'English',
            'slug'       => 'en',
            'locale'     => 'en_US',
            'rtl'        => false,
            'term_group' => 0,
        ]);

        PLL()->model->add_language([
            'name'       => 'Deutsch',
            'slug'       => 'de',
            'locale'     => 'de_DE',
            'rtl'        => false,
            'term_group' => 1,
        ]);
    }

    private function createEvent(): WP_Post
    {
        $location = $this->factory()->post->create_and_get([
            'post_type' => PostTypes::LOCATION,
            'meta_input' => [
                'acfe_location_address' => "Test Street 1\n12345 Test City",
                'acfe_location_area' => 'Test Area',
            ],
        ]);

        return $this->factory()->post->create_and_get([
            'post_type' => PostTypes::EVENT,
            'tax_input' => ['language' => 'en'], // no effect currently
            'meta_input' => [
                EventFields::DATE_AND_TIME => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('next saturday 10:00')),
                EventFields::LOCATION_ID => $location->ID,
            ],
        ]);
    }

    private function getRecurrences(): array
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
        /**
         * Augment $_POST to test recurrency creation
         * The fact this needs to be hacked here points to a weakness in the
         * implementation. We should maybe look into update_post_meta or so.
         * OR we could add a new API function fp_events()->addRecurrence($eventId, $dateAndTime)...
         * not sure about this.
         */
        $_POST = [
            'acf' => [
                Fields::key(EventFields::FURTHER_DATES) => [
                    "row-0" => [Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME) => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('+30 days 19:00:00'))],
                    "row-1" => [Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME) => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('+60 days 18:00:00'))],
                    "row-2" => [Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME) => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('+60 days 19:00:00'))],
                ],
            ],
        ];

        $event = $this->createEvent();
        $recurrences = $this->getRecurrences();

        $this->assertSame(count($recurrences), 3);

        $furtherDates = collect($_POST['acf'][Fields::key(EventFields::FURTHER_DATES)])
            ->map(fn($row) => $row[Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME)])
            ->values()
            ->all();

        foreach ($recurrences as $index => $p) {
            $this->assertSame($p->post_parent, $event->ID);
            $dateAndTime = get_post_meta($p->ID, EventFields::DATE_AND_TIME, true);
            $this->assertSame($dateAndTime, $furtherDates[$index]);
        }
    }

    public function test_does_not_create_recurrences_for_events_in_the_past(): void
    {
        $_POST = [
            'acf' => [
                Fields::key(EventFields::FURTHER_DATES) => [
                    /** yesterday: */
                    "row-0" => [Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME) => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('yesterday'))],
                    /** in the future: */
                    "row-1" => [Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME) => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('+60 days 18:00:00'))],
                    "row-2" => [Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME) => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('+60 days 19:00:00'))],
                ],
            ],
        ];

        $event = $this->createEvent();

        $recurrences = $this->getRecurrences();

        $this->assertSame(count($recurrences), 2);
    }
}

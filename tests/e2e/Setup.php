<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\End2End;

use Hirasso\WP\FPEvents\FPEvents;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FieldGroups\LocationFields;
use Hirasso\WP\FPEvents\PostTypes;

/** Exit if accessed directly */
if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Setup context to run e2e tests against
 */
final class Setup
{
    public function __construct()
    {
        /** Overwrite pll post types */
        add_filter('pll_get_post_types', PostTypes::all(...));

        add_action('after_setup_theme', $this->init(...));
    }

    private function init()
    {
        $this->setupPolylang();

        if (function_exists('fpe')) {
            fpe();
        }

        $this->createTestPosts();
    }

    private function createTestPosts(): void
    {
        $locationId = $this->ensureTestLocation();
        $this->ensureTestEvent($locationId);
    }

    private function ensureTestLocation(): int
    {
        $existing = get_page_by_path('e2e-test-location', OBJECT, PostTypes::LOCATION);
        if ($existing) {
            return $existing->ID;
        }

        return wp_insert_post([
            'post_type'   => PostTypes::LOCATION,
            'post_title'  => 'E2E Test Location',
            'post_name'   => 'e2e-test-location',
            'post_status' => 'publish',
            'meta_input'  => [
                LocationFields::ADDRESS => "Test Street 1\n12345 Test City",
                LocationFields::AREA    => 'Test Area',
            ],
        ]);
    }

    private function ensureTestEvent(int $locationId): int
    {
        $existing = get_page_by_path('e2e-test-event', OBJECT, PostTypes::EVENT);
        if ($existing) {
            return $existing->ID;
        }

        return wp_insert_post([
            'post_type'   => PostTypes::EVENT,
            'post_title'  => 'E2E Test Event',
            'post_name'   => 'e2e-test-event',
            'post_status' => 'publish',
            'meta_input'  => [
                EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('+6 months')),
                EventFields::LOCATION_ID   => $locationId,
            ],
        ]);
    }

    private function setupPolylang()
    {
        if (!function_exists('PLL')) {
            return;
        }

        /** we don't need the polylang wizard */
        delete_transient('pll_activation_redirect');

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
            'flag_code'  => 'fr',
        ]);
    }

}

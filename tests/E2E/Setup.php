<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\E2E;

use Exception;
use Extended\ACF\Fields\Image;
use Extended\ACF\Fields\Repeater;
use Extended\ACF\Fields\Text;
use Extended\ACF\Fields\Textarea;
use Extended\ACF\Fields\URL;
use Extended\ACF\Location;
use Hirasso\WP\FPEvents\Core;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\PostTypes;
use WP_Post;

/** Exit if accessed directly */
if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Setup context to run e2e tests against
 */
final class Setup
{
    protected WP_Post $testPage;
    protected WP_Post $testLocation;
    protected WP_Post $testEvent;

    /**
     * @var array{
     *  key: string
     * } $fieldGroup
     */
    protected array $fieldGroup;

    public function __construct()
    {
        if (!function_exists('fp_events')) {
            return;
        }

        /** initialize the library */
        fp_events();

        /** we don't need the polylang wizard */
        delete_transient('pll_activation_redirect');

        $this->testPage = $this->getTestPage();
        $this->fieldGroup = $this->setupTestFieldGroup();
        $this->testLocation = $this->setupTestLocation();
        $this->testEvent = $this->setupTestEvent($this->testLocation->ID);

        \add_filter('render_block', [$this, 'renderBlock'], 10, 2);
    }

    /**
     * Inject test content after the post title
     *
     * @param array{
     *   blockName: string
     * } $block
     */
    public function renderBlock(string $content, array $block): string
    {
        if ($block['blockName'] !== 'core/post-title') {
            return $content;
        }

        \ob_start(); ?>

        <?= $content ?>

        <?php return \ob_get_clean();
    }

    /**
     * Get a test page to hold the frontend form for e2e tests
     */
    protected function getTestPage(): ?WP_Post
    {
        /**
         * First, try an existing post
         * @var ?int $postID
         */
        $postID = \get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'meta_query' => [
                'key' => 'e2e_test_page',
                'value' => '1',
            ],
            'fields' => 'ids',
        ])[0] ?? null;

        /**
         * Create one if none exists
         */
        if (!$postID) {
            $postID = \wp_insert_post([
                'post_type' => 'page',
            ]);
        }

        if (\is_wp_error($postID)) {
            throw new Exception($postID->get_error_message());
        }

        /**
         * Set post properties here
         */
        \wp_update_post([
            'ID' => $postID,
            'post_title' => 'Test Page',
            'post_name' => 'test-page',
            'post_status' => 'publish',
            'meta_input' => [
                'e2e_test_page' => true,
            ],
        ]);

        return \get_post($postID);
    }

    /**
     * Get or create a test location
     */
    protected function setupTestLocation(): WP_Post
    {
        $postID = \get_posts([
            'post_type' => PostTypes::LOCATION,
            'post_status' => 'any',
            'meta_query' => [
                ['key' => 'e2e_test_location', 'value' => '1'],
            ],
            'fields' => 'ids',
        ])[0] ?? null;

        if (!$postID) {
            $postID = \wp_insert_post(['post_type' => PostTypes::LOCATION]);
        }

        if (\is_wp_error($postID)) {
            throw new Exception($postID->get_error_message());
        }

        \wp_update_post([
            'ID' => $postID,
            'post_title' => 'Test Location',
            'post_name' => 'test-location',
            'post_status' => 'publish',
            'meta_input' => [
                'e2e_test_location' => true,
                'acfe_location_address' => "Test Street 1\n12345 Test City",
                'acfe_location_area' => 'Test Area',
            ],
        ]);

        return \get_post($postID);
    }

    /**
     * Get or create a test event linked to the given location
     */
    protected function setupTestEvent(int $locationID): WP_Post
    {
        $postID = \get_posts([
            'post_type' => PostTypes::EVENT,
            'post_status' => 'any',
            'meta_query' => [
                ['key' => 'e2e_test_event', 'value' => '1'],
            ],
            'fields' => 'ids',
        ])[0] ?? null;

        if (!$postID) {
            $postID = \wp_insert_post(['post_type' => PostTypes::EVENT]);
        }

        if (\is_wp_error($postID)) {
            throw new Exception($postID->get_error_message());
        }

        \wp_update_post([
            'ID' => $postID,
            'post_title' => 'Test Event',
            'post_name' => 'test-event',
            'post_status' => 'publish',
            'meta_input' => [
                'e2e_test_event' => true,
                EventFields::DATE_AND_TIME => \date(Core::MYSQL_DATE_TIME_FORMAT, \strtotime('next saturday 20:00')),
                EventFields::LOCATION_ID => $locationID,
            ],
        ]);

        return \get_post($postID);
    }

    /**
     * @return array<string, mixed>
     */
    protected function setupTestFieldGroup(): array
    {
        return \register_extended_field_group([
            'title' => 'Test Field Group',
            'fields' => [
                Text::make('First Name')
                    ->column(50)
                    ->required(),

                Text::make('Last Name')
                    ->column(50)
                    ->required(),

                Textarea::make('Message'),

                Image::make('An Image'),

                Repeater::make('Some Links')
                    ->fields([
                        URL::make('Link')
                            ->required(),
                    ])
                    ->minRows(1),

            ],
            'location' => [
                Location::where('post_type', 'page'),
            ],
            'position' => 'acf_after_title',
            'style' => 'default',
            'active' => true,
        ]);
    }

}

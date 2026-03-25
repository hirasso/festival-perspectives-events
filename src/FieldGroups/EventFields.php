<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\FieldGroups;

use Hirasso\WP\FPEvents\FPEvents;
use Hirasso\WP\FPEvents\PostTypes;

/**
 * Global field names
 */
final class EventFields extends Fields
{
    public const DATE_AND_TIME = 'acfe_event_date_and_time';
    public const FURTHER_DATES = 'acfe_event_further_dates';
    public const FURTHER_DATES_DATE_AND_TIME = 'acfe_event_further_dates_date_and_time';
    public const DURATION = 'acfe_event_duration';
    public const LOCATION_NAME = 'acfe_event_location_name';
    public const LOCATION_SORT_NAME = 'acfe_event_location_sort_name';
    public const LOCATION_ID = 'acfe_event_location_id';
    public const QUICK_INFOS = 'acfe_event_quick_infos';
    public const QUICK_INFOS_TEXT = 'acfe_event_quick_infos_text';
    public const EXTERNAL_LINK = 'acfe_event_external_link';
    public const TICKET_LINK = 'acfe_event_ticket_link';

    private const GROUP_KEY = 'group_acfe_event_settings';
    private const GROUP_TITLE = 'Event Settings';

    protected function addFields()
    {
        add_filter('acf/prepare_field/key=' . self::key(self::LOCATION_NAME), [__CLASS__, 'restrict_debug_field_visibilty']);
        add_filter('acf/prepare_field/key=' . self::key(self::LOCATION_SORT_NAME), [__CLASS__, 'restrict_debug_field_visibilty']);
        add_action('acf/render_field/key=' . self::key(self::LOCATION_ID), [__CLASS__, 'render_field_location_id']);

        acf_add_local_field_group([
            'key' => self::GROUP_KEY,
            'title' => self::GROUP_TITLE,
            'fields' => [
                [
                    'key' => self::key(self::DATE_AND_TIME),
                    'label' => 'Date and Time',
                    'name' => self::DATE_AND_TIME,
                    'type' => 'date_time_picker',
                    'required' => 1,
                    'wrapper' => [ 'width' => '50' ],
                    'relevanssi_exclude' => 0,
                    'display_format' => 'Y-m-d H:i',
                    'return_format' => FPEvents::MYSQL_DATE_TIME_FORMAT,
                    'first_day' => 1,
                    'translations' => 'sync',
                    'allow_in_bindings' => 1,
                ],
                [
                    'key' => self::key(self::DURATION),
                    'label' => 'Duration',
                    'name' => self::DURATION,
                    'aria-label' => '',
                    'type' => 'time_picker',
                    'append' => 'HH:MM',
                    'instructions' => '',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => ['width' => '50'],
                    'restrict_access' => '',
                    'relevanssi_exclude' => 0,
                    'display_format' => 'H:i',
                    'return_format' => 'H:i',
                    'translations' => 'sync',
                    'allow_in_bindings' => 0,
                ],
                [
                    'key' => self::key(self::FURTHER_DATES),
                    'label' => 'Further Dates',
                    'name' => self::FURTHER_DATES,
                    'type' => 'repeater',
                    'relevanssi_exclude' => 1,
                    'layout' => 'table',
                    'button_label' => 'Add Date',
                    'sub_fields' => [
                        [
                            'key' => self::key(self::FURTHER_DATES_DATE_AND_TIME),
                            'label' => 'Date and Time',
                            'name' => self::FURTHER_DATES_DATE_AND_TIME,
                            'type' => 'date_time_picker',
                            'required' => 1,
                            'relevanssi_exclude' => 1,
                            'display_format' => 'Y-m-d H:i',
                            'return_format' => FPEvents::MYSQL_DATE_TIME_FORMAT,
                            'first_day' => 1,
                            'translations' => 'sync',
                            'allow_in_bindings' => 1,
                            'parent_repeater' => self::key(self::FURTHER_DATES),
                        ],
                    ],
                ],
                [
                    'key' => self::key(self::LOCATION_ID),
                    'label' => 'Location',
                    'name' => self::LOCATION_ID,
                    'type' => 'post_object',
                    'required' => 1,
                    'relevanssi_exclude' => 1,
                    'post_type' => [PostTypes::LOCATION],
                    'post_status' => 'publish',
                    'return_format' => 'id',
                    'translations' => 'sync',
                    'ui' => 1,
                ],
                [
                    'key' => self::key(self::LOCATION_NAME),
                    'label' => 'Location Name',
                    'name' => self::LOCATION_NAME,
                    'type' => 'text',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => ['width' => '50'],
                    'readonly' => 1,
                    'relevanssi_exclude' => 1,
                    'translations' => 'ignore',
                    'allow_in_bindings' => 0,
                ],
                [
                    'key' => 'field_' . self::LOCATION_SORT_NAME,
                    'label' => 'Location Sort Name',
                    'name' => self::LOCATION_SORT_NAME,
                    'type' => 'text',
                    'wrapper' => ['width' => '50'],
                    'readonly' => 1,
                    'relevanssi_exclude' => 0,
                    'translations' => 'ignore',
                ],
                [
                    'key' => self::key(self::QUICK_INFOS),
                    'label' => 'Quick Infos',
                    'name' => self::QUICK_INFOS,
                    'type' => 'repeater',
                    'relevanssi_exclude' => 0,
                    'layout' => 'table',
                    'button_label' => 'Add Info',
                    'sub_fields' => [
                        [
                            'key' => self::key(self::QUICK_INFOS_TEXT),
                            'label' => 'Info',
                            'name' => self::QUICK_INFOS_TEXT,
                            'type' => 'text',
                            'required' => 1,
                            'relevanssi_exclude' => 0,
                            'parent_repeater' => self::key(self::QUICK_INFOS),
                            'translations' => 'copy_once',
                        ],
                    ],
                ],
                [
                    'key' => self::key(self::EXTERNAL_LINK),
                    'label' => 'External Link',
                    'name' => self::EXTERNAL_LINK,
                    'type' => 'url',
                    'relevanssi_exclude' => 1,
                    'wrapper' => ['width' => 50],
                    'translations' => 'copy_once',
                ],
                [
                    'key' => self::key(self::TICKET_LINK),
                    'label' => 'Ticket Link',
                    'name' => self::TICKET_LINK,
                    'type' => 'url',
                    'relevanssi_exclude' => 1,
                    'wrapper' => ['width' => 50],
                    'translations' => 'copy_once',
                ],
            ],
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => PostTypes::EVENT,
                    ],
                ],
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => PostTypes::RECURRENCE,
                    ],
                ],
            ],
            'menu_order' => -10,
            'position' => 'acf_after_title',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
            'show_in_rest' => 1,
        ]);
    }

    public static function render_field_location_id(array $field): void
    {
        static $recursing = false;

        $locationID = $field['value'];

        if ($recursing || empty($locationID)) {
            return;
        }

        if (!$location = get_post($locationID)) {
            return;
        }
        $recursing = true;
        $editLink = get_edit_post_link($location);

        ob_start() ?>
        <style>
            [data-name="<?= self::LOCATION_ID ?>"] .acf-input {
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
        </style>
        <script>
            /** Hide the location edit link if the field changes */
            acf?.addAction('ready_field/name=<?= self::LOCATION_ID ?>', function($field) {
                $field.$input().on('change', () => {
                    $field.$el.find('[data-edit-location]').remove();
                })
            });
        </script>
        <a title="Edit Location" data-edit-location href="<?= esc_url($editLink) ?>" class="dashicons dashicons-arrow-right-alt">
            <span class="screen-reader-text">Edit Location</span>
        </a>

        <?php echo ob_get_clean();
    }

    /**
     * Hide certain fields if the current user doesn't have administrator privileges
     */
    public static function restrict_debug_field_visibilty(?array $field): ?array
    {
        if (!current_user_can('administrator')) {
            return null;
        }
        $field['label'] .= ' (Only visible for admins)';

        return $field;
    }
}

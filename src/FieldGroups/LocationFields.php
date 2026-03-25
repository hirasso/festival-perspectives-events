<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\FieldGroups;

use Hirasso\WP\FPEvents\PostTypes;
use Hirasso\WP\FPEvents\Singleton;
use Hirasso\WP\FPEvents\Utils;
use WP_Post;

/**
 * Global field names
 */
final class LocationFields extends Singleton
{
    public const SORT_NAME = 'acfe_location_sort_name';
    public const ADDRESS = 'acfe_location_address';
    public const AREA = 'acfe_location_area';
    public const TEL = 'acfe_location_tel';
    public const EMAIL = 'acfe_location_email';
    public const WEBSITE = 'acfe_location_website';
    public const MAPS_URL = 'acfe_location_maps_url';
    protected const DEBUG_ATTACHED_EVENTS = 'acfe_location_debug_info';
    public const GROUP_KEY = 'group_acfe_location_settings';
    public const GROUP_TITLE = 'Location Settings';

    public function __construct()
    {
        add_action('acf/init', [$this, 'init']);
    }

    public function init()
    {
        add_filter('acf/prepare_field/key=' . Utils::fieldKey(self::DEBUG_ATTACHED_EVENTS), $this->prepare_field_debug_attached_events(...));

        $fields = [
            [
                'key' => Utils::fieldKey(self::SORT_NAME),
                'label' => 'Sort Name',
                'name' => self::SORT_NAME,
                'type' => 'text',
                'instructions' => 'Optional',
                'translations' => 'copy_once',
            ],
            [
                'key' => Utils::fieldKey(self::ADDRESS),
                'label' => 'Address',
                'name' => self::ADDRESS,
                'type' => 'textarea',
                'required' => 1,
                'translations' => 'sync',
                'rows' => 2,
                'new_lines' => 'br',
            ],
            [
                'key' => Utils::fieldKey(self::AREA),
                'label' => 'Area',
                'name' => self::AREA,
                'type' => 'text',
                'translations' => 'copy_once',
            ],
            [
                'key' => Utils::fieldKey(self::TEL),
                'label' => 'Tel',
                'name' => self::TEL,
                'type' => 'text',
                'translations' => 'sync',
                'wrapper' => ['width' => 50],
            ],
            [
                'key' => Utils::fieldKey(self::EMAIL),
                'label' => 'Email',
                'name' => self::EMAIL,
                'type' => 'email',
                'translations' => 'sync',
                'wrapper' => ['width' => 50],
            ],
            [
                'key' => Utils::fieldKey(self::WEBSITE),
                'label' => 'Website',
                'name' => self::WEBSITE,
                'aria-label' => '',
                'type' => 'url',
                'translations' => 'copy_once',
                'wrapper' => ['width' => 50],
            ],
            [
                'key' => Utils::fieldKey(self::MAPS_URL),
                'label' => 'Maps URL',
                'name' => self::MAPS_URL,
                'type' => 'url',
                'relevanssi_exclude' => 1,
                'translations' => 'sync',
                'wrapper' => ['width' => 50],
            ],
            [
                'key' => Utils::fieldKey(self::DEBUG_ATTACHED_EVENTS),
                'label' => 'Attached Events',
                'name' => self::DEBUG_ATTACHED_EVENTS,
                'type' => 'message',
            ],
        ];

        $fields = apply_filters('acfe:location:fields', $fields);

        acf_add_local_field_group([
            'key' => self::GROUP_KEY,
            'title' => self::GROUP_TITLE,
            'fields' => $fields,
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => PostTypes::LOCATION,
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

    /**
     * Render attached events
     */
    public function prepare_field_debug_attached_events(?array $field): ?array
    {
        if (empty($field)) {
            return null;
        }

        /** @var WP_Post[] $attachedEvents */
        $attachedEvents = fpe()->getEventsAtLocation(
            get_post(),
            amount: -1,
            ids: false,
            includeRecurrences: false,
        );

        if (empty($attachedEvents)) {
            return $field;
        }

        ob_start() ?>
        <table class="wp-list-table widefat striped">
            <?php foreach ($attachedEvents as $p) { ?>
                <tr>
                    <td><?= get_the_title($p) ?></td>
                    <td><a target="_blank" href="<?= get_permalink($p) ?>">view</a></td>
                    <td><a href="<?= get_edit_post_link($p->ID) ?>">edit</a></td>
                </tr>
            <?php } ?>
        </table>

        <?php
        $field['message'] = ob_get_clean();
        $field['label'] .= ' (' . count($attachedEvents) . ')';

        return $field;
    }
}

<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use Exception;
use WP_Post;
use InvalidArgumentException;
use RuntimeException;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FieldGroups\Fields;
use WP_CLI;

/**
 * Automatically create event recurrences, based on an ACF repeater field containing dates
 */
final class Recurrences extends Singleton
{
    private string $fieldKey;
    private string $subFieldKey;

    protected function __construct()
    {
        parent::__construct();

        $this->fieldKey = Fields::key(EventFields::FURTHER_DATES);
        $this->subFieldKey = Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME);

        if (Utils::instance()->isWpCli()) {
            WP_CLI::add_command('events recurrences create', $this->createRecurrencesCommand(...));
        }

        $this->addHooks();
    }

    private function addHooks(): void
    {
        add_action('init', [$this, 'init_hook']);
        add_action('save_post', [$this, 'save_post'], 20);
        add_action('trashed_post', [$this, 'deleteRecurrences']);
        add_action('before_delete_post', [$this, 'deleteRecurrences']);
        add_filter('display_post_states', [$this, 'display_post_states'], 10, 2);
        add_filter('post_type_link', [$this, 'post_type_link'], 10, 2);
        add_filter("acf/validate_value/key=$this->subFieldKey", [$this, 'acf_validate_value_further_date'], 10, 2);
    }

    public function init_hook()
    {
        if (!post_type_exists(PostTypes::EVENT)) {
            throw new InvalidArgumentException(sprintf('Post type \'%s\' doesn\'t exist', PostTypes::EVENT));
        }

        register_post_type(PostTypes::RECURRENCE, [
            'public' => false,
            'show_ui' => current_user_can('administrator'),
            'publicly_queryable' => false,
            'has_archive' => false,
            'show_in_menu' => 'edit.php?post_type=' . PostTypes::EVENT,
            'hierarchical' => false,
            'labels' => [
                'menu_name' => 'Recurrences',
                'name' => 'Event Recurrences',
                'singular_name' => 'Event Recurrence',
            ],
            'supports' => ['title', 'author'],
        ]);
    }

    /**
     * Runs on save post
     */
    public function save_post(int $postID): void
    {
        if (!FPEvents::instance()->isOriginalEvent($postID)) {
            return;
        }

        $this->createRecurrences($postID);
        $this->createRecurrencesForTranslations($postID);
    }

    /**
     * Create recurrences for Polylang translations of an event
     */
    private function createRecurrencesForTranslations(int $postID): void
    {
        if (!FPEvents::instance()->isOriginalEvent($postID)) {
            return;
        }

        if (!function_exists('pll_get_post_translations')) {
            return;
        }

        if (!$postLanguage = pll_get_post_language($postID)) {
            return;
        }

        /** @var array<string, int> $postTranslations */
        $postTranslations = pll_get_post_translations($postID);

        if (empty($postTranslations)) {
            return;
        }

        /**
         * Seed groups with the already-created recurrences for the original post,
         * indexed by recurrence position: [ index => [ lang => recurrenceID ] ]
         *
         * @var array<int, array<string, int>> $recurrenceGroups
         */
        $recurrenceGroups = collect($this->getRecurrences($postID))
            ->map(fn($id) => [$postLanguage => $id])
            ->all();

        foreach ($postTranslations as $language => $id) {
            foreach ($this->createRecurrences($id) as $index => $recurrenceID) {
                $recurrenceGroups[$index][$language] = $recurrenceID;
            }
        }

        // Link each set of per-language recurrences as Polylang translations of each other
        foreach ($recurrenceGroups as $group) {
            pll_save_post_translations($group);
        }
    }

    /**
     * Delete all recurrences from a post
     */
    public function deleteRecurrences(int $postID): void
    {
        if (!FPEvents::instance()->isOriginalEvent($postID)) {
            return;
        }

        $recurrences = $this->getRecurrences($postID);

        foreach ($recurrences as $recurrenceID) {
            wp_delete_post($recurrenceID, true);
        }
    }

    /**
     * Get all recurrences of an event
     */
    public function getRecurrences(int $postID)
    {
        if (!FPEvents::instance()->isOriginalEvent($postID)) {
            return [];
        }

        return get_posts([
            // Fetch from any language
            'lang' => '',
            'post_type' => PostTypes::RECURRENCE,
            'post_parent' => $postID,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
    }

    /**
     * Create recurrences from an original event, based on
     * the subfields of the ACF field 'further_dates'
     *
     * @return list<int>
     */
    public function createRecurrences(int $postID): array
    {
        /** Double-check if this is an original event */
        if (!FPEvents::instance()->isOriginalEvent($postID)) {
            return [];
        }

        $this->deleteRecurrences($postID);

        /** Only create clones for published events */
        if (!FPEvents::instance()->isVisiblePostStatus($postID)) {
            return [];
        }

        $furtherDates = get_field($this->fieldKey, $postID, false) ?: [];

        if (empty($furtherDates)) {
            return [];
        }

        /**
         * Create a recurrence for each furtherDates entry
         */
        return collect($furtherDates)
            ->pluck($this->subFieldKey)
            ->filter(fn(string $date) => !FPEvents::instance()->isInThePast($date))
            ->map(fn(string $dateTime) => $this->createRecurrence($postID, $dateTime))
            ->values()
            ->all();
    }

    /**
     * Create an event recurrence entry
     */
    private function createRecurrence(int $postID, string $dateTime): int
    {
        if (!FPEvents::instance()->isOriginalEvent($postID)) {
            throw new RuntimeException(sprintf(__('Not an event: %d'), $postID));
        }

        if (!FPEvents::instance()->parseDateInFormat($dateTime)) {
            throw new Exception("Invalid date format: $dateTime");
        }

        $originalMeta = FPEvents::instance()->getFlatPostMeta($postID);
        $originalPostArray = get_post($postID, ARRAY_A);

        $taxInput = collect(get_post_taxonomies($postID))
            ->reject('post_translations')
            ->mapWithKeys(fn($tax) => [
                $tax => collect(wp_get_object_terms($postID, $tax))
                    ->map(fn($term) => $term->term_id)
                    ->all(),
            ])
            ->all();

        $postName = $originalPostArray['post_name'] . '-' . md5($dateTime);

        $postarr = collect($originalPostArray)
            ->only([
                'post_title',
                'post_name',
                'post_status',
                'post_date',
            ])
            ->merge([
                'post_type' => PostTypes::RECURRENCE,
                'post_name' => $postName,
                'post_parent' => $postID,
                'meta_input' => [
                    ...$originalMeta, // needed for searching
                    EventFields::DATE_AND_TIME => $dateTime,
                ],
                'tax_input' => $taxInput,
            ])
            ->all();

        $result = wp_insert_post($postarr, true);

        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }

        return $result;
    }

    /**
     * Check if a post is an event recurrence
     */
    public function isRecurrence(int $postID): bool
    {
        return !!$postID && get_post_type($postID) === PostTypes::RECURRENCE;
    }

    /**
     * Add custom Post states, to help with understanding
     */
    public function display_post_states(array $states, WP_Post $post): array
    {
        if (get_post_type($post->ID) !== PostTypes::RECURRENCE) {
            return $states;
        }

        $editLink = get_edit_post_link($post->post_parent);
        $link = "<a href='$editLink'>#$post->post_parent</a>";
        $states[] = "Parent: $link";

        return $states;
    }

    /**
     * Redirects recurring events to their parent event
     */
    public function post_type_link(string $link, WP_Post $post): string
    {
        if (!$this->isRecurrence($post->ID)) {
            return $link;
        }

        return add_query_arg(
            'recurrence',
            $post->ID,
            get_permalink($post->post_parent),
        );
    }

    /**
     * Validate further dates
     */
    public function acf_validate_value_further_date(
        string|bool $valid,
        mixed $value,
    ): string|bool {
        if (is_string($valid)) {
            return $valid;
        }

        $originalDate = $_POST['acf'][EventFields::key(EventFields::DATE_AND_TIME)] ?? null;

        if ($value === $originalDate) {
            return "Each date must be different from the original event's date and time.";
        }

        $isDuplicate = collect($_POST['acf'][$this->fieldKey] ?? [])
            ->pluck($this->subFieldKey)
            ->duplicates()
            ->contains($value);

        if ($isDuplicate) {
            return "Each date must be unique";
        }

        return $valid;
    }

    /**
     * Create recurrences from an event
     *
     * ## OPTIONS
     *
     * <post-id>...
     * : One or more event post IDs to create recurrences for.
     *
     * ## EXAMPLES
     *
     *     wp events recurrences create 423 857 920
     *
     * @param list<string> $args
     *
     * @throws RuntimeException
     */
    private function createRecurrencesCommand(array $args): void
    {
        $postIDs = collect($args);

        if ($invalid = $postIDs->first(fn($id) => !FPEvents::instance()->isOriginalEvent($id))) {
            WP_CLI::error("Not a valid event post ID: '{$invalid}'");
        }

        /** The save_post hook does all we need */
        $postIDs->each(fn($id) => $this->createRecurrences((int) $id));
    }
}

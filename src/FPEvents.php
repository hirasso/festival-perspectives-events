<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use DateTimeImmutable;
use Exception;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FieldGroups\LocationFields;
use Hirasso\WP\FPEvents\Logger\LoggerFactory;
use RuntimeException;
use WP_CLI;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Manage events, recurrences and locations using Advanced Custom Fields
 */
final class FPEvents extends Singleton
{
    public Utils $utils;
    public Recurrences $recurrences;

    public const MYSQL_DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    public const FILTER_TAXONOMY = 'acfe-event_filter';

    protected function __construct()
    {
        if (!did_action('after_setup_theme')) {
            add_action('after_setup_theme', $this->init(...), 1);
            return;
        }

        $this->init();
    }

    /**
     * Get things going. Runs on the after_setup_theme hook.
     */
    private function init()
    {
        $this->utils = Utils::instance();
        $this->addHooks();
        $this->recurrences = Recurrences::instance();
        Locations::instance();
        EventFields::instance();
        LocationFields::instance();
        PolylangIntegration::instance();
    }

    private function addHooks(): void
    {
        // add_action('wp', fn() => dump($this->utils->getFormattedSql()));

        add_action('init', [$this, 'init_hook'], 1);
        add_filter('relevanssi_post_title_before_tokenize', [$this, 'relevanssi_post_title_before_tokenize'], 10, 2);
        add_filter('pll_get_post_types', [$this, 'pll_get_post_types'], 10, 2);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('pre_get_posts', $this->pre_get_posts(...));
        add_filter('term_link', [$this, 'term_link'], 10, 2);
        add_filter('relevanssi_hits_filter', [$this, 'relevanssi_hits_filter'], 10, 2);
        add_action('restrict_manage_posts', $this->renderYearFilter(...));
        add_filter('redirect_canonical', $this->redirectCanonical(...), 10, 2);

        $this->setupGarbageCollector();
    }

    /**
     * Runs on init
     */
    public function init_hook()
    {
        $this->addPostType(name: PostTypes::EVENT, slug: 'event', filter: true, args: [
            'menu_position' => 0,
            'menu_icon' => 'dashicons-calendar',
            'has_archive' => 'programm',
            'labels' => [
                'name' => 'Events',
                'singular_name' => 'Event',
                'menu_name' => 'Events',
            ],
            'supports' => [
                'title',
                'revisions',
                'author',
            ],
        ]);

        $this->customizeEditColumns();
    }

    /**
     * Setup the garbage collector
     */
    private function setupGarbageCollector(): void
    {
        $hook = 'fpe/run_garbage_collector';

        add_action($hook, $this->runGarbageCollector(...));

        Utils::instance()->addCommand('garbage collect', fn() => do_action($hook), [
            'shortdesc' => 'Run the garbage collector.',
        ]);

        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(
                timestamp: time(),
                recurrence: 'daily',
                hook: $hook,
            );
        }
    }

    /**
     * Run the archiver
     */
    private function runGarbageCollector(): void
    {
        $logger = LoggerFactory::create(
            GarbageCollector::class,
            isWpCli: $this->utils->isWpCli(),
        );

        $archiver = new GarbageCollector($logger);
        $archiver->run();
    }

    /**
     * Add query vars
     */
    public function query_vars(array $vars): array
    {
        return collect($vars)->merge(['by'])->all();
    }



    /**
     * Get all dates from an Event. Excludes recurrences in the past via filter
     * @return EventDate[]
     */
    public function getEventRecurrences(int|WP_Post $event): array
    {
        if (!$event = $this->utils->getEvent($event)) {
            return [];
        }

        return collect([$event->ID])
            ->merge(get_posts([
                'post_type' => [PostTypes::RECURRENCE],
                'post_parent' => $event->ID,
                'posts_per_page' => -1,
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'fields' => 'ids',
            ]))
            ->flip()
            ->map(fn($_, $postID) => get_field(EventFields::DATE_AND_TIME, $postID, false))
            ->filter($this->utils->isFilledString(...))
            ->sort()
            ->map(fn($dateString, $postID) => new EventDate(
                new DateTimeImmutable($dateString),
                $postID,
            ))
            ->values()
            ->all();
    }

    /**
     * Get all dates from an Event
     *
     * @return EventDate[]
     */
    public function getEventDates(int|WP_Post $p): array
    {
        if (!$event = $this->utils->getOriginalEvent($p)) {
            return [];
        }

        return collect([$event->ID])
            ->merge($this->recurrences->getRecurrences($event->ID))
            ->map(fn($postID) => get_field(EventFields::DATE_AND_TIME, $postID, false))
            ->filter($this->utils->isFilledString(...))
            ->sort()
            ->map(fn($dateString, $postID) => new EventDate(
                new DateTimeImmutable($dateString),
                $postID,
            ))
            ->values()
            ->all();
    }

    /**
     * Get all Filters of an event
     * @return WP_Term[]
     */
    public function getEventFilters(int|WP_Post $post): array
    {
        if (!$event = $this->utils->getEvent($post)) {
            return [];
        }

        return wp_get_object_terms($event->ID, self::FILTER_TAXONOMY);
    }

    /**
     * Remove all event filters from an event
     */
    public function removeEventFilters(int $postID): void
    {
        $assignedTerms = wp_get_object_terms($postID, self::FILTER_TAXONOMY, ['fields' => 'slugs']);

        if (!count($assignedTerms)) {
            return;
        }

        wp_remove_object_terms($postID, $assignedTerms, self::FILTER_TAXONOMY);

        if ($this->utils->isWpCli()) {
            WP_CLI::success(sprintf(
                'Removed %d event filters from event #%d: %s',
                count($assignedTerms),
                $postID,
                WP_CLI::colorize('%b' . implode(', ', $assignedTerms) . '%n'),
            ));
        }
    }

    /**
     * Get all events attached to a location
     * @return int[]|WP_Post[]
     */
    public function getEventsAtLocation(
        int|WP_Post $post,
        int $amount = -1,
        bool $ids = true,
        bool $includeRecurrences = true,
    ): array {
        $postID = $post->ID ?? $post;

        if (!$this->utils->isLocation($postID)) {
            return [];
        }

        $postType = $includeRecurrences ? [PostTypes::EVENT, PostTypes::RECURRENCE] : PostTypes::EVENT;

        $args = [
            'lang' => '',
            'suppress_filters' => true,
            'post_type' => $postType,
            'posts_per_page' => $amount,
            'meta_query' => [
                EventFields::LOCATION_ID => [
                    'key' => EventFields::LOCATION_ID,
                    'value' => $postID,
                    'type' => 'NUMERIC',
                ],
            ],
        ];

        if ($ids) {
            $args['fields'] = 'ids';
        }

        $attachedEvents = new WP_Query($args);

        return $attachedEvents->posts;
    }

    /**
     * Filter the post title before indexing by relevanssi
     */
    public function relevanssi_post_title_before_tokenize(string $title, WP_Post $post): string
    {
        if (!$this->utils->isEvent($post)) {
            return $title;
        }

        $locationTokens = collect([
            get_post_meta($post->ID, EventFields::LOCATION_NAME, true),
            get_post_meta($post->ID, EventFields::LOCATION_SORT_NAME, true),
        ])
            ->filter()
            ->join(' ');

        return $this->utils->addWords($title, $locationTokens);
    }

    /**
     * Forcibly enable translations for all our post types
     */
    public function pll_get_post_types(array $postTypes, bool $is_settings)
    {
        $merge = [
            PostTypes::EVENT,
            PostTypes::RECURRENCE,
            PostTypes::LOCATION,
        ];

        /** keys === values */
        $merge = array_combine($merge, $merge);

        return collect($postTypes)->merge($merge)->all();
    }

    /**
     * Prepare the main archive query for events
     */
    public function pre_get_posts(WP_Query $query): void
    {
        if (!$query->is_main_query()) {
            return;
        }

        if (!$query->is_archive()) {
            return;
        }

        if ($this->utils->guessPostType($query) !== PostTypes::EVENT) {
            return;
        }

        if (!is_admin()) {
            $query->query_vars = collect($query->query_vars)
                ->replaceRecursive($this->getArchiveArgs($query))
                ->all();
        }

        /** restrict the year, both in the frontend as well as in the admin */
        $this->restrictToYear($query, $this->utils->getQueriedYear($query));
    }

    /**
     * Restrict a query to a certain year
     */
    private function restrictToYear(WP_Query $query, ?string $year): void
    {
        if ($query->get('suppress_filters')) {
            return;
        }

        if (!$year = $this->utils->parseYear($year)) {
            return;
        }

        $query->set('year', '');
        $query->set('acfe:year', $year);
        $query->query_vars = array_replace_recursive($query->query_vars, [
            'meta_query' => [
                'acfe:year' => [
                    'key'     => EventFields::DATE_AND_TIME,
                    'value'   => [ "{$year}-01-01", "{$year}-12-31" ],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ]);
    }

    /**
     * Get the archive args for events
     */
    private function getArchiveArgs(WP_Query $query)
    {
        if (!$query->is_archive()) {
            return [];
        }

        $groupby = get_query_var('by', null);

        $args = collect(array_replace_recursive([
            'post_type' => PostTypes::EVENT,
            'posts_per_page' => 6,
            'ignore_sticky_posts' => true,
        ], match (true) {
            // calendar
            $groupby === 'day' => [
                'post_type' => [PostTypes::EVENT, PostTypes::RECURRENCE],
                'orderby' => [EventFields::DATE_AND_TIME => 'asc'],
                'meta_query' => match (true) {
                    /**
                     * in a yearly archive, we only need
                     * the date and time for sorting
                     */
                    $this->isYearlyArchive($query) => [
                        EventFields::DATE_AND_TIME => [
                            'key' => EventFields::DATE_AND_TIME,
                            'compare' => 'EXISTS',
                        ],
                    ],
                    default => [
                        EventFields::DATE_AND_TIME => [
                            'key' => EventFields::DATE_AND_TIME,
                            'type' => 'DATETIME',
                            'compare' => '>=',
                            'value' => current_time(self::MYSQL_DATE_TIME_FORMAT),
                        ],
                    ],
                },
                'acfe:groupby-clause' => new GroupByMetaClause(
                    key: EventFields::DATE_AND_TIME,
                    groupby: 'day',
                    expression: 'DATE({alias}.meta_value) as day',
                ),
            ],
            // locations
            $groupby === 'location' => [
                'orderby' => [EventFields::LOCATION_SORT_NAME => 'asc'],
                'meta_query' => [
                    EventFields::LOCATION_SORT_NAME => [
                        'key' => EventFields::LOCATION_SORT_NAME,
                        'compare' => 'EXISTS',
                    ],
                    EventFields::LOCATION_NAME => [
                        'key' => EventFields::LOCATION_NAME,
                        'compare' => 'EXISTS',
                    ],
                ],
                'acfe:groupby-clause' => new GroupByMetaClause(EventFields::LOCATION_SORT_NAME),
            ],
            // filtered
            !$groupby && $query->is_tax() => [
                'orderby' => [EventFields::DATE_AND_TIME => 'asc'],
                'meta_query' => [
                    EventFields::DATE_AND_TIME => [
                        'key' => EventFields::DATE_AND_TIME,
                        'compare' => 'EXISTS',
                    ],
                ],
            ],
            // A-Z, unfiltered
            default => [
                'orderby' => ['title' => 'asc'],
            ],
        }));

        return $args->all();
    }

    /**
     * Get the current batch from a query
     */
    public function getCurrentBatch(WP_Query $query, ?string $groupby = null)
    {
        if (empty($query->posts)) {
            return [];
        }

        $groupby ??= get_query_var('by');

        return match ($groupby) {
            'day' => $this->groupByDay($query),
            'location' => $this->groupByLocation($query),
            default => $query->posts,
        };
    }

    /**
     * Get the default args for grouped events
     */
    private function getGroupDefaultArgs(WP_Query $query)
    {
        return collect($query->query_vars)
            ->except(['nopaging'])
            ->replaceRecursive([
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
                'posts_per_page' => -1,
                'suppress_filters' => true,
                'relevanssi' => $query->is_search(),
            ]);
    }

    /**
     * Group posts by day
     * @return array<int, GroupedEvents>
     */
    private function groupByDay(WP_Query $query): array
    {
        if (!count($query->posts)) {
            return [];
        }

        /** @var WP_Post[]|object{day: string}[] $posts */
        $posts = $query->posts;

        $days = collect($posts)->map(function (object $post) {
            return property_exists($post, 'day')
                ? $post->day
                : throw new RuntimeException("No 'day' found in post $post->ID");
        })->all();

        [$first, $last] = [
            collect($days)->first(),
            collect($days)->last(),
        ];

        $args = $this
            ->getGroupDefaultArgs($query)
            ->replaceRecursive([
                'meta_query' => [
                    /**
                     * DO NOT use EventFields::DATE_AND_TIME for the key here,
                     * otherwise sorting will break
                     */
                    'acfe:between-dates' => [
                        'key' => EventFields::DATE_AND_TIME,
                        'compare' => 'BETWEEN',
                        'value' => [$first, $last],
                        'type' => 'DATE',
                    ],
                ],
            ])
            ->all();

        return collect(get_posts($args))
            ->groupBy(fn($post) => $this->formatDayRelativeToToday(get_field(
                EventFields::DATE_AND_TIME,
                $post,
                false,
            )))
            ->map(fn($group, $title) => new GroupedEvents(title: $title, posts: $group->all()))
            ->values()
            ->all();
    }

    /**
     * Group posts by location
     * @return array<int, GroupedEvents>
     */
    private function groupByLocation(WP_Query $query): array
    {
        if (!count($query->posts)) {
            return [];
        }

        /** @var WP_Post[]|object[] $posts */
        $posts = $query->posts;

        $locationSortNames = collect($posts)->map(function (object $post) {
            return property_exists($post, EventFields::LOCATION_SORT_NAME)
                ? $post->{EventFields::LOCATION_SORT_NAME}
                : throw new RuntimeException("No 'EventFields::LOCATION_SORT_NAME' found in post $post->ID");
        })->all();

        $args = $this
            ->getGroupDefaultArgs($query)
            ->replaceRecursive([
                'meta_query' => [
                    'acfe_min_location_sort_name' => [
                        'key' => EventFields::LOCATION_SORT_NAME,
                        'compare' => '>=',
                        'value' => collect($locationSortNames)->first(),
                    ],
                    'acfe_max_location_sort_name' => [
                        'key' => EventFields::LOCATION_SORT_NAME,
                        'compare' => '<=',
                        'value' => collect($locationSortNames)->last(),
                    ],
                    EventFields::DATE_AND_TIME => [
                        'key' => EventFields::DATE_AND_TIME,
                        'compare' => 'EXISTS',
                    ],
                ],
                'orderby' => [
                    EventFields::DATE_AND_TIME => 'asc',
                ],
            ])
            ->all();

        return collect(get_posts($args))
            ->groupBy(fn($post) => get_field(EventFields::LOCATION_NAME, $post))
            ->map(fn($group, $title) => new GroupedEvents(title: $title, posts: $group->all()))
            ->values()
            ->all();
    }

    /**
     * Filter get_term_link to return the post type url appended with ?filter=term
     */
    public function term_link(string $link, WP_Term $term)
    {
        $postType = get_taxonomy($term->taxonomy)->object_type[0] ?? null;

        if ($postType !== PostTypes::EVENT) {
            return $link;
        }

        /** Preserve any params on the link */
        parse_str(parse_url($link, PHP_URL_QUERY) ?: '', $params);

        $archiveURL = get_post_type_archive_link(PostTypes::EVENT);
        $currentURL = $this->getCurrentURL(true);

        $url = str_starts_with($currentURL, $archiveURL) ? $currentURL : $archiveURL;

        return add_query_arg(['filter' => $term->slug, ...$params], $url);
    }

    /**
     * Flatten post meta (making sure single keys are not returned as an array)
     */
    public function getFlatPostMeta(int $postID): array
    {
        return collect(get_post_meta($postID))->map(
            fn(mixed $_, string $key) => get_post_meta($postID, $key, true),
        )->all();
    }

    /**
     * Get an event's duration in minutes
     */
    public function getEventDuration(int|WP_Post $post): ?string
    {
        if (!$this->utils->isEvent($post)) {
            return null;
        }
        $duration = get_field(EventFields::DURATION, $post);
        $minutes = $this->durationToMinutes($duration);

        $label = __('Minutes', 'fpe');

        return $minutes ? "$minutes $label" : null;
    }

    /**
     * Convert a duration in the shape of H:i to minutes
     */
    private function durationToMinutes(?string $duration): int
    {
        if (!$this->utils->isFilledString($duration) || !str_contains($duration, ':')) {
            return 0;
        }

        [$hours, $minutes] = collect(explode(':', $duration))->map(fn($value) => absint($value))->all();

        return ($hours * 60) + $minutes;
    }

    /**
     * Format a day relative to today
     * Special cases: 'Yesterday', 'Today' and 'Tomorrow'
     */
    public function formatDayRelativeToToday(
        DateTimeImmutable|string $date,
        ?string $relativeDateFormat = ', d. F Y',
        ?string $absoluteDateFormat = null,
    ): string {
        $absoluteDateFormat ??= get_option('date_format');

        if (is_string($date)) {
            $date = new DateTimeImmutable($date);
        }

        $now = current_datetime();

        $relativeDay = match (true) {
            $this->isSameDay($date, $now) => __('Today', 'fpe'),
            $this->isSameDay($date, $now->modify('- 1 day')) => __('Yesterday', 'fpe'),
            $this->isSameDay($date, $now->modify('+ 1 day')) => __('Tomorrow', 'fpe'),
            default => null,
        };

        return match (!!$relativeDay) {
            true => $relativeDay . date_i18n($relativeDateFormat, $date->getTimestamp()),
            default => date_i18n($absoluteDateFormat, $date->getTimestamp()),
        };
    }

    /**
     * Check if two dates represent the same day
     */
    public function isSameDay(DateTimeImmutable $date1, ?DateTimeImmutable $date2 = null): bool
    {
        if (!$date2) {
            return false;
        }
        return $date1->format('Y-m-d') === $date2->format('Y-m-d');
    }

    /**
     * Get current URL
     */
    public function getCurrentURL(bool $includeQuery = false): string
    {
        $url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

        if (!$includeQuery) {
            $url = explode('?', $url)[0];
        }
        return $url;
    }

    /**
     * Helper function to add a custom post type
     */
    public function addPostType(string $name, string $slug, array $args, ?bool $filter = false): void
    {
        $args = array_merge([
            'menu_icon' => 'dashicons-star-filled',
            'with_filter' => false,
            'exclude_from_search' => false,
            'has_archive' => false,
        ], $args);

        /** Assume the menu icon is a local SVG file if it doesn't start with dashicons- */
        if (!str_starts_with($args['menu_icon'], 'dashicons-')) {
            $localSvgFile = file_get_contents(get_theme_file_path($args['menu_icon']));
            $args['menu_icon'] = 'data:image/svg+xml;base64,' . base64_encode($localSvgFile);
        }

        $archive_slug = $args['has_archive'];
        if ($archive_slug === true) {
            $archive_slug = $slug;
        }

        $singular_name = $args['labels']['singular_name'];

        if ($archive_slug && $filter) {
            $taxonomy = "{$name}_filter";
            register_taxonomy($taxonomy, $name, [
                'labels' => [
                    'name' => "$singular_name Filters",
                    'singular_name' => "$singular_name Filter",
                    'menu_name' => 'Filters',
                ],
                'public' => true,
                'rewrite' => false,
                'query_var' => 'filter',
                'show_ui' => true,
                'hierarchical' => true,
                'show_admin_column' => true,
            ]);
        }

        $post_type_args = wp_parse_args($args, [
            'public' => true,
            'rewrite' => [
                'with_front' => false,
                'slug' => $slug,
                'ep_mask' => EP_PAGES, // assign EP_PAGES to the CPT
            ],
            'has_archive' => $archive_slug,
            'menu_position' => 0,
            'hierarchical' => false,
            'taxonomies' => $filter ? ["{$name}_filter"] : [],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type($name, $post_type_args);
    }

    /**
     * Filter relevanssi search hits
     *
     * @see https://www.relevanssi.com/user-manual/filter-hooks/relevanssi_hits_filter/
     * @param array{0: list<object{ID: int}>, 1: string} $hits
     */
    public function relevanssi_hits_filter(array $hits, WP_Query $query): array
    {
        $groupby = get_query_var('by');

        /**
         * Only group hits when in the main query
         */
        if (!$query->is_main_query()) {
            return $hits;
        }

        $hits[0] = match ($groupby) {
            'day' => collect($hits[0])
                ->each(
                    fn($hit) => $hit->day = date(
                        'Y-m-d',
                        strtotime(get_field(EventFields::DATE_AND_TIME, $hit->ID, false)),
                    ),
                )
                ->unique('day')
                ->all(),
            'location' => collect($hits[0])
                ->each(
                    fn($hit) => $hit->{EventFields::LOCATION_SORT_NAME} = get_field(
                        EventFields::LOCATION_SORT_NAME,
                        $hit->ID,
                    ),
                )
                ->unique(EventFields::LOCATION_SORT_NAME)
                ->all(),
            default => $hits[0],
        };

        return $hits;
    }

    /**
     * Customize edit columns of post types
     */
    private function customizeEditColumns(): void
    {
        collect([PostTypes::EVENT, PostTypes::RECURRENCE])->each(function (string $postType) {
            add_filter("manage_edit-{$postType}_columns", $this->addCustomEditColumns(...));
            add_action("manage_{$postType}_posts_custom_column", $this->handleCustomEditColumns(...), 10, 2);
        });
    }

    /**
     * Add custom columns to the edit screen
     */
    private function addCustomEditColumns(array $columns): array
    {
        unset($columns['date']);
        unset($columns['author']);

        $newColumns = [
            'acfe:thumbnail' => __('Post Thumbnail'),
        ];

        switch (get_current_screen()?->post_type) {
            case PostTypes::EVENT:
                $newColumns['acfe:location'] = __('Location', 'fpe');
                $newColumns['acfe:dates'] = __('Dates', 'fpe');
                break;
            case PostTypes::RECURRENCE:
                $newColumns['acfe:location'] = __('Location', 'fpe');
                $newColumns['acfe:date_and_time'] = __('Date', 'fpe');
                break;
        }

        /** inject at the second position (after the title) */
        $rest = array_splice($columns, 2);
        return array_merge($columns, $newColumns, $rest);
    }

    /**
     * Handles the value of custom edit columns
     */
    private function handleCustomEditColumns(string $column, int $postID): void
    {
        switch ($column) {
            case 'acfe:thumbnail':
                echo get_the_post_thumbnail($postID, 'thumbnail', [
                    'style' => 'display: block; height: 80px; width: auto;',
                ]);
                break;
            case 'acfe:dates':
                echo
                    collect($this->getEventDates($postID))
                        ->map(function (EventDate $date) {
                            $url = get_permalink($date->postID);
                            return "<a href='$url' target='_blank'>$date</a>";
                        })
                        ->join('<br>')
                ;
                break;

            case 'acfe:location':
                if ($locationID = get_field(EventFields::LOCATION_ID, $postID)) {
                    $editLink = get_edit_post_link($locationID);
                    $title = get_the_title($locationID);
                    echo "<a href='$editLink'>$title</a>";
                }

                break;
            case 'acfe:date_and_time':
                $dateFormat = get_option('date_format');
                $format = "$dateFormat, H:i";

                $dateString = get_field(EventFields::DATE_AND_TIME, $postID, false);
                echo date_i18n($format, strtotime($dateString));
                break;
        }
    }

    /**
     * Render a filter for the years
     */
    private function renderYearFilter(string $postType): void
    {
        if (!$this->utils->isEventPostType($postType)) {
            return;
        }

        $query = $this->utils->getMainQuery();
        $years = $this->utils->getYears($query);

        if (count($years) < 1) {
            return;
        }

        $selectedYear = $this->utils->getQueriedYear($query);

        ob_start(); ?>
        <select name="year">

            <?php foreach ($years as $year) : ?>
                <option <?= attr([
                    'selected' => $year === $selectedYear,
                    'value' => $year,
                ]) ?>>
                    <?= esc_html((string) $year) ?>
                </option>
            <?php endforeach; ?>

        </select>
        <?php echo ob_get_clean();
    }

    /**
     * Check if a query is a yearly archive
     * – post type is acfe-event
     * – year is set
     * – year is smaller then current year
     */
    public function isYearlyArchive(WP_Query $query): bool
    {
        if ($this->utils->guessPostType($query) !== PostTypes::EVENT) {
            return false;
        }

        if (!$year = $this->utils->getQueriedYear($query)) {
            return false;
        }

        return $year < (int) current_time('Y');
    }

    /**
     * Check if all dates of an event are in the past
     */
    public function isEventInThePast(int|WP_Post $post): bool
    {
        if (!$this->utils->isEvent($post)) {
            return false;
        }

        return collect($this->getEventDates($post))
            ->every(fn($eventDate) => $this->utils->isInThePast($eventDate->toMySQLString()));
    }

    /**
     * Set further dates for an event manually
     *
     * @param list<string> $dates
     */
    public function setFurtherDates(int|WP_Post $event, array $dates): void
    {
        if (!$event = $this->utils->getEvent($event)) {
            throw new Exception(sprintf('Please provide an event'));
        }

        $subFieldKey = Utils::fieldKey(EventFields::FURTHER_DATES_DATE_AND_TIME);

        $rows = collect($dates)
            ->values()
            ->map(fn($date) => \strtotime($date))
            ->filter(fn($timestamp) => !!$timestamp)
            ->sort()
            ->map(fn($timestamp) => [$subFieldKey => \date(FPEvents::MYSQL_DATE_TIME_FORMAT, $timestamp)])
            ->all();

        update_field(
            Utils::fieldKey(EventFields::FURTHER_DATES),
            $rows,
            $event,
        );

        Recurrences::instance()->updateRecurrences($event->ID);
    }

    /**
     * Get an event's date and time, separated by $separator
     */
    public function getEventDateAndTime(int|WP_Post $post, string $separator = ', '): ?string
    {
        if (!$this->utils->isEvent($post)) {
            return null;
        }

        $rawDate = get_field(EventFields::DATE_AND_TIME, $post, false);

        $date = date_i18n(get_option('date_format'), strtotime($rawDate));
        $time = date_i18n(get_option('time_format'), strtotime($rawDate));

        return collect([$date, $time])->filter()->join($separator);
    }

    /**
     * Get the year of an event
     */
    public function getEventYear(int|WP_Post $post): ?string
    {
        if (!$this->utils->isEvent($post)) {
            return null;
        }

        $rawDate = get_field(EventFields::DATE_AND_TIME, $post, false);

        return (new DateTimeImmutable($rawDate))->format('Y');
    }

    /**
     * Return "Location Name, Location Area"
     */
    public function getLocationNameAndArea(int $eventID): string
    {
        $locationID = get_field(EventFields::LOCATION_ID, $eventID);

        return collect([
            get_the_title($locationID),
            get_field(LocationFields::AREA, $locationID) ?: '',
        ])
        ->filter($this->utils->isFilledString(...))
        ->join(', ');
    }

    /**
     * Do not redirect canonically if on a yearly archive
     * The cleaner approach could be to _not_ use "year" as the query arg...
     * but this is good enough for now.
     */
    private function redirectCanonical(mixed $redirect_url, string $requested_url): mixed
    {
        return $this->utils->getMainQuery()->get('acfe:year')
            ? $requested_url
            : $redirect_url;
    }
}

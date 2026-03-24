<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use DateTimeImmutable;
use Exception;
use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FieldGroups\Fields;
use Hirasso\WP\FPEvents\Logger\LoggerFactory;
use InvalidArgumentException;
use RuntimeException;
use WP_CLI;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Manage events, recurrences and locations using Advanced Custom Fields
 */
final class Core
{
    private static ?self $instance = null;

    public const MYSQL_DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    public const FILTER_TAXONOMY = 'acfe-event_filter';

    public function __construct(public Utils $utils) {}

    public static function init(Utils $utils)
    {
        self::$instance ??= new self($utils);
        return self::$instance;
    }

    public function addHooks(): self
    {
        if (has_action('init', [$this, 'init_hook'])) {
            return $this;
        }

        // add_action('wp', fn() => dump($this->utils->getFormattedSql()));

        add_action('init', [$this, 'init_hook']);
        add_filter('relevanssi_post_title_before_tokenize', [$this, 'relevanssi_post_title_before_tokenize'], 10, 2);
        add_filter('pll_get_post_types', [$this, 'pll_get_post_types'], 10, 2);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('pre_get_posts', $this->prepare_main_query(...));
        add_filter('term_link', [$this, 'term_link'], 10, 2);
        add_filter('relevanssi_hits_filter', [$this, 'relevanssi_hits_filter'], 10, 2);
        add_action('restrict_manage_posts', $this->renderYearFilter(...));
        add_filter('posts_clauses', $this->applyGroupByClause(...), 1000, 2);

        $this->setupArchiver();

        return $this;
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
     * Setup the archiver
     */
    private function setupArchiver(): void
    {
        $hook = 'fp_events_run_archiver';

        add_action($hook, $this->runArchiver(...));

        if ($this->utils->isWpCli()) {
            WP_CLI::add_command("fp-events archiver run", fn() => do_action($hook), [
                'shortdesc' => 'Run the archiver.',
            ]);
        }

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
    private function runArchiver(): void
    {
        $logger = LoggerFactory::create(
            Archiver::class,
            isWpCli: $this->utils->isWpCli(),
        );

        $archiver = new Archiver($this, $logger);
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
     * Validate that a provided date string conforms to an expected format
     */
    public function parseDateInFormat(string $dateString, string $expectedFormat = self::MYSQL_DATE_TIME_FORMAT): bool
    {
        $datetime = \DateTime::createFromFormat($expectedFormat, $dateString);
        return $datetime && $datetime->format($expectedFormat) === $dateString;
    }

    /**
     * Get an event, only if the provided post/post_id is an event
     */
    private function getEvent(mixed $post): ?WP_Post
    {
        $post = get_post($post);
        return $this->isEvent($post) ? $post : null;
    }

    /**
     * Check if a post is an event
     */
    public function isEvent(mixed $post)
    {
        if (!$postID = $this->getPostID($post)) {
            return false;
        }
        return in_array(get_post_type($postID), [PostTypes::EVENT, PostTypes::RECURRENCE], true);
    }

    /**
     * Check if a post is an original event
     */
    public function isOriginalEvent(string|int|WP_Post $post)
    {
        if (!($postID = $this->getPostID($post))) {
            return false;
        }
        return get_post_type($postID) === PostTypes::EVENT;
    }

    /**
     * Check if a post is a location
     */
    public function isLocation(int|WP_Post $post)
    {
        if (!($postID = $this->getPostID($post))) {
            return false;
        }

        return get_post_type($postID) === PostTypes::LOCATION;
    }

    /**
     * Get the post ID from an unknown $post argument
     */
    private function getPostID(string|int|WP_Post|null $post): ?int
    {
        if ($post instanceof WP_Post) {
            return $post->ID;
        }

        return is_numeric($post) ? (int) $post : null;
    }

    /**
     * Get all dates from an Event. Excludes recurrences in the past via filter
     * @return EventDate[]
     */
    public function getEventDates(int|WP_Post $event): array
    {
        if (!$event = $this->getEvent($event)) {
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
            ->filter($this->isFilledString(...))
            ->sort()
            ->map(fn($dateString, $postID) => new EventDate(
                new DateTimeImmutable($dateString),
                $postID,
            ))
            ->all();
    }

    /**
     * Check if a string is filled
     */
    public function isFilledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Get all Filters of an event
     * @return WP_Term[]
     */
    public function getEventFilters(int|WP_Post $post): array
    {
        if (!$event = $this->getEvent($post)) {
            return [];
        }

        return wp_get_object_terms($event->ID, self::FILTER_TAXONOMY);
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

        if (!$this->isLocation($postID)) {
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
        if (!$this->isEvent($post)) {
            return $title;
        }

        $locationTokens = collect([
            get_post_meta($post->ID, EventFields::LOCATION_NAME, true),
            get_post_meta($post->ID, EventFields::LOCATION_SORT_NAME, true),
        ])
            ->filter()
            ->join(' ');

        return $this->addWords($title, $locationTokens);
    }

    /**
     * Add words to a string if that string doesn't already contain them
     */
    private function addWords(string $str, ?string $words = ''): string
    {
        $words = trim($words);

        if (empty($words)) {
            return $str;
        }

        foreach (explode(' ', $words) as $word) {
            if (!str_contains(" $str ", " $word ")) {
                $str = "$str $word";
            }
        }

        return $str;
    }

    /**
     * Get a date for display purposes
     */
    public function getDateForDisplay(string $dateString, bool $includeTime = false): string
    {
        $dateFormat = get_option('date_format');
        $timeFormat = get_option('time_format');
        $format = $includeTime ? "$dateFormat $timeFormat" : $dateFormat;
        return date_i18n($format, strtotime($dateString));
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
    public function prepare_main_query(WP_Query $query): void
    {
        if (!$query->is_main_query()) {
            return;
        }

        if (!$query->is_archive()) {
            return;
        }

        if ($this->guessPostType($query) !== PostTypes::EVENT) {
            return;
        }

        if (!is_admin()) {
            $query->query_vars = collect($query->query_vars)
                ->replaceRecursive($this->getArchiveArgs($query))
                ->all();
        }

        /** restrict the year, both in the frontend as well as in the admin */
        $this->restrictToYear($query, $this->getQueriedYear($query));
    }

    /**
     * Restrict a query to a certain year
     */
    private function restrictToYear(WP_Query $query, int $year): void
    {
        if ($query->get('suppress_filters')) {
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
     * Get the currently queried year. Also handle searches for years.
     */
    public function getQueriedYear(?WP_Query $query): int
    {
        $query ??= $this->utils->getMainQuery();

        return $this->utils->parseYear($query->get('acfe:year'))
            ?? $this->utils->parseYear($query->get('year'))
            ?? $this->utils->parseYear($query->get('s'))
            ?? (int) current_time('Y');
    }

    /**
     * Get the archive args for events
     */
    private function getArchiveArgs(WP_Query $query)
    {
        $wpdb = $this->utils->wpdb();

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
     * Inject the custom GroupByMetaClause into WP posts clauses
     */
    public function applyGroupByClause(array $clauses, WP_Query $query): array
    {
        /** @var ?GroupByMetaClause $groupByClause */
        $groupByClause = $query->get('acfe:groupby-clause');

        if (!$groupByClause) {
            return $clauses;
        }

        /** Only apply the clause once */
        $query->set('acfe:groupby-clause', '');

        $alias = collect($query->meta_query->get_clauses())
            ->map(fn($clause) => $clause['alias'])
            ->get($groupByClause->key)
            ?? throw new Exception("No post clause alias found for '{$groupByClause->key}'");

        $clauses['fields'] = str_replace('{alias}', $alias, $groupByClause->expression);
        $clauses['groupby'] = $groupByClause->groupby;

        return $clauses;
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

        $archiveURL = get_post_type_archive_link(PostTypes::EVENT);
        $currentURL = $this->getCurrentURL(true);

        $url = str_starts_with($currentURL, $archiveURL) ? $currentURL : $archiveURL;

        return add_query_arg(['filter' => $term->slug], $url);
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
        if (!$this->isEvent($post)) {
            return null;
        }
        $duration = get_field(EventFields::DURATION, $post);
        $minutes = $this->durationToMinutes($duration);

        $label = __('Minutes', 'festival-perspectives-events');

        return $minutes ? "$minutes $label" : null;
    }

    /**
     * Convert a duration in the shape of H:i to minutes
     */
    private function durationToMinutes(?string $duration): int
    {
        if (!$this->isFilledString($duration) || !str_contains($duration, ':')) {
            return 0;
        }

        [$hours, $minutes] = collect(explode(':', $duration))->map(fn($value) => absint($value))->all();

        return ($hours * 60) + $minutes;
    }

    /**
     * Check if the post status of a post can be considered "visible"
     */
    public function isVisiblePostStatus(int $postID)
    {
        return collect(['publish', 'future', 'private'])->contains(get_post_status($postID));
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
            $this->isSameDay($date, $now) => __('Today', 'festival-perspectives-events'),
            $this->isSameDay($date, $now->modify('- 1 day')) => __('Yesterday', 'festival-perspectives-events'),
            $this->isSameDay($date, $now->modify('+ 1 day')) => __('Tomorrow', 'festival-perspectives-events'),
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
     * Guess the post type based on a WP_Query
     */
    public function guessPostType(WP_Query $query): ?string
    {
        if (!empty($query->query_vars['post_type'])) {
            return collect($query->query_vars['post_type'])->first();
        }

        $queriedObject = $query->get_queried_object();

        if ($queriedObject instanceof \WP_Post) {
            return $queriedObject->post_type;
        }

        if ($queriedObject instanceof \WP_Post_Type) {
            return $queriedObject->name;
        }

        if ($queriedObject instanceof \WP_Term) {
            $tax = get_taxonomy($queriedObject->taxonomy);
            if ($tax->public) {
                return collect($tax->object_type)->first();
            }
        }

        return null;
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
                $newColumns['acfe:location'] = __('Location', 'festival-perspectives-events');
                $newColumns['acfe:dates'] = __('Dates', 'festival-perspectives-events');
                break;
            case PostTypes::RECURRENCE:
                $newColumns['acfe:location'] = __('Location', 'festival-perspectives-events');
                $newColumns['acfe:date_and_time'] = __('Date', 'festival-perspectives-events');
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
        if ($postType !== PostTypes::EVENT) {
            return;
        }

        $years = $this->utils->getYears($postType, ['publish']);
        if (empty($years)) {
            return;
        }

        $selectedYear = $this->getQueriedYear(null);

        ob_start(); ?>
        <select name="year">

            <?php foreach ($years as $year) : ?>
                <option <?= attr([
                    'selected' => $year === $selectedYear,
                    'value' => $year,
                ]) ?>>
                    <?php
                        echo $year === $selectedYear
                        ? sprintf(__('Year %s', 'festival-perspectives-events'), $year)
                        : $year;
                ?>
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
        if ($this->guessPostType($query) !== PostTypes::EVENT) {
            return false;
        }

        return $this->getQueriedYear($query) < (int) current_time('Y');
    }

    /**
     * Check if all dates of an event are in the past
     */
    public function isEventInThePast(int|WP_Post $post): bool
    {
        if (!$this->isEvent($post)) {
            return false;
        }

        return collect($this->getEventDates($post))
            ->every(fn($eventDate) => $this->isInThePast($eventDate->toMySQLString()));
    }

    /**
     * Check if a given date is in the past.
     *
     * Dates from ACF are stored as naive strings in the Europe/Berlin timezone
     * (e.g. "2026-06-14 12:00:00") with no timezone info attached. To correctly
     * compare them to "now", we must express "now" in the same naive Berlin format
     * rather than doing any timezone conversion.
     */
    public function isInThePast(string $date): bool
    {
        return $date < current_time(self::MYSQL_DATE_TIME_FORMAT);
    }

    /**
     * Get all expired events.
     * Uses a raw SQL query to make sure all candidates are found.
     *
     * @return list<int>
     */
    public function getExpiredEvents(string $postType = PostTypes::EVENT): array
    {
        if (!in_array($postType, [PostTypes::EVENT, PostTypes::RECURRENCE], true)) {
            throw new InvalidArgumentException(sprintf('Invalid post type requested: %s', $postType));
        }

        $wpdb = $this->utils->wpdb();

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND pm.meta_key = %s
               AND CAST(pm.meta_value AS DATE) < %s",
            $postType,
            EventFields::DATE_AND_TIME,
            current_time('Y') . '-01-01',
        ));

        return collect($results)->map(absint(...))->all();
    }

    /**
     * Set further dates for an event manually
     *
     * @param list<string> $dates
     *
     * @return list<string> the mysql-formatted dates
     */
    public function setFurtherDates(int|WP_Post $event, array $dates): array
    {
        if (!$event = $this->getEvent($event)) {
            throw new Exception(sprintf('Please provide an event'));
        }

        $subFieldKey = Fields::key(EventFields::FURTHER_DATES_DATE_AND_TIME);

        $rows = collect($dates)
            ->values()
            ->map(fn($date) => \strtotime($date))
            ->filter(fn($timestamp) => !!$timestamp)
            ->sort()
            ->map(fn($timestamp) => [$subFieldKey => \date(Core::MYSQL_DATE_TIME_FORMAT, $timestamp)])
            ->all();

        update_field(
            Fields::key(EventFields::FURTHER_DATES),
            $rows,
            $event,
        );

        return collect($rows)
            ->pluck($subFieldKey)
            ->values()
            ->all();
    }
}

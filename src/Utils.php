<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use InvalidArgumentException;
use RuntimeException;
use WP_CLI;
use WP_Post;
use WP_Query;
use wpdb;

final class Utils extends Singleton
{
    protected function __construct()
    {
        $this->addHooks();
    }

    private function addHooks(): void
    {
        add_filter('posts_clauses', $this->applyGroupByClause(...), 1000, 2);
    }

    /**
     * Inject the custom GroupByMetaClause into WP posts clauses
     */
    private function applyGroupByClause(array $clauses, WP_Query $query): array
    {
        /** @var ?GroupByMetaClause $groupByClause */
        $groupByClause = $query->get('acfe:groupby-clause');

        if (!$groupByClause) {
            return $clauses;
        }

        /** Only apply the clause once */
        $query->set('acfe:groupby-clause', '');

        $alias = collect($query->meta_query->get_clauses())
            ->first(fn($clause) => $clause['key'] === $groupByClause->key)['alias']
            ?? throw new RuntimeException("No post clause alias found for '{$groupByClause->key}'");

        $clauses['fields'] = str_replace('{alias}', $alias, $groupByClause->expression);
        $clauses['groupby'] = $groupByClause->groupby;

        return $clauses;
    }

    /**
     * Access the global wpdb instance
     */
    public function wpdb(): wpdb
    {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Access the main WP_Query instance
     */
    public function getMainQuery(): WP_Query
    {
        global $wp_query;
        return $wp_query;
    }

    /**
     * Get all years for which events exist
     */
    public function getYearsWithEvents(WP_Query $query): array
    {
        if (!$this->isEventPostType($query->get('post_type'))) {
            return [];
        }

        $results = $this->runUnfiltered(function () use ($query) {
            add_filter('posts_clauses', $this->applyGroupByClause(...), 1000, 2);

            $q = new WP_Query([
                'post_type' => $query->get('post_type'),
                'post_status' => $query->get('post_status'),
                'posts_per_page' => -1,
                /** Respect the current polylang language, if set: */
                'lang' => $query->get('lang'),
                'orderby' => [EventFields::DATE_AND_TIME => 'desc'],
                'meta_query' => [
                    EventFields::DATE_AND_TIME => [
                        'key' => EventFields::DATE_AND_TIME,
                        'compare' => 'EXISTS',
                    ],
                ],
                'acfe:groupby-clause' => new GroupByMetaClause(
                    key: EventFields::DATE_AND_TIME,
                    groupby: 'year',
                    expression: 'YEAR({alias}.meta_value) as year',
                ),
            ]);

            return $q->posts;
        });

        return collect($results)
            ->pluck('year')
            ->map($this->parseYear(...))
            ->values()
            ->all();
    }

    /**
     * Parse an unknown variable as a year, returning the year as an integer if valid, otherwise null.
     */
    public function parseYear(mixed $var): ?string
    {
        $str = trim((string) $var);

        if (
            is_numeric($var)
            && preg_match('/^\d{4}$/', $str) === 1
            && (int) $str >= 1000
            && (int) $str <= 9999
        ) {
            return (string) $str;
        }

        return null;
    }

    /**
     * Get the currently queried year. Also handle searches for years.
     */
    public function getQueriedYear(WP_Query $query): ?string
    {
        /** try from the query */
        return $this->parseYear($query->get('acfe:year'))
            ?? $this->parseYear($query->get('year'))
            ?? $this->parseYear($query->get('s'))
            /** fall back to the last year with events */
            ?? $this->getYearsWithEvents($query)[0]
            ?? null;
    }

    /**
     * Get the raw SQL query from a WP_Query, formatted for readability
     */
    public function getFormattedSql(?WP_Query $query = null): string
    {
        $query ??= $this->getMainQuery();
        $request = $query->request;

        /** remove whitespace at the beginning of each line */
        $request = preg_replace('/^\s+/m', '', $request);
        /** remove duplicate whitespaces */
        $request = preg_replace('/ +/m', ' ', $request);
        /** make sure clauses start new lines */
        $request = preg_replace('/(?<!^)\s(SELECT|FROM|AND|WHERE|INNER JOIN|LEFT JOIN|ORDER BY)\s/m', "\n$1 ", $request);
        /** remove whitespace at the end of each line */
        $request = preg_replace('/\s+$/m', '', $request);

        return $request;
    }

    /**
     * Currently running WP CLI?
     */
    public function isWpCli(): bool
    {
        return defined('WP_CLI') && WP_CLI; // @phpstan-ignore booleanAnd.rightAlwaysTrue
    }

    /**
     * Check if a string is filled
     */
    public function isFilledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Add a command, prefix it with "events"
     */
    public function addCommand(string $name, callable $callable, array $args = [])
    {
        if ($this->isWpCli()) {
            WP_CLI::add_command("fpe {$name}", $callable, $args);
        }
    }

    /**
     * Returns the field key for a given field name
     */
    public static function fieldKey(string $fieldName): string
    {
        return "field_$fieldName";
    }

    /**
     * Add words to a string if that string doesn't already contain them
     */
    public function addWords(string $str, ?string $words = ''): string
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
     * Check if a given date is in the past.
     *
     * Dates from ACF are stored as naive strings in the Europe/Berlin timezone
     * (e.g. "2026-06-14 12:00:00") with no timezone info attached. To correctly
     * compare them to "now", we must express "now" in the same naive Berlin format
     * rather than doing any timezone conversion.
     */
    public function isInThePast(string $dateString): bool
    {
        if (!fpe()->utils->isMySQLDateFormat($dateString)) {
            throw new InvalidArgumentException(sprintf('Invalid date format: %s', esc_html($dateString)));
        }
        return $dateString < current_time(FPEvents::MYSQL_DATE_TIME_FORMAT);
    }

    /**
     * Check if a date string is in the format 'Y-m-d H:i:s'
     */
    public function isMySQLDateFormat(string $dateString): bool
    {
        return $this->isDateFormat($dateString, FPEvents::MYSQL_DATE_TIME_FORMAT);
    }

    /**
     * Check if a provided date string conforms to an expected format
     */
    private function isDateFormat(string $dateString, string $expectedFormat): bool
    {
        $datetime = \DateTime::createFromFormat($expectedFormat, $dateString);
        return $datetime && $datetime->format($expectedFormat) === $dateString;
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
     * Check if the post status of a post can be considered "visible"
     */
    public function isVisiblePostStatus(int $postID)
    {
        return collect(['publish', 'future', 'private'])->contains(get_post_status($postID));
    }

    /**
     * Get the original event, either from an event or a recurrence
     */
    public function getOriginalEvent(mixed $post): ?WP_Post
    {
        if (!$event = $this->getEvent($post)) {
            return null;
        }
        return $event->post_parent ? get_post($event->post_parent) : $event;
    }

    /**
     * Get an event, only if the provided post/post_id is an event
     */
    public function getEvent(mixed $post): ?WP_Post
    {
        $post = get_post($post);
        return $this->isEvent($post) ? $post : null;
    }

    /**
     * Check if a post is an event
     */
    public function isEvent(mixed $post): bool
    {
        if (!$postID = $this->getPostID($post)) {
            return false;
        }
        return $this->isEventPostType(get_post_type($postID));
    }

    /**
     * Check if a post is an event
     */
    public function isEventPostType(string|array $postType): bool
    {
        // Normalize to a sorted array
        $types = (array) $postType;
        sort($types);

        $allowed = [
            [ PostTypes::EVENT ],
            [ PostTypes::RECURRENCE ],
            [ PostTypes::EVENT, PostTypes::RECURRENCE ], // already sorted
        ];

        return in_array($types, $allowed, true);
    }

    /**
     * Check if a post is a location
     */
    public function isLocation(mixed $post): bool
    {
        if (!$postID = $this->getPostID($post)) {
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
     * Get posts, unfiltered, with a few defaults
     */
    public function getPostsUnfiltered(array $args): array
    {
        $args = array_replace_recursive([
            'fields' => 'ids',
            'posts_per_page' => -1,
            'lang' => null,
            'post_status' => get_post_stati(),
            'suppress_filters' => true,
        ], $args);

        return $this->runUnfiltered(fn() => get_posts($args));
    }

    /**
     * Run a callback while temporarily disabling all filters
     *
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public function runUnfiltered(callable $callback)
    {
        global $wp_filter;

        // 1. Store all current filters
        $__wp_filters = $wp_filter;

        // 2. Clear all filters (and actions — they're stored the same way)
        $wp_filter = [];

        // 3. Run your callbac unaffected by any hooks
        $result = $callback();

        // 4. Restore filters
        $wp_filter = $__wp_filters;

        return $result;
    }

    /**
     * Get all events in the past.
     *
     * @return list<int>
     */
    public function getPastRecurrences(): array
    {
        return $this->getPostsUnfiltered([
            'post_type' => PostTypes::RECURRENCE,
            'orderby' => [EventFields::DATE_AND_TIME => 'desc'],
            'meta_query' => [
                EventFields::DATE_AND_TIME => [
                    'key' => EventFields::DATE_AND_TIME,
                    'type' => 'DATETIME',
                    'value' => current_time('mysql'),
                    'compare' => '<',
                ],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use WP_CLI;
use WP_Query;
use wpdb;

final class Utils extends Singleton
{
    protected function __construct() {}

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
     *
     * @param string|list<string> $postStatus
     * @return list<string>
     */
    public function getYearsWithEvents(
        string|array $postTypes = PostTypes::EVENT,
        string|array $postStatus = 'publish',
    ): array {
        $wpdb = $this->wpdb();

        $postTypes = collect($postTypes)
            ->filter($this->isFilledString(...))
            ->filter(PostTypes::postTypeIsEventOrRecurrence(...))
            ->all();

        if (empty($postTypes)) {
            return [];
        }

        $postStati = collect($postStatus)
            ->filter($this->isFilledString(...))
            ->all();

        $metaKey = EventFields::DATE_AND_TIME;

        $placeholders = fn(array $values) => collect($values)
            ->map(fn() => '%s')
            ->implode(', ');

        $statusClause = !empty($postStati)
            ? "AND p.post_status IN ({$placeholders($postStati)})"
            : '';

        $query = $wpdb->prepare(
            <<<SQL
            SELECT DISTINCT YEAR(pm.meta_value)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
                AND pm.meta_key = '%s'
            WHERE p.post_type IN ({$placeholders($postTypes)})
            {$statusClause}
            ORDER BY pm.meta_value DESC
            SQL,
            $metaKey,
            ...[
                ...$postTypes,
                ...$postStati,
            ],
        );

        return collect($wpdb->get_col($query))
            ->map($this->parseYear(...))
            ->filter()
            ->all();
    }

    /**
     * Get the last year that contains posts from the status
     */
    public function getLastYearWithEvents(WP_Query $query): ?string
    {
        $postStatus = trim($query->get('post_status'));

        if (!is_admin() && !$postStatus) {
            $postStatus = 'publish';
        }

        return $this->getYearsWithEvents(
            $query->get('post_type'),
            $postStatus,
        )[0] ?? null;
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
            ?? $this->getLastYearWithEvents($query);
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
    public function addWPCLICommand(string $name, callable $callable, array $args = [])
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
    public function isInThePast(string $date): bool
    {
        return $date < current_time(FPEvents::MYSQL_DATE_TIME_FORMAT);
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
}

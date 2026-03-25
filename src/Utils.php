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
     * @param string|list<string> $postTypes
     * @param list<string> $postStati pass `null` explicitly to ignore the post status
     * @return list<int>
     */
    public function getYears(string|array $postTypes, array $postStati): array
    {
        $wpdb = $this->wpdb();

        $postTypes = (array) $postTypes;

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
            ->map(absint(...))
            ->all();
    }

    /**
     * Parse an unknown variable as a year, returning the year as an integer if valid, otherwise null.
     */
    public function parseYear(mixed $var): ?int
    {
        $str = trim((string) $var);

        if (
            is_numeric($var)
            && preg_match('/^\d{4}$/', $str) === 1
            && (int) $str >= 1000
            && (int) $str <= 9999
        ) {
            return (int) $str;
        }

        return null;
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
}

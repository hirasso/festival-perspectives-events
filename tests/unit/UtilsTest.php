<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Unit;

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FPEvents;
use Hirasso\WP\FPEvents\PostTypes;
use Hirasso\WP\FPEvents\Utils;
use WP_Query;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class UtilsTest extends TestCase
{
    private Utils $utils;

    public function setUp(): void
    {
        parent::setUp();
        $this->utils = Utils::instance();
    }

    /**
     * Alters the query to query events way in the past.
     * This should be IGNORED because of the `unfiltered` function
     */
    private function modify_query(WP_Query $query): void
    {
        $query->query_vars = array_replace_recursive($query->query_vars, [
            'meta_query' => [
                EventFields::DATE_AND_TIME => [
                    'key' => EventFields::DATE_AND_TIME,
                    'type' => 'DATETIME',
                    'compare' => '<',
                    'value' => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2020-01-01')),
                ],
            ],
        ]);
    }

    public function test_unfiltered_query()
    {
        $this->factory()->post->create_many(3);

        $queryPosts = fn() => (new WP_Query(['post_type' => 'any']))->posts;

        $this->assertCount(3, $queryPosts());

        add_action('pre_get_posts', $this->modify_query(...));

        /** 1. the hook should be active */
        $this->assertCount(0, $queryPosts());
        /** 2. the hook should be skipped */
        $this->assertCount(3, $this->utils->unfiltered($queryPosts));
        /** 3. the hook should be active again */
        $this->assertCount(0, $queryPosts());
    }

    public function test_get_years_with_events()
    {
        $events = $this->factory()->post->create_many(20, ['post_type' => PostTypes::EVENT]);
        foreach ($events as $index => $event) {
            $year = 2030 - $index;
            update_post_meta(
                $event,
                EventFields::DATE_AND_TIME,
                date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime("$year-01-01")),
            );
        }

        add_action('pre_get_posts', $this->modify_query(...));

        $result = $this->utils->getYearsWithEvents(new WP_Query(['post_type' => PostTypes::EVENT]));

        $expected = collect(range(2030, 2011))->map(strval(...))->all();

        $this->assertSame($result, $expected);
    }

    public function test_get_years_with_events_only_published()
    {
        $this->factory()->post->create([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2030-01-01'))],
            'post_status' => 'draft',
        ]);
        $this->factory()->post->create([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2025-01-01'))],
        ]);

        $this->assertSame(
            $this->utils->getYearsWithEvents(new WP_Query(['post_type' => PostTypes::EVENT])),
            ["2025"],
        );
    }
}

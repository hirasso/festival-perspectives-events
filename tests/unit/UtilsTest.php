<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Unit;

use Hirasso\WP\FPEvents\PostTypes;
use Hirasso\WP\FPEvents\Utils;
use WP_Query;

class UtilsTest extends FPEventsTestCase
{
    private Utils $utils;

    public function setUp(): void
    {
        parent::setUp();
        $this->utils = Utils::instance();
    }

    /**
     * Alters the query so that it won't return anything
     * This should be IGNORED because of the `unfiltered` function
     */
    private function modify_query(WP_Query $query): void
    {
        $query->query_vars = array_replace_recursive($query->query_vars, [
            'meta_key' => 'something-nonexisting',
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

    public function test_get_unfiltered_years_with_events()
    {
        $years = collect(range(2030, 2011))->map(strval(...))->all();

        foreach ($years as $year) {
            $this->createEvent("$year-01-01");
        }

        /** would modify the query, should be ignored */
        add_action('pre_get_posts', $this->modify_query(...));

        $result = $this->utils->getYearsWithEvents(new WP_Query(['post_type' => PostTypes::EVENT]));
        $this->assertSame($result, $years);
    }

    public function test_get_years_with_events_post_status()
    {
        $this->createEvent('2030-01-01', ['post_status' => 'draft']);
        $this->createEvent('2025-01-01', ['post_status' => 'publish']);

        /** admin: include all post stati */
        set_current_screen('edit.php');
        $this->assertSame(
            $this->utils->getYearsWithEvents(new WP_Query(['post_type' => PostTypes::EVENT])),
            ["2030", "2025"],
        );

        /** frontend: exclude drafts */
        set_current_screen('front');
        $this->assertSame(
            $this->utils->getYearsWithEvents(new WP_Query(['post_type' => PostTypes::EVENT])),
            ["2025"],
        );
        set_current_screen('front');
    }
}

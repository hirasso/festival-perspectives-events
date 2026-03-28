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
     * Alters the query to request pages
     */
    private function modify_query(WP_Query $query): void
    {
        $query->set('post_type', 'page');
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
        $this->factory()->post->create([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2030-01-01'))],
        ]);
        $this->factory()->post->create([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2030-02-01'))],
        ]);
        $this->factory()->post->create([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2030-03-01'))],
        ]);
        $this->factory()->post->create([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2025-01-01'))],
        ]);
        $this->factory()->post->create([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime('2024-01-01'))],
        ]);

        add_action('pre_get_posts', $this->modify_query(...));

        $this->assertSame($this->utils->getYearsWithEvents(PostTypes::EVENT), ["2030", "2025", "2024"]);
    }
}

<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Unit;

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
    private function pre_get_posts(WP_Query $query): void
    {
        $query->set('post_type', 'page');
    }

    public function test_unfiltered_query()
    {
        $this->factory()->post->create_many(3);

        $queryPosts = fn() => (new WP_Query(['post_type' => 'any']))->posts;

        $this->assertCount(3, $queryPosts());

        add_action('pre_get_posts', $this->pre_get_posts(...));

        /** 1. the hook should be active */
        $this->assertCount(0, $queryPosts());
        /** 2. the hook should be skipped */
        $this->assertCount(3, $this->utils->unfiltered($queryPosts));
        /** 3. the hook should be active again */
        $this->assertCount(0, $queryPosts());

    }
}

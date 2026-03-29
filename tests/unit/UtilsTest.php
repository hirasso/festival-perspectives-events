<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Unit;

use Hirasso\WP\FPEvents\PostTypes;
use WP_Query;

uses(\WP_UnitTestCase::class);

/**
 * Alter the query so that it won't return anything
 * This should be IGNORED because of the `unfiltered` function
 */
function modifyQuery(WP_Query $query): void
{
    $query->query_vars = array_replace_recursive($query->query_vars, [
        'meta_key' => 'something-nonexisting',
    ]);
}

test('can run an unfiltered query', function () {
    factory()->post->create_many(3);

    $queryPosts = fn() => (new WP_Query(['post_type' => 'any']))->posts;

    expect($queryPosts())->toHaveCount(3);

    add_action('pre_get_posts', modifyQuery(...));

    /** 1. the hook should be active */
    expect($queryPosts())->toHaveCount(0);
    /** 2. the hook should be skipped */
    expect(utils()->unfiltered($queryPosts))->toHaveCount(3);
    /** 3. the hook should be active again */
    expect($queryPosts())->toHaveCount(0);
});

test('gets unfiltered years with events', function () {
    $years = collect(range(2030, 2011))->map(strval(...))->all();

    foreach ($years as $year) {
        createEvent("$year-01-01");
    }

    /** would modify the query, should be ignored */
    add_action('pre_get_posts', modifyQuery(...));

    $result = utils()->getYearsWithEvents(new WP_Query(['post_type' => PostTypes::EVENT]));
    expect($result)->toEqual($years);
});

test('adjusts the post status according to admin/frontend', function () {
    createEvent('2030-01-01', ['post_status' => 'draft']);
    createEvent('2025-01-01', ['post_status' => 'publish']);

    $query = new WP_Query(['post_type' => PostTypes::EVENT]);

    /** admin: include all post stati */
    set_current_screen('edit.php');
    $years = utils()->getYearsWithEvents($query);
    expect($years)->toEqual(["2030", "2025"]);

    /** frontend: exclude drafts */
    set_current_screen('front');
    $years = utils()->getYearsWithEvents($query);
    expect($years)->toEqual(["2025"]);

    set_current_screen('front');
});

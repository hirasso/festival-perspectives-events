<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Unit;

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FPEvents;
use Hirasso\WP\FPEvents\PostTypes;
use WP_Error;
use WP_Post;
use Yoast\WPTestUtils\WPIntegration\TestCase;

class FPEventsTestCase extends TestCase
{
    protected function createEvent(string $dateString, array $args = []): WP_Post|WP_Error
    {
        $args = array_replace_recursive([
            'post_type' => PostTypes::EVENT,
            'meta_input' => [EventFields::DATE_AND_TIME => date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime($dateString))],
        ], $args);

        return $this->factory()->post->create_and_get($args);
    }
}

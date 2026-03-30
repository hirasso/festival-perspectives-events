<?php

use Hirasso\WP\FPEvents\FieldGroups\EventFields;
use Hirasso\WP\FPEvents\FPEvents;
use Hirasso\WP\FPEvents\PostTypes;
use Hirasso\WP\FPEvents\Recurrences;
use Hirasso\WP\FPEvents\Utils;

uses(Tests\IntegrationTestCase::class)->in('Integration');

function recurreces()
{
    return Recurrences::instance();
}

function utils()
{
    return Utils::instance();
}

/**
 * Functional access to the factory
 */
function factory(): WP_UnitTest_Factory
{
    static $instance = null;
    $instance ??= new WP_UnitTest_Factory();
    return $instance;
}

/**
 * Convert any date string into a mysql date string
 */
function mysqlDate(string $dateString): string
{
    return date(FPEvents::MYSQL_DATE_TIME_FORMAT, strtotime($dateString));
}

/**
 * Create an event
 */
function createEvent(string $dateString, array $args = []): WP_Post|WP_Error
{
    $args = array_replace_recursive([
        'post_type' => PostTypes::EVENT,
        'meta_input' => [EventFields::DATE_AND_TIME => mysqlDate($dateString)],
    ], $args);

    return factory()->post->create_and_get($args);
}

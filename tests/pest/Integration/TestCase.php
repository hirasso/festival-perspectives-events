<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\Integration;

/**
 * Custom test case that patches WP_UnitTestCase for PHPUnit 11 compatibility.
 *
 * PHPUnit\Util\Test::parseTestMethodAnnotations() was removed in PHPUnit 11,
 * but wp-phpunit still calls it in expectDeprecated(). We override that method
 * to skip the annotation parsing (which we don't use) while keeping the WP hooks.
 *
 * @see https://github.com/wp-phpunit/wp-phpunit/issues/...
 */
class TestCase extends \WP_UnitTestCase
{
    public function expectDeprecated(): void
    {
        add_action('deprecated_function_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_argument_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_class_run', [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_file_included', [$this, 'deprecated_function_run'], 10, 4);
        add_action('deprecated_hook_run', [$this, 'deprecated_function_run'], 10, 4);
        add_action('doing_it_wrong_run', [$this, 'doing_it_wrong_run'], 10, 3);

        add_action('deprecated_function_trigger_error', '__return_false');
        add_action('deprecated_argument_trigger_error', '__return_false');
        add_action('deprecated_class_trigger_error', '__return_false');
        add_action('deprecated_file_trigger_error', '__return_false');
        add_action('deprecated_hook_trigger_error', '__return_false');
        add_action('doing_it_wrong_trigger_error', '__return_false');
    }
}

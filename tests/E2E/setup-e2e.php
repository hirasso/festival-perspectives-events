<?php

/**
 * Plugin Name: FPEvents Setup Plugin
 * Description: Initializes FPEvents in wp-env and creates content for e2e tests
 */

namespace Hirasso\WP\FPEvents\Tests\E2E;

/** Exit if accessed directly */
if (!\defined('ABSPATH')) {
    exit;
}

/** Load the composer autoloader from festival-perspectives-events.php */
require_once dirname(__DIR__) . '/festival-perspectives-events/vendor/autoload.php';

/**
 * Check what env we are currently in
 * @return null|"development"|"tests"
 */
function getCurrentEnv(): ?string
{
    $env = (\defined('ACFE_WP_ENV'))
        ? ACFE_WP_ENV
        : null;

    return \in_array($env, ['development', 'test'], true)
        ? $env
        : null;
}

add_action('after_setup_theme', function () {
    $whoops = new \Whoops\Run();
    $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler());
    $whoops->register();

    new Setup();
});

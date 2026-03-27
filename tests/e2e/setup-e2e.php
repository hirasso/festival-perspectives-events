<?php

/**
 * Plugin Name: FPEvents Setup Plugin
 * Description: Initializes FPEvents in wp-env and creates content for e2e tests
 */

namespace Hirasso\WP\FPEvents\Tests\End2End;

/** Exit if accessed directly */
if (!\defined('ABSPATH')) {
    exit;
}

/** Load the composer autoloader from festival-perspectives-events.php */
require_once dirname(__DIR__) . '/festival-perspectives-events/vendor/autoload.php';

new Setup();

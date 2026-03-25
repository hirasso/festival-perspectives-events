<?php

/**
 * Plugin Name: FPEvents Setup Plugin
 * Description: Initializes FPEvents in wp-env and creates content for e2e tests
 */

namespace Hirasso\WP\FPEvents\Tests\E2E;

use Hirasso\WP\FPEvents\PostTypes;

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

/** Overwrite pll post types */
add_filter('pll_get_post_types', PostTypes::all(...));

add_action('after_setup_theme', function () {

    PLL()->model->add_language([
        'name'       => 'Deutsch',
        'slug'       => 'de',
        'locale'     => 'de_DE',
        'rtl'        => false,
        'term_group' => 0,
    ]);

    PLL()->model->add_language([
        'name'       => 'Français',
        'slug'       => 'fr',
        'locale'     => 'fr_FR',
        'rtl'        => false,
        'term_group' => 1,
        'flag_code'  => 'fr',
    ]);

    new Setup();
});

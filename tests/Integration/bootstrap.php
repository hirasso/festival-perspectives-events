<?php

/** The wordpress plugins directory, deducted from this bootstrap file */

use Hirasso\WP\FPEvents\PostTypes;

$rootDir = \dirname(\dirname(__DIR__));

/** Load wp-env's config file in the container, but still use our own wp-phpunit */
\putenv('WP_PHPUNIT__TESTS_CONFIG=/wordpress-phpunit/wp-tests-config.php');

/** Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available */
require_once "$rootDir/vendor/autoload.php";

/** Provide access to the function `tests_add_filter()` */
require_once \getenv('WP_PHPUNIT__DIR') . '/includes/functions.php';

/** Manually load plugin files required for tests. */
tests_add_filter('muplugins_loaded', function () use ($rootDir) {
    /** required for polylang to load in tests */
    define('PLL_ADMIN', true);
    require_once "$rootDir/vendor/.wp/plugins/advanced-custom-fields-pro/acf.php";
    require_once "$rootDir/vendor/.wp/plugins/polylang/polylang.php";

    /** Overwrite pll post types */
    add_filter('pll_get_post_types', PostTypes::all(...));


}, 1);

tests_add_filter('plugins_loaded', function () {
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
    ]);
});

/** Start up the WP testing environment. */
require_once \getenv('WP_PHPUNIT__DIR') . '/includes/bootstrap.php';


/** Initialize FPEvents */
fp_events();

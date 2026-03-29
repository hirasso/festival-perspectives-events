<?php

use Hirasso\WP\FPEvents\FPEvents;
use Hirasso\WP\FPEvents\PostTypes;

/** the library's root dir */
$rootDir = \dirname(\dirname(__DIR__));

/** not really required when running pest, but who gives */
require_once("{$rootDir}/vendor/autoload.php");

/** @wordpress/env provides the required settings */
putenv('WP_PHPUNIT__TESTS_CONFIG=/wordpress-phpunit/wp-tests-config.php');
/** Use a separate db prefix for tests, so that we can develop against the same db */
putenv('WORDPRESS_TABLE_PREFIX=tests_');

/** provide access to the function `tests_add_filter()` */
require_once getenv('WP_PHPUNIT__DIR') . '/includes/functions.php';

/** Manually load plugin files required for tests. */
tests_add_filter('muplugins_loaded', function () use ($rootDir) {
    /** required for polylang to load in tests */
    define('PLL_ADMIN', true);
    require_once "$rootDir/vendor/.wp/plugins/advanced-custom-fields-pro/acf.php";
    require_once "$rootDir/vendor/.wp/plugins/polylang/polylang.php";

    /** Overwrite pll post types */
    add_filter('pll_get_post_types', PostTypes::all(...));

    /** Initialize FPEvents, as if activated from a theme */
    FPEvents::instance();
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

/** Manually load the Pest setup file */
require_once(__DIR__ . "/Pest.php");

<?php

/** The wordpress plugins directory, deducted from this bootstrap file */
$pluginsDir = \dirname(\dirname(\dirname(__DIR__)));

/** Load wp-env's config file in the container, but still use our own wp-phpunit */
\putenv('WP_PHPUNIT__TESTS_CONFIG=/wordpress-phpunit/wp-tests-config.php');

/** Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available */
require_once "$pluginsDir/festival-perspectives-events/vendor/autoload.php";

/** Provide access to the function `tests_add_filter()` */
require_once \getenv('WP_PHPUNIT__DIR') . '/includes/functions.php';

/** Manually load plugin files required for tests. */
\tests_add_filter('muplugins_loaded', function () use ($pluginsDir) {
    // define('PLL_ADMIN', true);
    require_once("$pluginsDir/advanced-custom-fields-pro/acf.php");
    require_once("$pluginsDir/polylang/polylang.php");
});

/**
 * Register festival-perspectives-events post types as Polylang-translatable before Polylang
 * bootstraps. Polylang registers the `language` taxonomy for object types
 * during PLL_Model construction (on plugins_loaded). Without this filter,
 * `clean_object_term_cache` won't clear `language_relationships` cache for
 * our post types, making pll_set_post_language / pll_get_post_language broken.
 */
// \tests_add_filter('pll_get_post_types', function (array $post_types): array {
//     $ours = ['acfe-event', 'acfe-recurrence', 'acfe-location'];
//     return array_merge($post_types, array_combine($ours, $ours));
// });

/** Start up the WP testing environment. */
require_once \getenv('WP_PHPUNIT__DIR') . '/includes/bootstrap.php';

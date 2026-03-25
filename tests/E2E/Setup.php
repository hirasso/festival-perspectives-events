<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\Tests\E2E;

use Hirasso\WP\FPEvents\PostTypes;

/** Exit if accessed directly */
if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Setup context to run e2e tests against
 */
final class Setup
{
    public function __construct()
    {
        /** Overwrite pll post types */
        add_filter('pll_get_post_types', PostTypes::all(...));

        add_action('after_setup_theme', $this->init(...));
    }

    private function init()
    {
        $this->setupPolylang();

        if (function_exists('fpe')) {
            fpe();
        }
    }

    private function setupPolylang()
    {
        if (!function_exists('PLL')) {
            return;
        }

        /** we don't need the polylang wizard */
        delete_transient('pll_activation_redirect');

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
    }

}

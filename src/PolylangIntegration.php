<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents;

use WP_Term;

/**
 * Polylang integration for FPEvents
 */
final class PolylangIntegration extends Singleton
{
    protected function __construct()
    {
        $this->addHooks();
    }

    private function addHooks(): void
    {
        if (!$this->isPolylangActive()) {
            return;
        }

        add_filter('term_link', $this->event_filter_term_link(...), 11, 2);
    }

    /**
     * Check if Polylang is active
     */
    private function isPolylangActive(): bool
    {
        return function_exists('PLL');
    }

    /**
     * Check if Polylang PRO is active
     */
    private function isPolylangProActive(): bool
    {
        return $this->isPolylangActive() && !empty(\PLL()->translate_slugs);
    }

    /**
     * Translate ACF Event Filter Links
     */
    public function event_filter_term_link(string $link, WP_Term $term): string
    {
        if (!$this->isPolylangProActive()) {
            return $link;
        }

        if ($term->taxonomy !== FPEvents::FILTER_TAXONOMY) {
            return $link;
        }

        if (!str_starts_with($link, get_post_type_archive_link(PostTypes::EVENT))) {
            return $link;
        }

        $postType = PostTypes::EVENT;
        $termLanguage = pll_get_term_language($term->term_id, \OBJECT);

        /** The term has no language: nothing to do. */
        if (!$termLanguage) {
            return $link;
        }

        $curlang = \PLL()->curlang;

        /** No current language, or the term's language is the same as the current language: nothing to do. */
        if ($curlang && $curlang->slug === $termLanguage->slug) {
            return $link;
        }

        /** @phpstan-ignore property.notFound */
        $link = \PLL()->translate_slugs->slugs_model->switch_translated_slug($link, $termLanguage, "archive_{$postType}");
        $link = \PLL()->links_model->switch_language_in_link($link, $termLanguage);

        return $link;
    }
}

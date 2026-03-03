<?php
/**
 * SEO Handler.
 *
 * Generates hreflang, canonical tags, and integrates with SEO plugins.
 *
 * @package Maharat\Multilingual\Seo
 */

namespace Maharat\Multilingual\Seo;

use Maharat\Multilingual\Core\LanguageManager;
use Maharat\Multilingual\Core\UrlRouter;

defined( 'ABSPATH' ) || exit;

class SeoHandler {

    private LanguageManager $language_manager;
    private UrlRouter $url_router;

    public function __construct( LanguageManager $language_manager, UrlRouter $url_router ) {
        $this->language_manager = $language_manager;
        $this->url_router       = $url_router;
    }

    /**
     * Initialise SEO hooks.
     */
    public function init(): void {
        // Output hreflang tags.
        add_action( 'wp_head', [ $this, 'output_hreflang_tags' ], 1 );

        // Canonical tag.
        add_action( 'wp_head', [ $this, 'output_canonical_tag' ], 1 );

        // HTML lang attribute.
        add_filter( 'language_attributes', [ $this, 'filter_language_attributes' ] );

        // Body class.
        add_filter( 'body_class', [ $this, 'add_language_body_class' ] );

        // Yoast SEO integration.
        add_filter( 'wpseo_canonical', [ $this, 'filter_yoast_canonical' ] );
        add_filter( 'wpseo_opengraph_url', [ $this, 'filter_yoast_canonical' ] );

        // Rank Math integration.
        add_filter( 'rank_math/frontend/canonical', [ $this, 'filter_yoast_canonical' ] );

        // Sitemap integration.
        add_filter( 'wpseo_sitemap_entry', [ $this, 'filter_sitemap_entry' ], 10, 3 );
        add_filter( 'rank_math/sitemap/entry', [ $this, 'filter_sitemap_entry' ], 10, 3 );

        // Open Graph locale.
        add_filter( 'wpseo_locale', [ $this, 'filter_og_locale' ] );
        add_filter( 'rank_math/locale', [ $this, 'filter_og_locale' ] );
    }

    /**
     * Output hreflang link tags.
     */
    public function output_hreflang_tags(): void {
        if ( is_admin() ) {
            return;
        }

        $post_id   = $this->get_current_post_id();
        $languages = $this->language_manager->get_languages();

        if ( empty( $languages ) ) {
            return;
        }

        echo "\n<!-- Maharat Multilingual hreflang tags -->\n";

        foreach ( $languages as $lang ) {
            if ( $post_id ) {
                $url = $this->url_router->get_translated_url( $post_id, $lang->code );
            } else {
                $url = $this->url_router->get_language_url( $lang->code );
            }

            printf(
                '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
                esc_attr( $lang->locale ),
                esc_url( $url )
            );
        }

        // x-default hreflang.
        $default_lang = $this->language_manager->get_default_language();
        if ( $post_id ) {
            $default_url = $this->url_router->get_translated_url( $post_id, $default_lang );
        } else {
            $default_url = $this->url_router->get_language_url( $default_lang );
        }

        printf(
            '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
            esc_url( $default_url )
        );

        echo "<!-- /Maharat Multilingual hreflang tags -->\n\n";
    }

    /**
     * Output canonical tag for the current language.
     */
    public function output_canonical_tag(): void {
        // Don't output if a SEO plugin already handles canonical.
        if ( $this->has_seo_plugin() ) {
            return;
        }

        if ( is_admin() ) {
            return;
        }

        $post_id = $this->get_current_post_id();
        $current = $this->language_manager->get_current_language();

        if ( $post_id ) {
            $url = $this->url_router->get_translated_url( $post_id, $current );
        } else {
            $url = $this->url_router->get_language_url( $current );
        }

        printf(
            '<link rel="canonical" href="%s" />' . "\n",
            esc_url( $url )
        );
    }

    /**
     * Filter the HTML lang attribute.
     *
     * @param string $attributes Existing attributes.
     * @return string
     */
    public function filter_language_attributes( string $attributes ): string {
        $lang = $this->language_manager->get_language( $this->language_manager->get_current_language() );
        if ( ! $lang ) {
            return $attributes;
        }

        // Replace lang= value.
        $attributes = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $lang->locale ) . '"', $attributes );

        // Set dir for RTL.
        if ( $lang->is_rtl ) {
            if ( ! str_contains( $attributes, 'dir=' ) ) {
                $attributes .= ' dir="rtl"';
            } else {
                $attributes = preg_replace( '/dir="[^"]*"/', 'dir="rtl"', $attributes );
            }
        } else {
            $attributes = preg_replace( '/dir="[^"]*"/', 'dir="ltr"', $attributes );
        }

        return $attributes;
    }

    /**
     * Add language-specific body classes.
     *
     * @param array $classes Body classes.
     * @return array
     */
    public function add_language_body_class( array $classes ): array {
        $current = $this->language_manager->get_current_language();
        $classes[] = 'maharat-lang-' . sanitize_html_class( $current );

        if ( $this->language_manager->is_rtl( $current ) ) {
            $classes[] = 'maharat-rtl';
        }

        return $classes;
    }

    /**
     * Filter Yoast / Rank Math canonical URL.
     *
     * @param string $canonical The canonical URL.
     * @return string
     */
    public function filter_yoast_canonical( string $canonical ): string {
        $post_id = $this->get_current_post_id();
        $current = $this->language_manager->get_current_language();

        if ( $post_id ) {
            return $this->url_router->get_translated_url( $post_id, $current );
        }

        return $canonical;
    }

    /**
     * Filter sitemap entries to include language variants.
     *
     * @param array  $url       Sitemap URL entry.
     * @param string $type      Post type.
     * @param object $post_or_term The post or term.
     * @return array
     */
    public function filter_sitemap_entry( $url, string $type = '', $post_or_term = null ): array {
        if ( ! is_array( $url ) || empty( $url['loc'] ) ) {
            return $url;
        }

        $languages = $this->language_manager->get_languages();
        if ( count( $languages ) < 2 ) {
            return $url;
        }

        // Add hreflang references.
        if ( ! isset( $url['languages'] ) ) {
            $url['languages'] = [];
        }

        foreach ( $languages as $lang ) {
            $url['languages'][ $lang->locale ] = $this->url_router->get_language_url( $lang->code );
        }

        return $url;
    }

    /**
     * Filter the Open Graph locale.
     *
     * @param string $locale The locale.
     * @return string
     */
    public function filter_og_locale( string $locale ): string {
        $lang = $this->language_manager->get_language( $this->language_manager->get_current_language() );
        return $lang ? $lang->locale : $locale;
    }

    // =========================================================================
    // Meta Box: Per-post SEO fields per language
    // =========================================================================

    /**
     * Save per-language SEO meta.
     *
     * @param int    $post_id Post ID.
     * @param string $lang    Language code.
     * @param array  $data    SEO data: title, description, slug.
     */
    public function save_post_seo_meta( int $post_id, string $lang, array $data ): void {
        if ( isset( $data['seo_title'] ) ) {
            update_post_meta( $post_id, "_maharat_seo_title_{$lang}", sanitize_text_field( $data['seo_title'] ) );
        }
        if ( isset( $data['seo_description'] ) ) {
            update_post_meta( $post_id, "_maharat_seo_description_{$lang}", sanitize_textarea_field( $data['seo_description'] ) );
        }
    }

    /**
     * Get per-language SEO meta.
     *
     * @param int    $post_id Post ID.
     * @param string $lang    Language code.
     * @return array{seo_title: string, seo_description: string}
     */
    public function get_post_seo_meta( int $post_id, string $lang ): array {
        return [
            'seo_title'       => get_post_meta( $post_id, "_maharat_seo_title_{$lang}", true ) ?: '',
            'seo_description' => get_post_meta( $post_id, "_maharat_seo_description_{$lang}", true ) ?: '',
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Detect if a major SEO plugin is active.
     */
    private function has_seo_plugin(): bool {
        return defined( 'WPSEO_VERSION' )
            || class_exists( '\\RankMath' )
            || class_exists( '\\AIOSEO\\Plugin\\AIOSEO' );
    }

    /**
     * Get the current post ID (works on singular and archives).
     */
    private function get_current_post_id(): int {
        if ( is_singular() ) {
            return get_queried_object_id();
        }
        return 0;
    }
}

<?php
/**
 * URL Router.
 *
 * Handles multilingual URL structures: directory, subdomain, query parameter.
 *
 * @package Maharat\Multilingual\Core
 */

namespace Maharat\Multilingual\Core;

defined( 'ABSPATH' ) || exit;

class UrlRouter {

    private LanguageManager $language_manager;

    /**
     * URL mode: 'directory', 'subdomain', or 'query'.
     */
    private string $url_mode = 'directory';

    /**
     * Re-entry guard for home_url filter.
     */
    private bool $filtering_home_url = false;

    public function __construct( LanguageManager $language_manager ) {
        $this->language_manager = $language_manager;
    }

    /**
     * Initialise URL rewriting hooks.
     */
    public function init(): void {
        $this->url_mode = get_option( 'maharat_url_mode', 'directory' );

        if ( 'directory' === $this->url_mode ) {
            add_action( 'init', [ $this, 'add_rewrite_rules' ], 1 );
            add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
            add_action( 'parse_request', [ $this, 'intercept_language_request' ], 0 );
            add_action( 'parse_request', [ $this, 'parse_language_from_request' ] );
            add_filter( 'request', [ $this, 'fix_post_query_vars' ] );
        }

        // Filter permalinks to include language prefix.
        add_filter( 'post_link', [ $this, 'filter_permalink' ], 10, 2 );
        add_filter( 'page_link', [ $this, 'filter_page_link' ], 10, 2 );
        add_filter( 'post_type_link', [ $this, 'filter_permalink' ], 10, 2 );
        add_filter( 'term_link', [ $this, 'filter_term_link' ], 10, 3 );

        // Redirect if necessary.
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ] );

        // Home URL filter.
        add_filter( 'home_url', [ $this, 'filter_home_url' ], 10, 4 );
    }

    /**
     * Add rewrite rules for directory-based language prefixes.
     */
    public function add_rewrite_rules(): void {
        $languages = $this->language_manager->get_languages();
        $show_default = get_option( 'maharat_show_default_lang', '0' );
        $default_lang = $this->language_manager->get_default_language();

        foreach ( $languages as $lang ) {
            // Skip default language prefix if configured to hide it.
            if ( $this->is_show_default_off( $show_default ) && $lang->code === $default_lang ) {
                continue;
            }

            $code = preg_quote( $lang->code, '/' );

            // Front page.
            add_rewrite_rule(
                "^{$code}/?$",
                'index.php?maharat_lang=' . $lang->code,
                'top'
            );

            // Pages and posts.
            add_rewrite_rule(
                "^{$code}/(.+?)/?$",
                'index.php?maharat_lang=' . $lang->code . '&pagename=$matches[1]',
                'top'
            );

            // Feed.
            add_rewrite_rule(
                "^{$code}/feed/(feed|rdf|rss|rss2|atom)/?$",
                'index.php?maharat_lang=' . $lang->code . '&feed=$matches[1]',
                'top'
            );
        }
    }

    /**
     * Register our query variable.
     *
     * @param array $vars Existing query vars.
     * @return array
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'maharat_lang';
        return $vars;
    }

    /**
     * Intercept parse_request to fix language-prefixed URL resolution.
     *
     * WordPress's rewrite rule matching may fail to resolve our custom rules
     * correctly (e.g., matching an attachment rule instead of our language rule)
     * because the `pagename` query var undergoes page-existence validation
     * inside `WP::parse_request()`. When no page exists for the slug, WP falls
     * through to less specific rules.
     *
     * This method runs at priority 0 on `parse_request`, checks if the request
     * path starts with a language prefix, and directly sets the correct query
     * vars on the WP object — bypassing WP's flawed resolution.
     *
     * @param \WP $wp The WordPress request object.
     */
    public function intercept_language_request( \WP $wp ): void {
        // Only act on front-end requests.
        if ( is_admin() ) {
            return;
        }

        // Get the raw request path (already stripped of home path by WP).
        $request = $wp->request;

        if ( empty( $request ) ) {
            return;
        }

        // Get active language codes.
        $languages = $this->language_manager->get_languages();
        $lang_codes = [];
        foreach ( $languages as $lang ) {
            $lang_codes[] = $lang->code;
        }

        // Check if request starts with a language code.
        $segments = explode( '/', $request );
        $first_segment = $segments[0] ?? '';

        if ( ! in_array( $first_segment, $lang_codes, true ) ) {
            return;
        }

        $lang_code = $first_segment;
        $rest_of_path = implode( '/', array_slice( $segments, 1 ) );

        // Already correctly matched our rule — nothing to fix.
        if ( ! empty( $wp->query_vars['maharat_lang'] ) && $wp->query_vars['maharat_lang'] === $lang_code ) {
            return;
        }

        // The language-prefixed URL was NOT matched by our rewrite rule.
        // Manually set the correct query vars.
        $wp->query_vars = [];
        $wp->query_vars['maharat_lang'] = $lang_code;

        if ( empty( $rest_of_path ) ) {
            // Front page: /ar/
            $wp->matched_rule  = "^{$lang_code}/?$";
            $wp->matched_query = "maharat_lang={$lang_code}";
            return;
        }

        // Has a slug path — need to determine the right query var.
        $wp->matched_rule  = "^{$lang_code}/(.+?)/?$";
        $wp->matched_query = "maharat_lang={$lang_code}&pagename={$rest_of_path}";

        // Now determine if slug is a page, post, CPT, or taxonomy.
        $this->resolve_slug_query_vars( $wp, $rest_of_path );
    }

    /**
     * Resolve the correct query vars for a slug.
     *
     * Sets the appropriate query var (pagename, name, post_type+name, or taxonomy)
     * on the WP object based on what the slug actually refers to.
     *
     * @param \WP    $wp   The WordPress request object.
     * @param string $slug The slug portion of the URL (after language prefix).
     */
    private function resolve_slug_query_vars( \WP $wp, string $slug ): void {
        // Handle paths with slashes (e.g., "product/slug", "category/slug", "parent-page/child-page").
        if ( str_contains( $slug, '/' ) ) {
            $parts     = explode( '/', $slug, 2 );
            $first     = $parts[0];
            $remainder = $parts[1];

            // Check if the first segment is a known CPT rewrite slug.
            $post_types = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
            foreach ( $post_types as $pt ) {
                $rewrite_slug = ltrim( $pt->rewrite['slug'] ?? $pt->name, '/' );
                if ( $first === $rewrite_slug ) {
                    $wp->query_vars['post_type'] = $pt->name;
                    $wp->query_vars['name']      = $remainder;
                    return;
                }
            }

            // Check if the first segment is a taxonomy rewrite slug.
            $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
            foreach ( $taxonomies as $tax ) {
                $rewrite_slug = ltrim( $tax->rewrite['slug'] ?? $tax->name, '/' );
                if ( $first === $rewrite_slug ) {
                    $wp->query_vars[ $tax->query_var ?: $tax->name ] = $remainder;
                    return;
                }
            }

            // Otherwise treat as hierarchical page path (e.g., "parent/child").
            $wp->query_vars['pagename'] = $slug;
            return;
        }

        // Simple slug (no slashes).
        global $wpdb;

        // Check if a published page exists with this slug.
        $page_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status = 'publish' LIMIT 1",
            $slug
        ) );

        if ( $page_exists ) {
            $wp->query_vars['pagename'] = $slug;
            return;
        }

        // Check if a published post exists with this slug.
        $post_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'post' AND post_status = 'publish' LIMIT 1",
            $slug
        ) );

        if ( $post_exists ) {
            $wp->query_vars['name'] = $slug;
            return;
        }

        // Check other public post types.
        $post_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment') LIMIT 1",
            $slug
        ) );

        if ( $post_row ) {
            $wp->query_vars['post_type'] = $post_row->post_type;
            $wp->query_vars['name']      = $slug;
            return;
        }

        // Fallback: assume page (let WP handle 404 naturally).
        $wp->query_vars['pagename'] = $slug;
    }

    /**
     * Parse language from the WP request object.
     *
     * @param \WP $wp The WordPress request object.
     */
    public function parse_language_from_request( \WP $wp ): void {
        if ( ! empty( $wp->query_vars['maharat_lang'] ) ) {
            $lang = sanitize_text_field( $wp->query_vars['maharat_lang'] );
            $this->language_manager->set_current_language( $lang );
        }
    }

    /**
     * Fix query vars for language-prefixed URLs.
     *
     * Our catch-all rewrite rule maps everything to `pagename`, but blog posts
     * need the `name` query var instead. This filter checks whether the slug
     * belongs to a published post and swaps the query var accordingly.
     *
     * Also handles WooCommerce product URLs (product/slug) and other custom
     * post types that use their own rewrite slugs.
     *
     * @param array $query_vars The matched query vars.
     * @return array
     */
    public function fix_post_query_vars( array $query_vars ): array {
        if ( empty( $query_vars['maharat_lang'] ) || empty( $query_vars['pagename'] ) ) {
            return $query_vars;
        }

        $slug = $query_vars['pagename'];

        // Check for custom post type archive/single patterns (e.g., "product/slug").
        if ( str_contains( $slug, '/' ) ) {
            $parts     = explode( '/', $slug, 2 );
            $cpt_slug  = $parts[0];
            $post_slug = $parts[1];

            // Check if the first segment is a known custom post type rewrite slug.
            $post_types = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
            foreach ( $post_types as $pt ) {
                $rewrite_slug = ltrim( $pt->rewrite['slug'] ?? $pt->name, '/' );
                if ( $cpt_slug === $rewrite_slug ) {
                    unset( $query_vars['pagename'] );
                    $query_vars['post_type'] = $pt->name;
                    $query_vars['name']      = $post_slug;
                    return $query_vars;
                }
            }

            // Check if the first segment is a taxonomy rewrite slug (e.g., "category/slug").
            $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
            foreach ( $taxonomies as $tax ) {
                $rewrite_slug = ltrim( $tax->rewrite['slug'] ?? $tax->name, '/' );
                if ( $cpt_slug === $rewrite_slug ) {
                    unset( $query_vars['pagename'] );
                    $query_vars[ $tax->query_var ?: $tax->name ] = $post_slug;
                    return $query_vars;
                }
            }

            // Otherwise leave as pagename (could be a nested page path like "parent/child").
            return $query_vars;
        }

        // Simple slug (no slashes) — check if it's a post rather than a page.
        global $wpdb;

        // First check if a published page exists with this slug.
        $page_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status = 'publish' LIMIT 1",
            $slug
        ) );

        if ( $page_exists ) {
            return $query_vars; // It's a real page, keep pagename.
        }

        // Check if a published post exists with this slug.
        $post_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'post' AND post_status = 'publish' LIMIT 1",
            $slug
        ) );

        if ( $post_exists ) {
            unset( $query_vars['pagename'] );
            $query_vars['name'] = $slug;
            return $query_vars;
        }

        // Check other public post types.
        $post_type = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment') LIMIT 1",
            $slug
        ) );

        if ( $post_type && $post_type !== 'page' ) {
            unset( $query_vars['pagename'] );
            $query_vars['post_type'] = $post_type;
            $query_vars['name']      = $slug;
            return $query_vars;
        }

        return $query_vars;
    }

    /**
     * Redirect to the correct language URL if needed.
     */
    public function maybe_redirect(): void {
        // If browser redirect is enabled and this is the first visit.
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $current = $this->language_manager->get_current_language();

        // Set cookie for subsequent visits.
        if ( ! headers_sent() ) {
            setcookie( 'maharat_language', $current, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
        }
    }

    /**
     * Filter post permalinks.
     *
     * @param string   $url  The original URL.
     * @param \WP_Post $post The post object.
     * @return string
     */
    public function filter_permalink( string $url, $post ): string {
        if ( 'directory' !== $this->url_mode ) {
            return $this->apply_non_directory_url( $url );
        }

        return $this->add_language_prefix( $url );
    }

    /**
     * Filter page link.
     *
     * @param string $url     The page URL.
     * @param int    $post_id The page ID.
     * @return string
     */
    public function filter_page_link( string $url, int $post_id ): string {
        if ( 'directory' !== $this->url_mode ) {
            return $this->apply_non_directory_url( $url );
        }

        return $this->add_language_prefix( $url );
    }

    /**
     * Filter term link.
     *
     * @param string   $url      The term URL.
     * @param \WP_Term $term     The term.
     * @param string   $taxonomy The taxonomy.
     * @return string
     */
    public function filter_term_link( string $url, $term, string $taxonomy ): string {
        if ( 'directory' !== $this->url_mode ) {
            return $this->apply_non_directory_url( $url );
        }

        return $this->add_language_prefix( $url );
    }

    /**
     * Filter home_url() to include language prefix.
     *
     * @param string      $url    The complete URL.
     * @param string      $path   The requested path.
     * @param string|null $scheme The scheme.
     * @param int|null    $blog_id Blog ID.
     * @return string
     */
    public function filter_home_url( string $url, string $path, ?string $scheme, ?int $blog_id ): string {
        // Prevent infinite recursion (add_language_prefix calls home_url internally).
        if ( $this->filtering_home_url ) {
            return $url;
        }

        // Don't filter in admin.
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $url;
        }

        $current = $this->language_manager->get_current_language();
        $default = $this->language_manager->get_default_language();
        $show_default = get_option( 'maharat_show_default_lang', '0' );

        // Don't add prefix for default language if option is off.
        if ( $current === $default && $this->is_show_default_off( $show_default ) ) {
            return $url;
        }

        if ( 'directory' === $this->url_mode ) {
            $this->filtering_home_url = true;
            $result = $this->add_language_prefix( $url, $current );
            $this->filtering_home_url = false;
            return $result;
        }

        return $url;
    }

    /**
     * Generate a translated URL for a given post in a specific language.
     *
     * @param int    $post_id       The post ID.
     * @param string $language_code Target language.
     * @return string
     */
    public function get_translated_url( int $post_id, string $language_code ): string {
        // Get translation of this post in the target language.
        global $wpdb;

        $group = $wpdb->get_var( $wpdb->prepare(
            "SELECT translation_group FROM {$wpdb->prefix}maharat_translations WHERE post_id = %d LIMIT 1",
            $post_id
        ) );

        if ( ! $group ) {
            return $this->get_language_url( $language_code );
        }

        $translated_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}maharat_translations WHERE translation_group = %d AND language_code = %s LIMIT 1",
            $group,
            $language_code
        ) );

        if ( $translated_id ) {
            // Temporarily switch language to generate correct URL.
            $original = $this->language_manager->get_current_language();
            $this->language_manager->set_current_language( $language_code );
            $url = get_permalink( (int) $translated_id );
            $this->language_manager->set_current_language( $original );
            return $url ?: $this->get_language_url( $language_code );
        }

        return $this->get_language_url( $language_code );
    }

    /**
     * Get the home URL for a specific language.
     *
     * @param string $code Language code.
     * @return string
     */
    public function get_language_url( string $code ): string {
        $home = $this->get_raw_home_url();

        switch ( $this->url_mode ) {
            case 'directory':
                $default = $this->language_manager->get_default_language();
                $show_default = get_option( 'maharat_show_default_lang', '0' );

                if ( $code === $default && $this->is_show_default_off( $show_default ) ) {
                    return $home;
                }

                return trailingslashit( $home ) . $code . '/';

            case 'subdomain':
                $parsed = wp_parse_url( $home );
                $host   = $parsed['host'] ?? '';
                // Remove existing language subdomain.
                $host = preg_replace( '/^[a-z]{2}\./', '', $host );
                return ( $parsed['scheme'] ?? 'https' ) . '://' . $code . '.' . $host . '/';

            case 'query':
            default:
                return add_query_arg( 'lang', $code, $home );
        }
    }

    /**
     * Get the raw home URL without triggering our home_url filter.
     *
     * Uses get_option('home') directly to avoid infinite recursion.
     *
     * @return string Home URL with trailing slash.
     */
    private function get_raw_home_url(): string {
        return trailingslashit( get_option( 'home' ) );
    }

    /**
     * Check if the "show default language in URL" setting is off.
     *
     * Handles all possible stored values: "0", "", false, null — all mean "off".
     * Only "1" (the explicit truthy string) means "on".
     *
     * @param mixed $value The option value from get_option().
     * @return bool True if show_default_lang is OFF.
     */
    private function is_show_default_off( $value ): bool {
        return '1' !== $value;
    }

    /**
     * Get current URL mode.
     *
     * @return string
     */
    public function get_url_mode(): string {
        return $this->url_mode;
    }

    /**
     * Add language prefix to a URL (directory mode).
     *
     * @param string      $url  The URL.
     * @param string|null $lang Language code (defaults to current).
     * @return string
     */
    private function add_language_prefix( string $url, ?string $lang = null ): string {
        $lang    = $lang ?? $this->language_manager->get_current_language();
        $default = $this->language_manager->get_default_language();
        $show    = get_option( 'maharat_show_default_lang', '0' );

        if ( $lang === $default && $this->is_show_default_off( $show ) ) {
            return $url;
        }

        $home = $this->get_raw_home_url();

        // Don't double-prefix.
        if ( str_contains( $url, "/{$lang}/" ) ) {
            return $url;
        }

        return str_replace( $home, $home . $lang . '/', $url );
    }

    /**
     * Apply subdomain or query parameter URL mode.
     *
     * @param string $url The URL.
     * @return string
     */
    private function apply_non_directory_url( string $url ): string {
        $current = $this->language_manager->get_current_language();
        $default = $this->language_manager->get_default_language();

        if ( $current === $default ) {
            return $url;
        }

        if ( 'subdomain' === $this->url_mode ) {
            $parsed = wp_parse_url( $url );
            $host   = $parsed['host'] ?? '';
            $host   = preg_replace( '/^[a-z]{2}\./', '', $host );
            $new_host = $current . '.' . $host;
            return str_replace( $parsed['host'] ?? '', $new_host, $url );
        }

        // Query mode.
        return add_query_arg( 'lang', $current, $url );
    }
}

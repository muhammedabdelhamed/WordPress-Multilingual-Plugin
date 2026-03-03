<?php
/**
 * Language Manager.
 *
 * Manages languages: CRUD, current language detection, caching.
 *
 * @package Maharat\Multilingual\Core
 */

namespace Maharat\Multilingual\Core;

defined( 'ABSPATH' ) || exit;

class LanguageManager {

    /**
     * In-memory cache of languages.
     *
     * @var array|null
     */
    private ?array $languages_cache = null;

    /**
     * Current language code.
     */
    private string $current_language = '';

    /**
     * Default language code.
     */
    private string $default_language = '';

    /**
     * Initialise: detect current language.
     */
    public function init(): void {
        $this->default_language = get_option( 'maharat_default_language', 'en' );
        $this->current_language = $this->detect_current_language();

        // Switch WP locale to match.
        add_filter( 'locale', [ $this, 'filter_locale' ] );
    }

    /**
     * Get all active languages.
     *
     * @param bool $include_inactive Whether to include inactive languages.
     * @return array
     */
    public function get_languages( bool $include_inactive = false ): array {
        if ( null !== $this->languages_cache ) {
            if ( $include_inactive ) {
                return $this->languages_cache;
            }
            return array_filter( $this->languages_cache, static fn( $l ) => (bool) $l->is_active );
        }

        // Try object cache first.
        $cached = wp_cache_get( 'maharat_all_languages', 'maharat' );
        if ( false !== $cached ) {
            $this->languages_cache = $cached;
            if ( $include_inactive ) {
                return $this->languages_cache;
            }
            return array_filter( $this->languages_cache, static fn( $l ) => (bool) $l->is_active );
        }

        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}maharat_languages ORDER BY sort_order ASC"
        );

        $this->languages_cache = $results ?: [];
        wp_cache_set( 'maharat_all_languages', $this->languages_cache, 'maharat', HOUR_IN_SECONDS );

        if ( $include_inactive ) {
            return $this->languages_cache;
        }
        return array_filter( $this->languages_cache, static fn( $l ) => (bool) $l->is_active );
    }

    /**
     * Get a single language by code.
     *
     * @param string $code Language code.
     * @return object|null
     */
    public function get_language( string $code ): ?object {
        $languages = $this->get_languages( true );
        foreach ( $languages as $lang ) {
            if ( $lang->code === $code ) {
                return $lang;
            }
        }
        return null;
    }

    /**
     * Add a new language.
     *
     * @param array $data Language data.
     * @return int|false Inserted ID or false on failure.
     */
    public function add_language( array $data ): int|false {
        global $wpdb;

        $defaults = [
            'code'        => '',
            'locale'      => '',
            'name'        => '',
            'native_name' => '',
            'is_rtl'      => 0,
            'is_default'  => 0,
            'is_active'   => 1,
            'flag'        => '',
            'sort_order'  => 0,
        ];

        $data = wp_parse_args( $data, $defaults );
        $data = $this->sanitize_language_data( $data );

        if ( empty( $data['code'] ) || empty( $data['name'] ) ) {
            return false;
        }

        // If setting as default, unset other defaults.
        if ( $data['is_default'] ) {
            $wpdb->update(
                "{$wpdb->prefix}maharat_languages",
                [ 'is_default' => 0 ],
                [ 'is_default' => 1 ]
            );
            update_option( 'maharat_default_language', $data['code'] );
        }

        $result    = $wpdb->insert( "{$wpdb->prefix}maharat_languages", $data );
        $insert_id = $wpdb->insert_id;

        $this->flush_cache();

        /**
         * Fires after a language is added.
         *
         * @param array $data The language data.
         * @param int   $id   The new language row ID.
         */
        do_action( 'maharat_language_added', $data, $insert_id );

        return $result ? (int) $insert_id : false;
    }

    /**
     * Update an existing language.
     *
     * @param string $code Language code.
     * @param array  $data Fields to update.
     * @return bool
     */
    public function update_language( string $code, array $data ): bool {
        global $wpdb;

        $data = $this->sanitize_language_data( $data );

        // Handle default language switch.
        if ( isset( $data['is_default'] ) && $data['is_default'] ) {
            $wpdb->update(
                "{$wpdb->prefix}maharat_languages",
                [ 'is_default' => 0 ],
                [ 'is_default' => 1 ]
            );
            update_option( 'maharat_default_language', $code );
        }

        $result = $wpdb->update(
            "{$wpdb->prefix}maharat_languages",
            $data,
            [ 'code' => $code ]
        );

        $this->flush_cache();

        do_action( 'maharat_language_updated', $code, $data );

        return false !== $result;
    }

    /**
     * Delete a language (only if not default).
     *
     * @param string $code Language code.
     * @return bool
     */
    public function delete_language( string $code ): bool {
        if ( $code === $this->get_default_language() ) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->delete(
            "{$wpdb->prefix}maharat_languages",
            [ 'code' => $code ]
        );

        $this->flush_cache();

        do_action( 'maharat_language_deleted', $code );

        return (bool) $result;
    }

    /**
     * Get the current language code.
     */
    public function get_current_language(): string {
        return $this->current_language ?: $this->default_language;
    }

    /**
     * Set the current language programmatically.
     */
    public function set_current_language( string $code ): void {
        $this->current_language = $code;
    }

    /**
     * Get the default language code.
     */
    public function get_default_language(): string {
        return $this->default_language;
    }

    /**
     * Check whether a code is the default language.
     */
    public function is_default_language( ?string $code = null ): bool {
        $code = $code ?? $this->get_current_language();
        return $code === $this->default_language;
    }

    /**
     * Check whether a language is RTL.
     */
    public function is_rtl( ?string $code = null ): bool {
        $code = $code ?? $this->get_current_language();
        $lang = $this->get_language( $code );
        return $lang ? (bool) $lang->is_rtl : false;
    }

    /**
     * Filter the WordPress locale to match the current language.
     */
    public function filter_locale( string $locale ): string {
        $lang = $this->get_language( $this->get_current_language() );
        return $lang ? $lang->locale : $locale;
    }

    /**
     * Detect the current language from URL / cookie / browser.
     */
    private function detect_current_language(): string {
        // 1. Query parameter.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['lang'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $lang = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
            if ( $this->get_language( $lang ) ) {
                return $lang;
            }
        }

        // 2. URL path prefix (directory mode).
        $url_mode = get_option( 'maharat_url_mode', 'directory' );
        if ( 'directory' === $url_mode ) {
            $request_uri = isset( $_SERVER['REQUEST_URI'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
                : '';
            $request_path = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

            // Strip WordPress home path (e.g. "wordpress") so we only look
            // at the portion *after* the WP install directory.
            $home_path = trim( wp_parse_url( get_option( 'home' ), PHP_URL_PATH ) ?? '', '/' );
            if ( '' !== $home_path && str_starts_with( $request_path, $home_path ) ) {
                $request_path = trim( substr( $request_path, strlen( $home_path ) ), '/' );
            }

            $segments = explode( '/', $request_path );
            if ( ! empty( $segments[0] ) && $this->get_language( $segments[0] ) ) {
                return $segments[0];
            }

            // In directory mode, if URL has no language prefix, this is the
            // default language — skip cookie/browser detection entirely.
            $show_default = get_option( 'maharat_show_default_lang', '0' );
            if ( '1' !== $show_default ) {
                return $this->default_language;
            }
        }

        // 3. Subdomain mode.
        if ( 'subdomain' === $url_mode ) {
            $host = isset( $_SERVER['HTTP_HOST'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
                : '';
            $parts = explode( '.', $host );
            if ( count( $parts ) > 2 && $this->get_language( $parts[0] ) ) {
                return $parts[0];
            }
        }

        // 4. Cookie.
        if ( ! empty( $_COOKIE['maharat_language'] ) ) {
            $lang = sanitize_text_field( wp_unslash( $_COOKIE['maharat_language'] ) );
            if ( $this->get_language( $lang ) ) {
                return $lang;
            }
        }

        // 5. Browser accept-language (if enabled).
        if ( get_option( 'maharat_browser_redirect', '0' ) === '1' && ! is_admin() ) {
            $browser_lang = $this->detect_browser_language();
            if ( $browser_lang ) {
                return $browser_lang;
            }
        }

        return $this->default_language;
    }

    /**
     * Detect language from Accept-Language header.
     */
    private function detect_browser_language(): ?string {
        if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
            return null;
        }

        $accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
        $langs  = $this->get_languages();
        $codes  = array_map( static fn( $l ) => $l->code, $langs );

        // Parse Accept-Language header.
        preg_match_all( '/([a-z]{1,8}(?:-[a-z]{1,8})?)\s*(?:;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $accept, $matches );

        if ( empty( $matches[1] ) ) {
            return null;
        }

        $preferences = [];
        foreach ( $matches[1] as $i => $tag ) {
            $quality = isset( $matches[2][ $i ] ) && '' !== $matches[2][ $i ]
                ? (float) $matches[2][ $i ]
                : 1.0;
            $preferences[ strtolower( substr( $tag, 0, 2 ) ) ] = $quality;
        }

        arsort( $preferences );

        foreach ( array_keys( $preferences ) as $code ) {
            if ( in_array( $code, $codes, true ) ) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Sanitize language data.
     */
    private function sanitize_language_data( array $data ): array {
        $sanitized = [];

        $string_fields = [ 'code', 'locale', 'name', 'native_name', 'flag', 'date_format', 'time_format' ];
        foreach ( $string_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $sanitized[ $field ] = sanitize_text_field( $data[ $field ] );
            }
        }

        $int_fields = [ 'is_rtl', 'is_default', 'is_active', 'sort_order' ];
        foreach ( $int_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $sanitized[ $field ] = absint( $data[ $field ] );
            }
        }

        return $sanitized;
    }

    /**
     * Flush all language caches.
     */
    public function flush_cache(): void {
        $this->languages_cache = null;
        wp_cache_delete( 'maharat_all_languages', 'maharat' );
        delete_transient( 'maharat_languages_cache' );
    }
}

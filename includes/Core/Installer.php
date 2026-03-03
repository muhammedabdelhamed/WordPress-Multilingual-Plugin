<?php
/**
 * Database Installer.
 *
 * Creates and manages custom database tables on activation.
 *
 * @package Maharat\Multilingual\Core
 */

namespace Maharat\Multilingual\Core;

defined( 'ABSPATH' ) || exit;

class Installer {

    /**
     * Run on plugin activation.
     */
    public function activate(): void {
        $this->create_tables();
        $this->seed_defaults();
        update_option( 'maharat_db_version', MAHARAT_DB_VERSION );
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation.
     */
    public function deactivate(): void {
        // Clean up transients.
        delete_transient( 'maharat_languages_cache' );
    }

    /**
     * Create custom database tables.
     */
    private function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = [];

        // Languages table.
        $sql[] = "CREATE TABLE {$wpdb->prefix}maharat_languages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(10) NOT NULL,
            locale VARCHAR(20) NOT NULL,
            name VARCHAR(100) NOT NULL,
            native_name VARCHAR(100) NOT NULL,
            is_rtl TINYINT(1) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            flag VARCHAR(10) DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            date_format VARCHAR(50) DEFAULT '',
            time_format VARCHAR(50) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY is_active (is_active),
            KEY is_default (is_default)
        ) {$charset_collate};";

        // Translations table (links posts across languages).
        $sql[] = "CREATE TABLE {$wpdb->prefix}maharat_translations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            translation_group BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            language_code VARCHAR(10) NOT NULL,
            post_type VARCHAR(50) NOT NULL DEFAULT 'post',
            source_id BIGINT UNSIGNED DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY post_language (post_id, language_code),
            KEY translation_group (translation_group),
            KEY language_code (language_code),
            KEY post_type (post_type),
            KEY source_id (source_id)
        ) {$charset_collate};";

        // Taxonomy translations.
        $sql[] = "CREATE TABLE {$wpdb->prefix}maharat_taxonomy_translations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            translation_group BIGINT UNSIGNED NOT NULL,
            term_taxonomy_id BIGINT UNSIGNED NOT NULL,
            language_code VARCHAR(10) NOT NULL,
            taxonomy VARCHAR(50) NOT NULL,
            source_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY term_language (term_taxonomy_id, language_code),
            KEY translation_group (translation_group),
            KEY language_code (language_code),
            KEY taxonomy (taxonomy)
        ) {$charset_collate};";

        // String translations.
        $sql[] = "CREATE TABLE {$wpdb->prefix}maharat_string_translations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            string_name VARCHAR(255) NOT NULL,
            string_domain VARCHAR(100) NOT NULL DEFAULT 'default',
            string_value TEXT NOT NULL,
            language_code VARCHAR(10) NOT NULL,
            translation TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'untranslated',
            context VARCHAR(255) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY string_lang (string_name(191), string_domain, language_code),
            KEY language_code (language_code),
            KEY status (status),
            KEY string_domain (string_domain)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Seed default settings.
     */
    private function seed_defaults(): void {
        // Only seed if no languages exist yet.
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}maharat_languages" );

        if ( $count > 0 ) {
            return;
        }

        // Detect WP locale.
        $locale = get_locale();
        $code   = substr( $locale, 0, 2 );

        $defaults = $this->get_default_languages();

        // Set the matching language as default, or fall back to English.
        $default_set = false;
        foreach ( $defaults as &$lang ) {
            if ( $lang['code'] === $code ) {
                $lang['is_default'] = 1;
                $lang['is_active']  = 1;
                $default_set        = true;
            }
        }
        unset( $lang );

        if ( ! $default_set ) {
            $defaults[0]['is_default'] = 1;
            $defaults[0]['is_active']  = 1;
        }

        foreach ( $defaults as $lang ) {
            $wpdb->insert( "{$wpdb->prefix}maharat_languages", $lang );
        }

        // Default settings.
        $default_settings = [
            'maharat_url_mode'         => 'directory',
            'maharat_default_language'  => $default_set ? $code : 'en',
            'maharat_show_default_lang' => '0',
            'maharat_browser_redirect'  => '0',
            'maharat_auto_translate'    => '0',
            'maharat_translation_api'   => '',
            'maharat_api_key'           => '',
        ];

        foreach ( $default_settings as $key => $value ) {
            if ( false === get_option( $key ) ) {
                update_option( $key, $value );
            }
        }
    }

    /**
     * Predefined language set.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_default_languages(): array {
        return [
            [
                'code'        => 'en',
                'locale'      => 'en_US',
                'name'        => 'English',
                'native_name' => 'English',
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 1,
                'flag'        => 'us',
                'sort_order'  => 0,
            ],
            [
                'code'        => 'ar',
                'locale'      => 'ar',
                'name'        => 'Arabic',
                'native_name' => "\xD8\xA7\xD9\x84\xD8\xB9\xD8\xB1\xD8\xA8\xD9\x8A\xD8\xA9",
                'is_rtl'      => 1,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'sa',
                'sort_order'  => 1,
            ],
            [
                'code'        => 'fr',
                'locale'      => 'fr_FR',
                'name'        => 'French',
                'native_name' => "Fran\xC3\xA7ais",
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'fr',
                'sort_order'  => 2,
            ],
            [
                'code'        => 'es',
                'locale'      => 'es_ES',
                'name'        => 'Spanish',
                'native_name' => "Espa\xC3\xB1ol",
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'es',
                'sort_order'  => 3,
            ],
            [
                'code'        => 'de',
                'locale'      => 'de_DE',
                'name'        => 'German',
                'native_name' => 'Deutsch',
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'de',
                'sort_order'  => 4,
            ],
            [
                'code'        => 'tr',
                'locale'      => 'tr_TR',
                'name'        => 'Turkish',
                'native_name' => "T\xC3\xBCrk\xC3\xA7e",
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'tr',
                'sort_order'  => 5,
            ],
            [
                'code'        => 'zh',
                'locale'      => 'zh_CN',
                'name'        => 'Chinese (Simplified)',
                'native_name' => "\xE4\xB8\xAD\xE6\x96\x87",
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'cn',
                'sort_order'  => 6,
            ],
            [
                'code'        => 'ja',
                'locale'      => 'ja',
                'name'        => 'Japanese',
                'native_name' => "\xE6\x97\xA5\xE6\x9C\xAC\xE8\xAA\x9E",
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'jp',
                'sort_order'  => 7,
            ],
            [
                'code'        => 'pt',
                'locale'      => 'pt_BR',
                'name'        => 'Portuguese',
                'native_name' => "Portugu\xC3\xAAs",
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'br',
                'sort_order'  => 8,
            ],
            [
                'code'        => 'ru',
                'locale'      => 'ru_RU',
                'name'        => 'Russian',
                'native_name' => "\xD0\xA0\xD1\x83\xD1\x81\xD1\x81\xD0\xBA\xD0\xB8\xD0\xB9",
                'is_rtl'      => 0,
                'is_default'  => 0,
                'is_active'   => 0,
                'flag'        => 'ru',
                'sort_order'  => 9,
            ],
        ];
    }
}

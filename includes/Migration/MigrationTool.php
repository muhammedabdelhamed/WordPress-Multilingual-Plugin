<?php
/**
 * Migration Tool.
 *
 * Imports data from WPML and Polylang into the Maharat translation tables.
 *
 * @package Maharat\Multilingual\Migration
 */

namespace Maharat\Multilingual\Migration;

use Maharat\Multilingual\Core\LanguageManager;
use Maharat\Multilingual\Translation\TranslationManager;
use Maharat\Multilingual\Translation\StringTranslation;

defined( 'ABSPATH' ) || exit;

class MigrationTool {

    private LanguageManager $language_manager;
    private TranslationManager $translation_manager;
    private StringTranslation $string_translation;

    /**
     * Migration progress log.
     *
     * @var array<int, string>
     */
    private array $log = [];

    /**
     * Counts of migrated items.
     *
     * @var array<string, int>
     */
    private array $counts = [
        'languages'    => 0,
        'posts'        => 0,
        'taxonomies'   => 0,
        'strings'      => 0,
        'skipped'      => 0,
        'errors'       => 0,
    ];

    public function __construct(
        LanguageManager $language_manager,
        TranslationManager $translation_manager,
        StringTranslation $string_translation
    ) {
        $this->language_manager    = $language_manager;
        $this->translation_manager = $translation_manager;
        $this->string_translation  = $string_translation;
    }

    /* ------------------------------------------------------------------
     * Public API
     * ----------------------------------------------------------------*/

    /**
     * Check if WPML data is available for migration.
     */
    public function has_wpml_data(): bool {
        global $wpdb;

        return $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$wpdb->prefix}icl_translations'"
        ) > 0;
    }

    /**
     * Check if Polylang data is available for migration.
     */
    public function has_polylang_data(): bool {
        return taxonomy_exists( 'language' )
            || get_option( 'polylang', false ) !== false;
    }

    /**
     * Run WPML migration.
     *
     * @return array{log: string[], counts: array<string, int>}
     */
    public function migrate_from_wpml(): array {
        $this->reset();
        $this->log( 'Starting WPML migration...' );

        if ( ! $this->has_wpml_data() ) {
            $this->log( 'ERROR: No WPML data found.' );
            return $this->result();
        }

        $this->migrate_wpml_languages();
        $this->migrate_wpml_post_translations();
        $this->migrate_wpml_taxonomy_translations();
        $this->migrate_wpml_strings();

        $this->log( 'WPML migration complete.' );

        return $this->result();
    }

    /**
     * Run Polylang migration.
     *
     * @return array{log: string[], counts: array<string, int>}
     */
    public function migrate_from_polylang(): array {
        $this->reset();
        $this->log( 'Starting Polylang migration...' );

        if ( ! $this->has_polylang_data() ) {
            $this->log( 'ERROR: No Polylang data found.' );
            return $this->result();
        }

        $this->migrate_polylang_languages();
        $this->migrate_polylang_post_translations();
        $this->migrate_polylang_taxonomy_translations();
        $this->migrate_polylang_strings();

        $this->log( 'Polylang migration complete.' );

        return $this->result();
    }

    /**
     * Get migration status / summary.
     *
     * @return array{wpml_available: bool, polylang_available: bool}
     */
    public function get_status(): array {
        return [
            'wpml_available'    => $this->has_wpml_data(),
            'polylang_available' => $this->has_polylang_data(),
        ];
    }

    /* ------------------------------------------------------------------
     * WPML Migration Internals
     * ----------------------------------------------------------------*/

    /**
     * Migrate languages from WPML icl_languages table.
     */
    private function migrate_wpml_languages(): void {
        global $wpdb;

        $this->log( 'Migrating WPML languages...' );

        $wpml_langs = $wpdb->get_results(
            "SELECT l.code, l.english_name, l.default_locale, lt.name AS native_name, l.active
             FROM {$wpdb->prefix}icl_languages l
             LEFT JOIN {$wpdb->prefix}icl_languages_translations lt
                 ON lt.language_code = l.code AND lt.display_language_code = l.code
             WHERE l.active = 1
             ORDER BY l.code"
        );

        if ( empty( $wpml_langs ) ) {
            $this->log( 'No active WPML languages found.' );
            return;
        }

        foreach ( $wpml_langs as $wlang ) {
            $existing = $this->language_manager->get_language( $wlang->code );

            if ( $existing ) {
                $this->log( "  Language '{$wlang->code}' already exists, skipping." );
                $this->counts['skipped']++;
                continue;
            }

            $result = $this->language_manager->add_language( [
                'code'        => $wlang->code,
                'locale'      => $wlang->default_locale ?: $wlang->code,
                'name'        => $wlang->english_name,
                'native_name' => $wlang->native_name ?: $wlang->english_name,
                'is_rtl'      => in_array( $wlang->code, [ 'ar', 'he', 'fa', 'ur', 'ps', 'ku', 'yi', 'sd', 'ug' ], true ) ? 1 : 0,
                'is_active'   => (int) $wlang->active,
                'sort_order'  => 0,
            ] );

            if ( $result ) {
                $this->log( "  Imported language: {$wlang->code} ({$wlang->english_name})" );
                $this->counts['languages']++;
            } else {
                $this->log( "  ERROR: Failed to import language '{$wlang->code}'." );
                $this->counts['errors']++;
            }
        }
    }

    /**
     * Migrate post translations from icl_translations.
     */
    private function migrate_wpml_post_translations(): void {
        global $wpdb;

        $this->log( 'Migrating WPML post translations...' );

        // Get all translation groups for posts.
        $groups = $wpdb->get_results(
            "SELECT trid, GROUP_CONCAT(element_id ORDER BY language_code) AS element_ids,
                    GROUP_CONCAT(language_code ORDER BY language_code) AS languages
             FROM {$wpdb->prefix}icl_translations
             WHERE element_type LIKE 'post_%'
               AND element_id IS NOT NULL
             GROUP BY trid
             HAVING COUNT(*) > 1"
        );

        if ( empty( $groups ) ) {
            $this->log( '  No post translation groups found.' );
            return;
        }

        foreach ( $groups as $group ) {
            $post_ids  = array_map( 'intval', explode( ',', $group->element_ids ) );
            $lang_codes = explode( ',', $group->languages );

            // Build the translation mapping.
            $translations = [];
            foreach ( $post_ids as $idx => $pid ) {
                if ( isset( $lang_codes[ $idx ] ) ) {
                    $translations[ $lang_codes[ $idx ] ] = $pid;
                }
            }

            if ( count( $translations ) < 2 ) {
                continue;
            }

            // Create a Maharat translation group.
            $group_id = $this->translation_manager->create_translation_group( $translations );

            if ( $group_id ) {
                $this->counts['posts'] += count( $translations );
                $this->log( "  Migrated post group (trid={$group->trid}): " . implode( ', ', array_keys( $translations ) ) );
            } else {
                $this->counts['errors']++;
                $this->log( "  ERROR: Failed to migrate post group trid={$group->trid}." );
            }
        }
    }

    /**
     * Migrate taxonomy translations from icl_translations.
     */
    private function migrate_wpml_taxonomy_translations(): void {
        global $wpdb;

        $this->log( 'Migrating WPML taxonomy translations...' );

        $groups = $wpdb->get_results(
            "SELECT trid, GROUP_CONCAT(element_id ORDER BY language_code) AS element_ids,
                    GROUP_CONCAT(language_code ORDER BY language_code) AS languages
             FROM {$wpdb->prefix}icl_translations
             WHERE element_type LIKE 'tax_%'
               AND element_id IS NOT NULL
             GROUP BY trid
             HAVING COUNT(*) > 1"
        );

        if ( empty( $groups ) ) {
            $this->log( '  No taxonomy translation groups found.' );
            return;
        }

        foreach ( $groups as $group ) {
            $term_ids   = array_map( 'intval', explode( ',', $group->element_ids ) );
            $lang_codes = explode( ',', $group->languages );

            $translations = [];
            foreach ( $term_ids as $idx => $tid ) {
                if ( isset( $lang_codes[ $idx ] ) ) {
                    $translations[ $lang_codes[ $idx ] ] = $tid;
                }
            }

            if ( count( $translations ) < 2 ) {
                continue;
            }

            // Determine taxonomy from the first term.
            $first_term = get_term( reset( $translations ) );
            $taxonomy   = $first_term ? $first_term->taxonomy : 'category';

            $group_id = $this->translation_manager->create_taxonomy_translation_group( $translations, $taxonomy );

            if ( $group_id ) {
                $this->counts['taxonomies'] += count( $translations );
                $this->log( "  Migrated taxonomy group (trid={$group->trid}): " . implode( ', ', array_keys( $translations ) ) );
            } else {
                $this->counts['errors']++;
                $this->log( "  ERROR: Failed to migrate taxonomy group trid={$group->trid}." );
            }
        }
    }

    /**
     * Migrate WPML string translations from icl_string_translations.
     */
    private function migrate_wpml_strings(): void {
        global $wpdb;

        $this->log( 'Migrating WPML string translations...' );

        $strings = $wpdb->get_results(
            "SELECT s.id, s.context AS domain, s.name, s.value AS original,
                    st.language AS target_lang, st.value AS translated
             FROM {$wpdb->prefix}icl_strings s
             INNER JOIN {$wpdb->prefix}icl_string_translations st ON st.string_id = s.id
             WHERE st.status = 10
             ORDER BY s.id"
        );

        if ( empty( $strings ) ) {
            $this->log( '  No WPML string translations found.' );
            return;
        }

        foreach ( $strings as $str ) {
            $result = $this->string_translation->register_string(
                $str->original,
                $str->domain ?: 'wpml_import',
                $str->name
            );

            if ( $result ) {
                $this->string_translation->set_translation(
                    $str->original,
                    $str->target_lang,
                    $str->translated,
                    $str->domain ?: 'wpml_import'
                );
                $this->counts['strings']++;
            } else {
                $this->counts['errors']++;
            }
        }

        $this->log( "  Migrated {$this->counts['strings']} string translations." );
    }

    /* ------------------------------------------------------------------
     * Polylang Migration Internals
     * ----------------------------------------------------------------*/

    /**
     * Migrate languages from Polylang.
     */
    private function migrate_polylang_languages(): void {
        $this->log( 'Migrating Polylang languages...' );

        $pll_languages = get_terms( [
            'taxonomy'   => 'language',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $pll_languages ) || empty( $pll_languages ) ) {
            $this->log( '  No Polylang languages found.' );
            return;
        }

        foreach ( $pll_languages as $term ) {
            $locale = get_term_meta( $term->term_id, '_pll_locale', true ) ?: $term->slug;
            $name   = $term->name;

            $existing = $this->language_manager->get_language( $term->slug );
            if ( $existing ) {
                $this->log( "  Language '{$term->slug}' already exists, skipping." );
                $this->counts['skipped']++;
                continue;
            }

            $result = $this->language_manager->add_language( [
                'code'        => $term->slug,
                'locale'      => $locale,
                'name'        => $name,
                'native_name' => $name,
                'is_rtl'      => (int) get_term_meta( $term->term_id, '_pll_is_rtl', true ),
                'is_active'   => 1,
                'sort_order'  => (int) $term->term_order,
            ] );

            if ( $result ) {
                $this->log( "  Imported language: {$term->slug} ({$name})" );
                $this->counts['languages']++;
            } else {
                $this->log( "  ERROR: Failed to import language '{$term->slug}'." );
                $this->counts['errors']++;
            }
        }
    }

    /**
     * Migrate Polylang post translations.
     *
     * Polylang stores translation links in the `post_translations` taxonomy
     * using pll_get_post_translations().
     */
    private function migrate_polylang_post_translations(): void {
        global $wpdb;

        $this->log( 'Migrating Polylang post translations...' );

        // Polylang stores translation groups in term_taxonomy 'post_translations'
        // with description containing serialized data: array( 'en' => post_id, 'fr' => post_id ).
        $groups = $wpdb->get_results(
            "SELECT t.term_id, tt.description
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'post_translations'"
        );

        if ( empty( $groups ) ) {
            $this->log( '  No Polylang post translation groups found.' );
            return;
        }

        foreach ( $groups as $group ) {
            $translations = maybe_unserialize( $group->description );

            if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
                continue;
            }

            // Ensure all values are integers.
            $translations = array_map( 'intval', $translations );

            $group_id = $this->translation_manager->create_translation_group( $translations );

            if ( $group_id ) {
                $this->counts['posts'] += count( $translations );
                $this->log( "  Migrated post group: " . implode( ', ', array_keys( $translations ) ) );
            } else {
                $this->counts['errors']++;
            }
        }
    }

    /**
     * Migrate Polylang taxonomy translations.
     */
    private function migrate_polylang_taxonomy_translations(): void {
        global $wpdb;

        $this->log( 'Migrating Polylang taxonomy translations...' );

        $groups = $wpdb->get_results(
            "SELECT t.term_id, tt.description
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'term_translations'"
        );

        if ( empty( $groups ) ) {
            $this->log( '  No Polylang taxonomy translation groups found.' );
            return;
        }

        foreach ( $groups as $group ) {
            $translations = maybe_unserialize( $group->description );

            if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
                continue;
            }

            $translations = array_map( 'intval', $translations );

            // Determine taxonomy from the first term.
            $first_term = get_term( reset( $translations ) );
            $taxonomy   = $first_term ? $first_term->taxonomy : 'category';

            $group_id = $this->translation_manager->create_taxonomy_translation_group( $translations, $taxonomy );

            if ( $group_id ) {
                $this->counts['taxonomies'] += count( $translations );
                $this->log( "  Migrated taxonomy group: " . implode( ', ', array_keys( $translations ) ) );
            } else {
                $this->counts['errors']++;
            }
        }
    }

    /**
     * Migrate Polylang string translations.
     */
    private function migrate_polylang_strings(): void {
        $this->log( 'Migrating Polylang string translations...' );

        // Polylang stores strings in mo_* options.
        $pll_options = get_option( 'polylang', [] );

        if ( empty( $pll_options ) ) {
            $this->log( '  No Polylang options found.' );
            return;
        }

        // Polylang string translations are stored in pll_string_translations option.
        $pll_strings = get_option( 'pll_string_translations', [] );

        if ( empty( $pll_strings ) ) {
            $this->log( '  No Polylang string translations found.' );
            return;
        }

        foreach ( $pll_strings as $lang_code => $strings ) {
            if ( ! is_array( $strings ) ) {
                continue;
            }

            foreach ( $strings as $string_data ) {
                if ( ! isset( $string_data['string'], $string_data['translations'] ) ) {
                    continue;
                }

                $original = $string_data['string'];
                $domain   = $string_data['context'] ?? 'polylang_import';
                $name     = $string_data['name'] ?? md5( $original );

                $this->string_translation->register_string( $original, $domain, $name );

                foreach ( (array) $string_data['translations'] as $target_lang => $translated ) {
                    if ( ! empty( $translated ) ) {
                        $this->string_translation->set_translation( $original, $target_lang, $translated, $domain );
                        $this->counts['strings']++;
                    }
                }
            }
        }

        $this->log( "  Migrated {$this->counts['strings']} string translations from Polylang." );
    }

    /* ------------------------------------------------------------------
     * Internal Helpers
     * ----------------------------------------------------------------*/

    /**
     * Reset state for a new migration run.
     */
    private function reset(): void {
        $this->log    = [];
        $this->counts = [
            'languages'  => 0,
            'posts'      => 0,
            'taxonomies' => 0,
            'strings'    => 0,
            'skipped'    => 0,
            'errors'     => 0,
        ];
    }

    /**
     * Append a log entry.
     */
    private function log( string $message ): void {
        $this->log[] = '[' . current_time( 'H:i:s' ) . '] ' . $message;
    }

    /**
     * Build the result array.
     *
     * @return array{log: string[], counts: array<string, int>}
     */
    private function result(): array {
        return [
            'log'    => $this->log,
            'counts' => $this->counts,
        ];
    }
}

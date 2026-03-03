<?php
/**
 * Translation Manager.
 *
 * Handles post/taxonomy translation linking, creation, and synchronisation.
 *
 * @package Maharat\Multilingual\Translation
 */

namespace Maharat\Multilingual\Translation;

use Maharat\Multilingual\Core\LanguageManager;

defined( 'ABSPATH' ) || exit;

class TranslationManager {

    private LanguageManager $language_manager;

    public function __construct( LanguageManager $language_manager ) {
        $this->language_manager = $language_manager;
    }

    /**
     * Initialise hooks.
     */
    public function init(): void {
        // Filter main query to show only posts in current language.
        add_action( 'pre_get_posts', [ $this, 'filter_posts_by_language' ] );

        // Admin columns for translation status.
        add_action( 'admin_init', [ $this, 'register_admin_columns' ] );

        // Sync meta when translations are saved.
        add_action( 'save_post', [ $this, 'maybe_sync_translation_meta' ], 20, 2 );
    }

    // =========================================================================
    // Post Translation CRUD
    // =========================================================================

    /**
     * Get the translation group ID for a post.
     *
     * @param int $post_id Post ID.
     * @return int|null The translation group or null.
     */
    public function get_translation_group( int $post_id ): ?int {
        global $wpdb;

        $group = $wpdb->get_var( $wpdb->prepare(
            "SELECT translation_group FROM {$wpdb->prefix}maharat_translations WHERE post_id = %d LIMIT 1",
            $post_id
        ) );

        return $group ? (int) $group : null;
    }

    /**
     * Get all translations for a post (keyed by language code).
     *
     * @param int $post_id The post ID.
     * @return array<string, int> Language code => post ID.
     */
    public function get_post_translations( int $post_id ): array {
        global $wpdb;

        $group = $this->get_translation_group( $post_id );
        if ( ! $group ) {
            // Return the post itself in its language.
            $lang = $this->get_post_language( $post_id );
            return $lang ? [ $lang => $post_id ] : [];
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT language_code, post_id FROM {$wpdb->prefix}maharat_translations WHERE translation_group = %d",
            $group
        ) );

        $translations = [];
        foreach ( $results as $row ) {
            $translations[ $row->language_code ] = (int) $row->post_id;
        }

        return $translations;
    }

    /**
     * Get the language code of a post.
     *
     * @param int $post_id Post ID.
     * @return string|null Language code or null if not registered.
     */
    public function get_post_language( int $post_id ): ?string {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT language_code FROM {$wpdb->prefix}maharat_translations WHERE post_id = %d LIMIT 1",
            $post_id
        ) );
    }

    /**
     * Set the language of a post. If the post has no translation record, create one.
     *
     * @param int    $post_id       Post ID.
     * @param string $language_code Language code.
     * @param int|null $group       Translation group. Null = auto-create.
     * @return bool
     */
    public function set_post_language( int $post_id, string $language_code, ?int $group = null ): bool {
        global $wpdb;

        $existing = $this->get_post_language( $post_id );

        if ( $existing ) {
            // Update.
            $result = $wpdb->update(
                "{$wpdb->prefix}maharat_translations",
                [ 'language_code' => $language_code ],
                [ 'post_id' => $post_id ]
            );
            return false !== $result;
        }

        // Create new record.
        if ( null === $group ) {
            $group = $this->generate_translation_group();
        }

        $post = get_post( $post_id );
        $result = $wpdb->insert(
            "{$wpdb->prefix}maharat_translations",
            [
                'translation_group' => $group,
                'post_id'           => $post_id,
                'language_code'     => $language_code,
                'post_type'         => $post ? $post->post_type : 'post',
                'status'            => 'published',
            ]
        );

        return (bool) $result;
    }

    /**
     * Link two posts as translations of each other.
     *
     * @param int    $source_id     Source post ID.
     * @param int    $target_id     Target post ID.
     * @param string $target_lang   Target language code.
     * @return bool
     */
    public function link_translation( int $source_id, int $target_id, string $target_lang ): bool {
        $group = $this->get_translation_group( $source_id );

        if ( ! $group ) {
            $group = $this->generate_translation_group();
            $source_lang = $this->language_manager->get_default_language();
            $this->set_post_language( $source_id, $source_lang, $group );
        }

        return $this->set_post_language( $target_id, $target_lang, $group );
    }

    /**
     * Create a translation of a post in a target language.
     *
     * Duplicates the post, its meta, taxonomies, and builder data.
     *
     * @param int    $source_id   Source post ID.
     * @param string $target_lang Target language code.
     * @return int|false New post ID or false on failure.
     */
    public function create_translation( int $source_id, string $target_lang ): int|false {
        $source = get_post( $source_id );
        if ( ! $source ) {
            return false;
        }

        /**
         * Fires before a translation is saved.
         *
         * @param int    $source_id   Source post ID.
         * @param string $target_lang Target language code.
         */
        do_action( 'maharat_before_translation_save', $source_id, $target_lang );

        // Duplicate the post.
        $new_post_data = [
            'post_title'   => $source->post_title,
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_status'  => 'draft',
            'post_type'    => $source->post_type,
            'post_author'  => $source->post_author,
            'post_parent'  => $source->post_parent,
            'menu_order'   => $source->menu_order,
            'post_name'    => $source->post_name . '-' . $target_lang,
        ];

        /**
         * Filter the new post data before insertion.
         *
         * @param array  $new_post_data The post data.
         * @param int    $source_id     Source post ID.
         * @param string $target_lang   Target language.
         */
        $new_post_data = apply_filters( 'maharat_new_translation_data', $new_post_data, $source_id, $target_lang );

        $new_id = wp_insert_post( $new_post_data, true );
        if ( is_wp_error( $new_id ) ) {
            return false;
        }

        // Clone meta.
        $this->clone_post_meta( $source_id, $new_id );

        // Clone taxonomies.
        $this->clone_post_taxonomies( $source_id, $new_id );

        // Clone featured image.
        $thumbnail_id = get_post_thumbnail_id( $source_id );
        if ( $thumbnail_id ) {
            set_post_thumbnail( $new_id, $thumbnail_id );
        }

        // Link the translation.
        $this->link_translation( $source_id, $new_id, $target_lang );

        // Mark source ID.
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}maharat_translations",
            [ 'source_id' => $source_id ],
            [ 'post_id' => $new_id ]
        );

        /**
         * Fires after a translation is created.
         *
         * @param int    $new_id      New post ID.
         * @param int    $source_id   Source post ID.
         * @param string $target_lang Target language.
         */
        do_action( 'maharat_after_translation_created', $new_id, $source_id, $target_lang );

        return $new_id;
    }

    /**
     * Delete a translation link (does not delete the post).
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public function unlink_translation( int $post_id ): bool {
        global $wpdb;

        return (bool) $wpdb->delete(
            "{$wpdb->prefix}maharat_translations",
            [ 'post_id' => $post_id ]
        );
    }

    // =========================================================================
    // Taxonomy Translations
    // =========================================================================

    /**
     * Set the language of a term.
     *
     * @param int    $term_taxonomy_id Term taxonomy ID.
     * @param string $language_code    Language code.
     * @param string $taxonomy         Taxonomy slug.
     * @param int|null $group          Translation group.
     * @return bool
     */
    public function set_term_language( int $term_taxonomy_id, string $language_code, string $taxonomy, ?int $group = null ): bool {
        global $wpdb;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}maharat_taxonomy_translations WHERE term_taxonomy_id = %d LIMIT 1",
            $term_taxonomy_id
        ) );

        if ( $existing ) {
            $result = $wpdb->update(
                "{$wpdb->prefix}maharat_taxonomy_translations",
                [ 'language_code' => $language_code ],
                [ 'term_taxonomy_id' => $term_taxonomy_id ]
            );
            return false !== $result;
        }

        if ( null === $group ) {
            $group = $this->generate_translation_group();
        }

        return (bool) $wpdb->insert(
            "{$wpdb->prefix}maharat_taxonomy_translations",
            [
                'translation_group' => $group,
                'term_taxonomy_id'  => $term_taxonomy_id,
                'language_code'     => $language_code,
                'taxonomy'          => $taxonomy,
            ]
        );
    }

    /**
     * Get term translations.
     *
     * @param int $term_taxonomy_id Term taxonomy ID.
     * @return array<string, int> Language code => term_taxonomy_id.
     */
    public function get_term_translations( int $term_taxonomy_id ): array {
        global $wpdb;

        $group = $wpdb->get_var( $wpdb->prepare(
            "SELECT translation_group FROM {$wpdb->prefix}maharat_taxonomy_translations WHERE term_taxonomy_id = %d LIMIT 1",
            $term_taxonomy_id
        ) );

        if ( ! $group ) {
            return [];
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT language_code, term_taxonomy_id FROM {$wpdb->prefix}maharat_taxonomy_translations WHERE translation_group = %d",
            (int) $group
        ) );

        $translations = [];
        foreach ( $results as $row ) {
            $translations[ $row->language_code ] = (int) $row->term_taxonomy_id;
        }

        return $translations;
    }

    /**
     * Get the language of a term.
     *
     * @param int $term_taxonomy_id Term taxonomy ID.
     * @return string|null
     */
    public function get_term_language( int $term_taxonomy_id ): ?string {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT language_code FROM {$wpdb->prefix}maharat_taxonomy_translations WHERE term_taxonomy_id = %d LIMIT 1",
            $term_taxonomy_id
        ) );
    }

    // =========================================================================
    // Query Filtering
    // =========================================================================

    /**
     * Filter the main query to only show posts in the current language.
     *
     * @param \WP_Query $query The WP_Query instance.
     */
    public function filter_posts_by_language( $query ): void {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        if ( ! $query->is_main_query() ) {
            return;
        }

        $current_lang = $this->language_manager->get_current_language();

        // Join with translations table.
        add_filter( 'posts_join', function ( $join, $q ) use ( $query ) {
            if ( $q !== $query ) {
                return $join;
            }
            global $wpdb;
            $join .= " LEFT JOIN {$wpdb->prefix}maharat_translations AS maharat_t ON ({$wpdb->posts}.ID = maharat_t.post_id)";
            return $join;
        }, 10, 2 );

        add_filter( 'posts_where', function ( $where, $q ) use ( $query, $current_lang ) {
            if ( $q !== $query ) {
                return $where;
            }
            global $wpdb;
            $where .= $wpdb->prepare(
                " AND (maharat_t.language_code = %s OR maharat_t.language_code IS NULL)",
                $current_lang
            );
            return $where;
        }, 10, 2 );
    }

    /**
     * Register translation status columns in post list tables.
     */
    public function register_admin_columns(): void {
        $post_types = get_post_types( [ 'public' => true ] );
        foreach ( $post_types as $pt ) {
            add_filter( "manage_{$pt}_posts_columns", [ $this, 'add_translation_column' ] );
            add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_translation_column' ], 10, 2 );
        }
    }

    /**
     * Add the translations column header.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_translation_column( array $columns ): array {
        $columns['maharat_translations'] = esc_html__( 'Translations', 'maharat-multilingual' );
        return $columns;
    }

    /**
     * Render the translation column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public function render_translation_column( string $column, int $post_id ): void {
        if ( 'maharat_translations' !== $column ) {
            return;
        }

        $translations = $this->get_post_translations( $post_id );
        $languages    = $this->language_manager->get_languages();
        $current_lang = $this->get_post_language( $post_id );

        $flags = [];
        foreach ( $languages as $lang ) {
            if ( $lang->code === $current_lang ) {
                continue;
            }

            if ( isset( $translations[ $lang->code ] ) ) {
                $edit_link = get_edit_post_link( $translations[ $lang->code ] );
                $flags[] = sprintf(
                    '<a href="%s" title="%s">%s</a>',
                    esc_url( $edit_link ),
                    /* translators: %s: language name */
                    esc_attr( sprintf( __( 'Edit %s translation', 'maharat-multilingual' ), $lang->name ) ),
                    esc_html( $lang->code )
                );
            } else {
                $create_url = wp_nonce_url(
                    admin_url( sprintf(
                        'admin.php?page=maharat-translations&action=create&source=%d&lang=%s',
                        $post_id,
                        $lang->code
                    ) ),
                    'maharat_create_translation'
                );
                $flags[] = sprintf(
                    '<a href="%s" title="%s" style="opacity:0.4">+%s</a>',
                    esc_url( $create_url ),
                    /* translators: %s: language name */
                    esc_attr( sprintf( __( 'Create %s translation', 'maharat-multilingual' ), $lang->name ) ),
                    esc_html( $lang->code )
                );
            }
        }

        echo implode( ' | ', $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    }

    /**
     * Sync certain meta fields when a translation is saved.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function maybe_sync_translation_meta( int $post_id, $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        /**
         * Filter the list of meta keys to sync across translations.
         *
         * @param array $meta_keys Meta keys.
         * @param int   $post_id   Post ID.
         */
        $sync_keys = apply_filters( 'maharat_sync_meta_keys', [ '_thumbnail_id' ], $post_id );

        if ( empty( $sync_keys ) ) {
            return;
        }

        $translations = $this->get_post_translations( $post_id );
        foreach ( $translations as $lang => $tid ) {
            if ( $tid === $post_id ) {
                continue;
            }
            foreach ( $sync_keys as $key ) {
                $value = get_post_meta( $post_id, $key, true );
                update_post_meta( $tid, $key, $value );
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Generate a new unique translation group ID.
     *
     * @return int
     */
    private function generate_translation_group(): int {
        global $wpdb;

        $max = (int) $wpdb->get_var(
            "SELECT MAX(translation_group) FROM {$wpdb->prefix}maharat_translations"
        );

        return $max + 1;
    }

    /**
     * Clone all post meta from one post to another.
     *
     * @param int $source_id Source post ID.
     * @param int $target_id Target post ID.
     */
    private function clone_post_meta( int $source_id, int $target_id ): void {
        $meta = get_post_meta( $source_id );

        /**
         * Filter meta keys to exclude from cloning.
         *
         * @param array $excluded_keys Keys to skip.
         * @param int   $source_id     Source post ID.
         */
        $excluded = apply_filters( 'maharat_excluded_clone_meta_keys', [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
        ], $source_id );

        foreach ( $meta as $key => $values ) {
            if ( in_array( $key, $excluded, true ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $target_id, $key, maybe_unserialize( $value ) );
            }
        }
    }

    /**
     * Clone taxonomies from one post to another.
     *
     * @param int $source_id Source post ID.
     * @param int $target_id Target post ID.
     */
    private function clone_post_taxonomies( int $source_id, int $target_id ): void {
        $taxonomies = get_object_taxonomies( get_post_type( $source_id ) );

        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $target_id, $terms, $taxonomy );
            }
        }
    }

    /**
     * Get translation statistics.
     *
     * @return array{total: int, translated: int, untranslated: int}
     */
    public function get_translation_stats(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT translation_group) FROM {$wpdb->prefix}maharat_translations"
        );

        $languages   = $this->language_manager->get_languages();
        $lang_count  = count( $languages );
        $translated  = 0;

        if ( $lang_count > 0 ) {
            $fully = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT translation_group, COUNT(DISTINCT language_code) as cnt
                    FROM {$wpdb->prefix}maharat_translations
                    GROUP BY translation_group
                    HAVING cnt >= %d
                ) AS complete_groups",
                $lang_count
            ) );
            $translated = $fully;
        }

        return [
            'total'        => $total,
            'translated'   => $translated,
            'untranslated' => $total - $translated,
        ];
    }
}

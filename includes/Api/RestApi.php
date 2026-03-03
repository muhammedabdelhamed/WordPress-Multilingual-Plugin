<?php
/**
 * REST API.
 *
 * Registers /wp-json/maharat/v1/ routes.
 *
 * @package Maharat\Multilingual\Api
 */

namespace Maharat\Multilingual\Api;

use Maharat\Multilingual\Core\LanguageManager;
use Maharat\Multilingual\Migration\MigrationTool;
use Maharat\Multilingual\Translation\AutoTranslation;
use Maharat\Multilingual\Translation\StringTranslation;
use Maharat\Multilingual\Translation\TranslationManager;

defined( 'ABSPATH' ) || exit;

class RestApi {

    private LanguageManager $language_manager;
    private TranslationManager $translation_manager;
    private StringTranslation $string_translation;
    private AutoTranslation $auto_translation;
    private MigrationTool $migration_tool;

    public function __construct(
        LanguageManager $language_manager,
        TranslationManager $translation_manager,
        StringTranslation $string_translation,
        AutoTranslation $auto_translation,
        MigrationTool $migration_tool
    ) {
        $this->language_manager    = $language_manager;
        $this->translation_manager = $translation_manager;
        $this->string_translation  = $string_translation;
        $this->auto_translation    = $auto_translation;
        $this->migration_tool      = $migration_tool;
    }

    /**
     * Register all REST routes.
     */
    public function register_routes(): void {
        $namespace = 'maharat/v1';

        // === Languages ===
        register_rest_route( $namespace, '/languages', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_languages' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_language' ],
                'permission_callback' => [ $this, 'admin_permission' ],
                'args'                => $this->get_language_args(),
            ],
        ] );

        register_rest_route( $namespace, '/languages/(?P<code>[a-z]{2,5})', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_language' ],
                'permission_callback' => [ $this, 'admin_permission' ],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_language' ],
                'permission_callback' => [ $this, 'admin_permission' ],
            ],
        ] );

        // === Translations ===
        register_rest_route( $namespace, '/translations', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_translations' ],
            'permission_callback' => [ $this, 'admin_permission' ],
            'args'                => [
                'post_type' => [ 'type' => 'string', 'default' => 'post', 'sanitize_callback' => 'sanitize_text_field' ],
                'language'  => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'page'      => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( $namespace, '/translations/(?P<post_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_translations' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/translate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_translation' ],
            'permission_callback' => [ $this, 'editor_permission' ],
            'args'                => [
                'post_id'     => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'target_lang' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $namespace, '/translate/link', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'link_translation' ],
            'permission_callback' => [ $this, 'editor_permission' ],
            'args'                => [
                'source_id'   => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'target_id'   => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'target_lang' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $namespace, '/translate/unlink/(?P<post_id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'unlink_translation' ],
            'permission_callback' => [ $this, 'editor_permission' ],
        ] );

        // === Auto Translation ===
        register_rest_route( $namespace, '/auto-translate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'auto_translate' ],
            'permission_callback' => [ $this, 'editor_permission' ],
            'args'                => [
                'text'        => [ 'required' => true, 'type' => 'string' ],
                'source_lang' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'target_lang' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $namespace, '/auto-translate/bulk', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'bulk_auto_translate' ],
            'permission_callback' => [ $this, 'editor_permission' ],
        ] );

        // === String Translations ===
        register_rest_route( $namespace, '/strings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_strings' ],
                'permission_callback' => [ $this, 'admin_permission' ],
            ],
        ] );

        register_rest_route( $namespace, '/strings/translate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'save_string_translation' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        register_rest_route( $namespace, '/strings/register', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'register_string' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        register_rest_route( $namespace, '/strings/domains', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_string_domains' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        // === Settings ===
        register_rest_route( $namespace, '/settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => [ $this, 'admin_permission' ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'update_settings' ],
                'permission_callback' => [ $this, 'admin_permission' ],
            ],
        ] );

        // === Stats ===
        register_rest_route( $namespace, '/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_stats' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        // === Tools ===
        register_rest_route( $namespace, '/tools/migration-status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_migration_status' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        register_rest_route( $namespace, '/tools/migrate/(?P<source>wpml|polylang)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'run_migration' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        register_rest_route( $namespace, '/tools/clear-cache', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'clear_cache' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        /**
         * Fires after all Maharat REST routes are registered.
         *
         * @param string $namespace The REST namespace.
         */
        do_action( 'maharat_rest_routes_registered', $namespace );
    }

    // =========================================================================
    // Permissions
    // =========================================================================

    public function admin_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    public function editor_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    // =========================================================================
    // Language Endpoints
    // =========================================================================

    public function get_languages( \WP_REST_Request $request ): \WP_REST_Response {
        $include_inactive = (bool) $request->get_param( 'include_inactive' );
        $languages = $this->language_manager->get_languages( $include_inactive );

        // Append flag_url to each language for the admin UI.
        $languages = array_map( function ( $lang ) {
            $lang = clone $lang; // Don't modify cached objects.
            $lang->flag_url = $this->get_flag_url( $lang->code );
            return $lang;
        }, $languages );

        return new \WP_REST_Response( array_values( $languages ), 200 );
    }

    /**
     * Get the flag URL for a language code.
     *
     * @param string $code Language code.
     * @return string Flag URL or empty string.
     */
    private function get_flag_url( string $code ): string {
        $flag_path = MAHARAT_PLUGIN_DIR . 'assets/flags/' . $code . '.svg';
        if ( file_exists( $flag_path ) ) {
            return MAHARAT_PLUGIN_URL . 'assets/flags/' . $code . '.svg';
        }
        return '';
    }

    public function create_language( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params();

        // Cast booleans to integers for DB storage.
        foreach ( [ 'is_rtl', 'is_default', 'is_active' ] as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $data[ $field ] = (int) $data[ $field ];
            }
        }

        $id = $this->language_manager->add_language( $data );

        if ( false === $id ) {
            return new \WP_REST_Response( [ 'error' => __( 'Failed to create language.', 'maharat-multilingual' ) ], 400 );
        }

        return new \WP_REST_Response( [ 'id' => $id, 'message' => __( 'Language created.', 'maharat-multilingual' ) ], 201 );
    }

    public function update_language( \WP_REST_Request $request ): \WP_REST_Response {
        $code = sanitize_text_field( $request->get_param( 'code' ) );
        $data = $request->get_json_params();

        // Cast booleans to integers for DB storage.
        foreach ( [ 'is_rtl', 'is_default', 'is_active' ] as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $data[ $field ] = (int) $data[ $field ];
            }
        }
        $ok   = $this->language_manager->update_language( $code, $data );

        if ( ! $ok ) {
            return new \WP_REST_Response( [ 'error' => __( 'Failed to update language.', 'maharat-multilingual' ) ], 400 );
        }

        return new \WP_REST_Response( [ 'message' => __( 'Language updated.', 'maharat-multilingual' ) ], 200 );
    }

    public function delete_language( \WP_REST_Request $request ): \WP_REST_Response {
        $code = sanitize_text_field( $request->get_param( 'code' ) );
        $ok   = $this->language_manager->delete_language( $code );

        if ( ! $ok ) {
            return new \WP_REST_Response( [ 'error' => __( 'Cannot delete default language.', 'maharat-multilingual' ) ], 400 );
        }

        return new \WP_REST_Response( [ 'message' => __( 'Language deleted.', 'maharat-multilingual' ) ], 200 );
    }

    // =========================================================================
    // Translation Endpoints
    // =========================================================================

    /**
     * List posts with their translation status (for the admin Translations page).
     */
    public function list_translations( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $post_type = $request->get_param( 'post_type' ) ?: 'post';
        $language  = $request->get_param( 'language' );
        $page      = max( 1, (int) $request->get_param( 'page' ) );
        $per_page  = 20;
        $offset    = ( $page - 1 ) * $per_page;

        $table = $wpdb->prefix . 'maharat_translations';

        // Build query for posts that have a language assigned via the translations table.
        // If language filter is provided, only show posts in that language.
        if ( $language ) {
            $where = $wpdb->prepare(
                "AND t.language_code = %s",
                $language
            );
        } else {
            $where = '';
        }

        // Get posts from translations table joined with posts.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, p.post_status, t.language_code, t.translation_group
             FROM {$wpdb->posts} p
             INNER JOIN {$table} t ON p.ID = t.post_id
             WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','private')
             {$where}
             ORDER BY p.ID DESC
             LIMIT %d OFFSET %d",
            $post_type,
            $per_page,
            $offset
        );

        $results = $wpdb->get_results( $sql );

        // Count total.
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$table} t ON p.ID = t.post_id
             WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','private')
             {$where}",
            $post_type
        );
        $total = (int) $wpdb->get_var( $count_sql );

        // If no posts in translations table yet, fall back to listing all posts of this type.
        if ( empty( $results ) && ! $language ) {
            $sql = $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_status, '' AS language_code, 0 AS translation_group
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','private')
                 ORDER BY p.ID DESC
                 LIMIT %d OFFSET %d",
                $post_type,
                $per_page,
                $offset
            );
            $results = $wpdb->get_results( $sql );

            $count_sql = $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','private')",
                $post_type
            );
            $total = (int) $wpdb->get_var( $count_sql );
        }

        $active_languages = $this->language_manager->get_languages( false );

        $items = [];
        foreach ( $results as $row ) {
            // Get translations for this post's group.
            $translations = [];
            if ( $row->translation_group ) {
                $group_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT language_code, post_id FROM {$table} WHERE translation_group = %d",
                    $row->translation_group
                ) );
                foreach ( $group_rows as $gr ) {
                    $translations[ $gr->language_code ] = (int) $gr->post_id;
                }
            }

            $items[] = [
                'id'           => (int) $row->ID,
                'title'        => $row->post_title,
                'status'       => $row->post_status,
                'language'     => $row->language_code ?: $this->language_manager->get_default_language(),
                'edit_url'     => get_edit_post_link( $row->ID, 'raw' ),
                'translations' => $translations,
            ];
        }

        return new \WP_REST_Response( [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => (int) ceil( $total / $per_page ),
            'page'        => $page,
        ], 200 );
    }

    public function get_translations( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id      = (int) $request->get_param( 'post_id' );
        $translations = $this->translation_manager->get_post_translations( $post_id );

        $result = [];
        foreach ( $translations as $lang => $tid ) {
            $post = get_post( $tid );
            $result[] = [
                'language_code' => $lang,
                'post_id'       => $tid,
                'title'         => $post ? $post->post_title : '',
                'status'        => $post ? $post->post_status : '',
                'edit_link'     => get_edit_post_link( $tid, 'raw' ),
            ];
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function create_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id     = (int) $request->get_param( 'post_id' );
        $target_lang = sanitize_text_field( $request->get_param( 'target_lang' ) );

        $new_id = $this->translation_manager->create_translation( $post_id, $target_lang );

        if ( false === $new_id ) {
            return new \WP_REST_Response( [ 'error' => __( 'Failed to create translation.', 'maharat-multilingual' ) ], 400 );
        }

        return new \WP_REST_Response( [
            'post_id'   => $new_id,
            'edit_link' => get_edit_post_link( $new_id, 'raw' ),
            'message'   => __( 'Translation created.', 'maharat-multilingual' ),
        ], 201 );
    }

    public function link_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $source_id   = (int) $request->get_param( 'source_id' );
        $target_id   = (int) $request->get_param( 'target_id' );
        $target_lang = sanitize_text_field( $request->get_param( 'target_lang' ) );

        $ok = $this->translation_manager->link_translation( $source_id, $target_id, $target_lang );

        if ( ! $ok ) {
            return new \WP_REST_Response( [ 'error' => __( 'Failed to link.', 'maharat-multilingual' ) ], 400 );
        }

        return new \WP_REST_Response( [ 'message' => __( 'Translation linked.', 'maharat-multilingual' ) ], 200 );
    }

    public function unlink_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = (int) $request->get_param( 'post_id' );
        $this->translation_manager->unlink_translation( $post_id );

        return new \WP_REST_Response( [ 'message' => __( 'Translation unlinked.', 'maharat-multilingual' ) ], 200 );
    }

    // =========================================================================
    // Auto Translation Endpoints
    // =========================================================================

    public function auto_translate( \WP_REST_Request $request ): \WP_REST_Response {
        $text        = $request->get_param( 'text' );
        $source_lang = sanitize_text_field( $request->get_param( 'source_lang' ) );
        $target_lang = sanitize_text_field( $request->get_param( 'target_lang' ) );

        $result = $this->auto_translation->translate( $text, $source_lang, $target_lang );

        if ( false === $result ) {
            return new \WP_REST_Response( [ 'error' => __( 'Translation failed.', 'maharat-multilingual' ) ], 500 );
        }

        return new \WP_REST_Response( [ 'translation' => $result ], 200 );
    }

    public function bulk_auto_translate( \WP_REST_Request $request ): \WP_REST_Response {
        $texts       = $request->get_param( 'texts' );
        $source_lang = sanitize_text_field( $request->get_param( 'source_lang' ) );
        $target_lang = sanitize_text_field( $request->get_param( 'target_lang' ) );

        if ( ! is_array( $texts ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Texts must be an array.', 'maharat-multilingual' ) ], 400 );
        }

        $results = $this->auto_translation->bulk_translate( $texts, $source_lang, $target_lang );

        return new \WP_REST_Response( [ 'translations' => $results ], 200 );
    }

    // =========================================================================
    // String Translation Endpoints
    // =========================================================================

    public function get_strings( \WP_REST_Request $request ): \WP_REST_Response {
        $result = $this->string_translation->get_strings( [
            'domain'        => $request->get_param( 'domain' ) ?: '',
            'language_code' => $request->get_param( 'language_code' ) ?: '',
            'status'        => $request->get_param( 'status' ) ?: '',
            'search'        => $request->get_param( 'search' ) ?: '',
            'page'          => (int) ( $request->get_param( 'page' ) ?: 1 ),
            'per_page'      => (int) ( $request->get_param( 'per_page' ) ?: 50 ),
        ] );

        return new \WP_REST_Response( $result, 200 );
    }

    public function save_string_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $name          = sanitize_text_field( $request->get_param( 'name' ) );
        $domain        = sanitize_text_field( $request->get_param( 'domain' ) );
        $language_code = sanitize_text_field( $request->get_param( 'language_code' ) );
        $translation   = sanitize_textarea_field( $request->get_param( 'translation' ) );

        $ok = $this->string_translation->save_translation( $name, $domain, $language_code, $translation );

        if ( ! $ok ) {
            return new \WP_REST_Response( [ 'error' => __( 'Failed to save.', 'maharat-multilingual' ) ], 400 );
        }

        return new \WP_REST_Response( [ 'message' => __( 'String translation saved.', 'maharat-multilingual' ) ], 200 );
    }

    public function register_string( \WP_REST_Request $request ): \WP_REST_Response {
        $text    = $request->get_param( 'text' );
        $domain  = sanitize_text_field( $request->get_param( 'domain' ) ?: 'default' );
        $context = sanitize_text_field( $request->get_param( 'context' ) ?: '' );

        $this->string_translation->register_string( $text, $domain, $context );

        return new \WP_REST_Response( [ 'message' => __( 'String registered.', 'maharat-multilingual' ) ], 201 );
    }

    // =========================================================================
    // Settings Endpoints
    // =========================================================================

    public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = [
            'url_mode'                    => get_option( 'maharat_url_mode', 'directory' ),
            'default_language'            => get_option( 'maharat_default_language', 'en' ),
            'show_default_lang'           => get_option( 'maharat_show_default_lang', '0' ),
            'browser_redirect'            => get_option( 'maharat_browser_redirect', '0' ),
            'auto_translate'              => get_option( 'maharat_auto_translate', '0' ),
            'translation_api'             => get_option( 'maharat_translation_api', '' ),
            'woo_sync_stock'              => get_option( 'maharat_woo_sync_stock', '1' ),
            'floating_switcher'           => get_option( 'maharat_floating_switcher', '0' ),
            'floating_switcher_position'  => get_option( 'maharat_floating_switcher_position', 'bottom-right' ),
            'clean_uninstall'             => get_option( 'maharat_clean_uninstall', '0' ),
            'api_usage'                   => $this->auto_translation->get_usage_stats(),
        ];

        return new \WP_REST_Response( $settings, 200 );
    }

    public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $allowed = [
            'url_mode', 'default_language', 'show_default_lang',
            'browser_redirect', 'auto_translate', 'translation_api',
            'woo_sync_stock', 'floating_switcher', 'floating_switcher_position',
            'clean_uninstall',
        ];

        $data = $request->get_json_params();

        // Boolean settings need explicit "0"/"1" normalization
        // because JS sends true/false which sanitize_text_field converts to "1"/"".
        $bool_keys = [
            'show_default_lang', 'browser_redirect', 'auto_translate',
            'woo_sync_stock', 'floating_switcher', 'clean_uninstall',
        ];

        foreach ( $allowed as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $value = $data[ $key ];
                if ( in_array( $key, $bool_keys, true ) ) {
                    $value = ( $value && '0' !== $value ) ? '1' : '0';
                } else {
                    $value = sanitize_text_field( $value );
                }
                update_option( "maharat_{$key}", $value );
            }
        }

        // API keys handled separately (sensitive data).
        $api_keys = [ 'api_key_google', 'api_key_deepl', 'api_key_openai' ];
        foreach ( $api_keys as $key ) {
            if ( isset( $data[ $key ] ) && ! empty( $data[ $key ] ) ) {
                update_option( "maharat_{$key}", sanitize_text_field( $data[ $key ] ) );
            }
        }

        // Flush rewrite rules if URL mode changed.
        if ( isset( $data['url_mode'] ) ) {
            flush_rewrite_rules();
        }

        return new \WP_REST_Response( [ 'message' => __( 'Settings updated.', 'maharat-multilingual' ) ], 200 );
    }

    // =========================================================================
    // Stats Endpoint
    // =========================================================================

    public function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
        $translation_stats = $this->translation_manager->get_translation_stats();
        $languages         = $this->language_manager->get_languages();
        $all_languages     = $this->language_manager->get_languages( true );
        $api_usage         = $this->auto_translation->get_usage_stats();
        $default_code      = $this->language_manager->get_default_language();
        $default_lang      = $this->language_manager->get_language( $default_code );

        // String translation stats.
        $string_stats = $this->string_translation->get_strings( [
            'per_page' => 1,
            'page'     => 1,
        ] );
        $string_count = isset( $string_stats['total'] ) ? (int) $string_stats['total'] : 0;

        return new \WP_REST_Response( [
            'languages_count'      => count( $languages ),
            'total_languages'      => count( $all_languages ),
            'translated_posts'     => $translation_stats['translated'] ?? 0,
            'pending_translations' => $translation_stats['untranslated'] ?? 0,
            'string_translations'  => $string_count,
            'default_language'     => $default_lang ? [
                'code' => $default_lang->code,
                'name' => $default_lang->native_name,
            ] : null,
            'translations'         => $translation_stats,
            'api_usage'            => $api_usage,
        ], 200 );
    }

    // =========================================================================
    // String Domains Endpoint
    // =========================================================================

    public function get_string_domains( \WP_REST_Request $request ): \WP_REST_Response {
        $domains = $this->string_translation->get_domains();
        return new \WP_REST_Response( $domains, 200 );
    }

    // =========================================================================
    // Tools Endpoints
    // =========================================================================

    public function get_migration_status( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->migration_tool->get_status(), 200 );
    }

    public function run_migration( \WP_REST_Request $request ): \WP_REST_Response {
        $source = sanitize_text_field( $request->get_param( 'source' ) );

        if ( 'wpml' === $source ) {
            $result = $this->migration_tool->migrate_from_wpml();
        } elseif ( 'polylang' === $source ) {
            $result = $this->migration_tool->migrate_from_polylang();
        } else {
            return new \WP_REST_Response( [ 'error' => __( 'Invalid migration source.', 'maharat-multilingual' ) ], 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function clear_cache( \WP_REST_Request $request ): \WP_REST_Response {
        // Flush language cache.
        $this->language_manager->flush_cache();

        // Flush all maharat transients.
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_maharat_%' OR option_name LIKE '_transient_timeout_maharat_%'"
        );

        // Flush object cache group.
        wp_cache_flush();

        return new \WP_REST_Response( [ 'message' => __( 'Cache cleared.', 'maharat-multilingual' ) ], 200 );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function get_language_args(): array {
        return [
            'code'        => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'locale'      => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'name'        => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'native_name' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'is_rtl'      => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
            'is_default'  => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
            'is_active'   => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
            'flag'        => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            'sort_order'  => [ 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ],
        ];
    }
}

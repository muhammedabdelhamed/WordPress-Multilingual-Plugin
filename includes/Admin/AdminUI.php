<?php
/**
 * Admin UI.
 *
 * Registers admin menu pages and enqueues the React dashboard app.
 *
 * @package Maharat\Multilingual\Admin
 */

namespace Maharat\Multilingual\Admin;

use Maharat\Multilingual\Core\LanguageManager;
use Maharat\Multilingual\Translation\StringTranslation;
use Maharat\Multilingual\Translation\TranslationManager;

defined( 'ABSPATH' ) || exit;

class AdminUI {

    private LanguageManager $language_manager;
    private TranslationManager $translation_manager;
    private StringTranslation $string_translation;

    public function __construct(
        LanguageManager $language_manager,
        TranslationManager $translation_manager,
        StringTranslation $string_translation
    ) {
        $this->language_manager    = $language_manager;
        $this->translation_manager = $translation_manager;
        $this->string_translation  = $string_translation;
    }

    /**
     * Initialise admin hooks.
     */
    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_menu' ], 100 );

        // Handle translation creation requests (before page renders).
        add_action( 'admin_init', [ $this, 'handle_create_translation' ] );

        // Show translation-created success notice.
        add_action( 'admin_notices', [ $this, 'show_translation_created_notice' ] );

        // Translation meta box on post editor.
        add_action( 'add_meta_boxes', [ $this, 'add_translation_meta_box' ] );
        add_action( 'save_post', [ $this, 'save_translation_meta_box' ], 10, 2 );

        // Plugin action links.
        add_filter( 'plugin_action_links_' . MAHARAT_PLUGIN_BASENAME, [ $this, 'add_plugin_links' ] );
    }

    /**
     * Handle translation creation requests.
     *
     * Intercepts admin.php?page=maharat-translations&action=create&source=ID&lang=XX
     * before the page renders, creates the translation, and redirects to the new post editor.
     */
    public function handle_create_translation(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'], $_GET['action'], $_GET['source'], $_GET['lang'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( 'maharat-translations' !== $_GET['page'] || 'create' !== $_GET['action'] ) {
            return;
        }

        // Verify nonce.
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'maharat_create_translation' ) ) {
            wp_die(
                esc_html__( 'Security check failed. Please try again.', 'maharat-multilingual' ),
                esc_html__( 'Error', 'maharat-multilingual' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        $source_id   = absint( wp_unslash( $_GET['source'] ) );
        $target_lang = sanitize_text_field( wp_unslash( $_GET['lang'] ) );

        // Validate permissions.
        if ( ! current_user_can( 'edit_post', $source_id ) ) {
            wp_die(
                esc_html__( 'You do not have permission to create translations for this post.', 'maharat-multilingual' ),
                esc_html__( 'Permission Denied', 'maharat-multilingual' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        // Validate source post exists.
        $source = get_post( $source_id );
        if ( ! $source ) {
            wp_die(
                esc_html__( 'Source post not found.', 'maharat-multilingual' ),
                esc_html__( 'Error', 'maharat-multilingual' ),
                [ 'response' => 404, 'back_link' => true ]
            );
        }

        // Validate target language exists.
        $lang = $this->language_manager->get_language( $target_lang );
        if ( ! $lang ) {
            wp_die(
                esc_html__( 'Invalid target language.', 'maharat-multilingual' ),
                esc_html__( 'Error', 'maharat-multilingual' ),
                [ 'response' => 400, 'back_link' => true ]
            );
        }

        // Check if translation already exists for this language.
        $existing = $this->translation_manager->get_post_translations( $source_id );
        if ( isset( $existing[ $target_lang ] ) ) {
            // Translation already exists — redirect to its editor.
            $edit_url = get_edit_post_link( $existing[ $target_lang ], 'raw' );
            wp_safe_redirect( $edit_url );
            exit;
        }

        // Create the translation.
        $new_id = $this->translation_manager->create_translation( $source_id, $target_lang );

        if ( false === $new_id ) {
            wp_die(
                esc_html__( 'Failed to create translation. Please try again.', 'maharat-multilingual' ),
                esc_html__( 'Error', 'maharat-multilingual' ),
                [ 'response' => 500, 'back_link' => true ]
            );
        }

        // Add admin notice via transient so it shows on the editor page.
        set_transient(
            'maharat_translation_created_' . get_current_user_id(),
            sprintf(
                /* translators: %s: language name */
                __( '%s translation created successfully. You can now edit the translated content.', 'maharat-multilingual' ),
                $lang->native_name
            ),
            30
        );

        // Redirect to the new post's editor.
        $edit_url = get_edit_post_link( $new_id, 'raw' );
        wp_safe_redirect( $edit_url );
        exit;
    }

    /**
     * Display a success notice after a translation is created.
     */
    public function show_translation_created_notice(): void {
        $transient_key = 'maharat_translation_created_' . get_current_user_id();
        $message       = get_transient( $transient_key );

        if ( ! $message ) {
            return;
        }

        delete_transient( $transient_key );

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html( $message )
        );
    }

    /**
     * Register admin menu pages.
     */
    public function register_menus(): void {
        // Main menu.
        add_menu_page(
            __( 'Maharat Multilingual', 'maharat-multilingual' ),
            __( 'Maharat', 'maharat-multilingual' ),
            'manage_options',
            'maharat-multilingual',
            [ $this, 'render_dashboard' ],
            'dashicons-translation',
            30
        );

        // Submenus.
        add_submenu_page(
            'maharat-multilingual',
            __( 'Dashboard', 'maharat-multilingual' ),
            __( 'Dashboard', 'maharat-multilingual' ),
            'manage_options',
            'maharat-multilingual',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'maharat-multilingual',
            __( 'Languages', 'maharat-multilingual' ),
            __( 'Languages', 'maharat-multilingual' ),
            'manage_options',
            'maharat-languages',
            [ $this, 'render_react_page' ]
        );

        add_submenu_page(
            'maharat-multilingual',
            __( 'Translations', 'maharat-multilingual' ),
            __( 'Translations', 'maharat-multilingual' ),
            'edit_posts',
            'maharat-translations',
            [ $this, 'render_react_page' ]
        );

        add_submenu_page(
            'maharat-multilingual',
            __( 'String Translation', 'maharat-multilingual' ),
            __( 'String Translation', 'maharat-multilingual' ),
            'manage_options',
            'maharat-string-translation',
            [ $this, 'render_react_page' ]
        );

        add_submenu_page(
            'maharat-multilingual',
            __( 'Settings', 'maharat-multilingual' ),
            __( 'Settings', 'maharat-multilingual' ),
            'manage_options',
            'maharat-settings',
            [ $this, 'render_react_page' ]
        );

        add_submenu_page(
            'maharat-multilingual',
            __( 'Tools', 'maharat-multilingual' ),
            __( 'Tools', 'maharat-multilingual' ),
            'manage_options',
            'maharat-tools',
            [ $this, 'render_react_page' ]
        );
    }

    /**
     * Render the dashboard page (React mount point).
     */
    public function render_dashboard(): void {
        echo '<div class="wrap"><div id="maharat-admin-app" data-page="dashboard"></div></div>';
    }

    /**
     * Render a React-powered admin page.
     */
    public function render_react_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        printf(
            '<div class="wrap"><div id="maharat-admin-app" data-page="%s"></div></div>',
            esc_attr( str_replace( 'maharat-', '', $page ) )
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook_suffix The current admin page.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        // Only on our pages.
        if ( ! str_contains( $hook_suffix, 'maharat' ) ) {
            // But always enqueue the post editor meta box styles.
            $screen = get_current_screen();
            if ( $screen && 'post' === $screen->base ) {
                wp_enqueue_style(
                    'maharat-editor',
                    MAHARAT_PLUGIN_URL . 'assets/css/editor.css',
                    [],
                    MAHARAT_VERSION
                );
            }
            return;
        }

        // Admin CSS.
        wp_enqueue_style(
            'maharat-admin',
            MAHARAT_PLUGIN_URL . 'assets/css/admin.css',
            [],
            MAHARAT_VERSION
        );

        // React app.
        $asset_file = MAHARAT_PLUGIN_DIR . 'assets/react/build/admin.asset.php';
        $asset      = file_exists( $asset_file ) ? require $asset_file : [
            'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ],
            'version'      => MAHARAT_VERSION,
        ];

        wp_enqueue_script(
            'maharat-admin-app',
            MAHARAT_PLUGIN_URL . 'assets/react/build/admin.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'maharat-admin-app',
            MAHARAT_PLUGIN_URL . 'assets/react/build/admin.css',
            [ 'wp-components' ],
            $asset['version']
        );

        // Localise data for the React app.
        wp_localize_script( 'maharat-admin-app', 'maharatData', [
            'restUrl'         => rest_url( 'maharat/v1/' ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'adminUrl'        => admin_url(),
            'pluginUrl'       => MAHARAT_PLUGIN_URL,
            'defaultLanguage' => $this->language_manager->get_default_language(),
            'currentLanguage' => $this->language_manager->get_current_language(),
            'languages'       => array_values( $this->language_manager->get_languages( true ) ),
            'version'         => MAHARAT_VERSION,
            'wpVersion'       => get_bloginfo( 'version' ),
            'phpVersion'      => PHP_VERSION,
            'activeTheme'     => wp_get_theme()->get( 'Name' ),
        ] );

        // Set up wp.i18n.
        wp_set_script_translations( 'maharat-admin-app', 'maharat-multilingual', MAHARAT_PLUGIN_DIR . 'languages' );
    }

    /**
     * Add language switcher to admin bar.
     *
     * @param \WP_Admin_Bar $admin_bar The admin bar.
     */
    public function add_admin_bar_menu( $admin_bar ): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        $current = $this->language_manager->get_current_language();
        $lang    = $this->language_manager->get_language( $current );

        $admin_bar->add_node( [
            'id'    => 'maharat-language',
            'title' => sprintf(
                '<span class="ab-icon dashicons-translation"></span> %s',
                esc_html( $lang ? $lang->native_name : $current )
            ),
            'href'  => admin_url( 'admin.php?page=maharat-multilingual' ),
        ] );

        // Sub items for each language.
        $languages = $this->language_manager->get_languages();
        foreach ( $languages as $l ) {
            $admin_bar->add_node( [
                'parent' => 'maharat-language',
                'id'     => 'maharat-lang-' . $l->code,
                'title'  => esc_html( $l->native_name ),
                'href'   => add_query_arg( 'lang', $l->code ),
                'meta'   => [
                    'class' => ( $l->code === $current ) ? 'maharat-current-lang' : '',
                ],
            ] );
        }
    }

    /**
     * Add translation meta box to post editors.
     */
    public function add_translation_meta_box(): void {
        $post_types = get_post_types( [ 'public' => true ] );
        foreach ( $post_types as $pt ) {
            add_meta_box(
                'maharat-translations',
                __( 'Translations', 'maharat-multilingual' ),
                [ $this, 'render_translation_meta_box' ],
                $pt,
                'side',
                'high'
            );
        }
    }

    /**
     * Render the translation meta box.
     *
     * @param \WP_Post $post The current post.
     */
    public function render_translation_meta_box( $post ): void {
        wp_nonce_field( 'maharat_translation_meta', 'maharat_translation_nonce' );

        $languages    = $this->language_manager->get_languages();
        $post_lang    = $this->translation_manager->get_post_language( $post->ID );
        $translations = $this->translation_manager->get_post_translations( $post->ID );

        echo '<div class="maharat-meta-box">';

        // Current language selector.
        echo '<p><strong>' . esc_html__( 'Language:', 'maharat-multilingual' ) . '</strong></p>';
        echo '<select name="maharat_post_language" id="maharat_post_language" class="widefat">';
        foreach ( $languages as $lang ) {
            printf(
                '<option value="%s"%s>%s (%s)</option>',
                esc_attr( $lang->code ),
                selected( $post_lang ?: $this->language_manager->get_default_language(), $lang->code, false ),
                esc_html( $lang->native_name ),
                esc_html( $lang->code )
            );
        }
        echo '</select>';

        // Translations list.
        echo '<p style="margin-top:12px"><strong>' . esc_html__( 'Translations:', 'maharat-multilingual' ) . '</strong></p>';
        echo '<ul class="maharat-translation-list">';

        foreach ( $languages as $lang ) {
            if ( $lang->code === $post_lang ) {
                continue;
            }

            echo '<li>';
            echo '<span class="maharat-lang-label">' . esc_html( $lang->native_name ) . ': </span>';

            if ( isset( $translations[ $lang->code ] ) ) {
                $edit_url = get_edit_post_link( $translations[ $lang->code ] );
                echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'maharat-multilingual' ) . '</a>';
            } else {
                $create_url = wp_nonce_url(
                    admin_url( sprintf(
                        'admin.php?page=maharat-translations&action=create&source=%d&lang=%s',
                        $post->ID,
                        $lang->code
                    ) ),
                    'maharat_create_translation'
                );
                echo '<a href="' . esc_url( $create_url ) . '" class="maharat-create-translation">';
                echo esc_html__( '+ Add', 'maharat-multilingual' );
                echo '</a>';
            }

            echo '</li>';
        }

        echo '</ul>';
        echo '</div>';
    }

    /**
     * Save translation meta box data.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function save_translation_meta_box( int $post_id, $post ): void {
        if ( ! isset( $_POST['maharat_translation_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['maharat_translation_nonce'] ) ), 'maharat_translation_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['maharat_post_language'] ) ) {
            $lang = sanitize_text_field( wp_unslash( $_POST['maharat_post_language'] ) );
            $this->translation_manager->set_post_language( $post_id, $lang );
        }
    }

    /**
     * Add plugin action links.
     *
     * @param array $links Existing links.
     * @return array
     */
    public function add_plugin_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=maharat-settings' ),
            __( 'Settings', 'maharat-multilingual' )
        );

        array_unshift( $links, $settings_link );

        return $links;
    }
}

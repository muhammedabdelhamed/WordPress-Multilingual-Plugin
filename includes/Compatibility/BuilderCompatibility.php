<?php
/**
 * Universal Page Builder Compatibility Engine.
 *
 * Detects active page builders and hooks safely to support translation cloning.
 *
 * @package Maharat\Multilingual\Compatibility
 */

namespace Maharat\Multilingual\Compatibility;

defined( 'ABSPATH' ) || exit;

class BuilderCompatibility {

    /**
     * Detected builders.
     *
     * @var array<string, bool>
     */
    private array $detected = [];

    /**
     * Initialise builder detection and hooks.
     */
    public function init(): void {
        $this->detect_builders();
        $this->register_hooks();
    }

    /**
     * Detect which page builders are active.
     */
    private function detect_builders(): void {
        // Elementor.
        $this->detected['elementor'] = did_action( 'elementor/loaded' ) > 0
            || defined( 'ELEMENTOR_VERSION' );

        // WPBakery.
        $this->detected['wpbakery'] = class_exists( 'Vc_Manager' )
            || defined( 'WPB_VC_VERSION' );

        // Divi.
        $this->detected['divi'] = defined( 'ET_BUILDER_VERSION' )
            || function_exists( 'et_setup_theme' );

        // Beaver Builder.
        $this->detected['beaver'] = class_exists( 'FLBuilderLoader' )
            || defined( 'FL_BUILDER_VERSION' );

        // Bricks Builder.
        $this->detected['bricks'] = defined( 'BRICKS_VERSION' )
            || class_exists( 'Bricks\\Theme' );

        // Gutenberg is always available in WP 5+.
        $this->detected['gutenberg'] = true;

        /**
         * Filter the detected builders.
         *
         * @param array $detected Builder detection results.
         */
        $this->detected = apply_filters( 'maharat_detected_builders', $this->detected );
    }

    /**
     * Register hooks for each detected builder.
     */
    private function register_hooks(): void {
        // Elementor: Clone Elementor data when creating translation.
        if ( $this->is_active( 'elementor' ) ) {
            add_action( 'maharat_after_translation_created', [ $this, 'clone_elementor_data' ], 10, 3 );
        }

        // WPBakery: Clone WPBakery shortcode data.
        if ( $this->is_active( 'wpbakery' ) ) {
            add_action( 'maharat_after_translation_created', [ $this, 'clone_wpbakery_data' ], 10, 3 );
        }

        // Divi: Clone Divi builder layout.
        if ( $this->is_active( 'divi' ) ) {
            add_action( 'maharat_after_translation_created', [ $this, 'clone_divi_data' ], 10, 3 );
        }

        // Beaver Builder.
        if ( $this->is_active( 'beaver' ) ) {
            add_action( 'maharat_after_translation_created', [ $this, 'clone_beaver_data' ], 10, 3 );
        }

        // Bricks Builder.
        if ( $this->is_active( 'bricks' ) ) {
            add_action( 'maharat_after_translation_created', [ $this, 'clone_bricks_data' ], 10, 3 );
        }

        // Gutenberg (block editor) - no special handling needed, content is in post_content.
    }

    /**
     * Check if a builder is active.
     *
     * @param string $builder Builder key.
     * @return bool
     */
    public function is_active( string $builder ): bool {
        return ! empty( $this->detected[ $builder ] );
    }

    /**
     * Get all detected builders.
     *
     * @return array<string, bool>
     */
    public function get_detected_builders(): array {
        return $this->detected;
    }

    // =========================================================================
    // Builder-specific cloning
    // =========================================================================

    /**
     * Clone Elementor page builder data.
     *
     * @param int    $new_id       New post ID.
     * @param int    $source_id    Source post ID.
     * @param string $target_lang  Target language.
     */
    public function clone_elementor_data( int $new_id, int $source_id, string $target_lang ): void {
        $elementor_data = get_post_meta( $source_id, '_elementor_data', true );
        if ( ! empty( $elementor_data ) ) {
            update_post_meta( $new_id, '_elementor_data', $elementor_data );
        }

        // Elementor edit mode.
        $edit_mode = get_post_meta( $source_id, '_elementor_edit_mode', true );
        if ( $edit_mode ) {
            update_post_meta( $new_id, '_elementor_edit_mode', $edit_mode );
        }

        // Elementor template type.
        $template_type = get_post_meta( $source_id, '_elementor_template_type', true );
        if ( $template_type ) {
            update_post_meta( $new_id, '_elementor_template_type', $template_type );
        }

        // Elementor page settings.
        $page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );
        if ( $page_settings ) {
            update_post_meta( $new_id, '_elementor_page_settings', $page_settings );
        }

        // CSS.
        $css = get_post_meta( $source_id, '_elementor_css', true );
        if ( $css ) {
            update_post_meta( $new_id, '_elementor_css', $css );
        }

        /**
         * Fires after Elementor data is cloned.
         *
         * @param int    $new_id     New post ID.
         * @param int    $source_id  Source post ID.
         * @param string $target_lang Target language.
         */
        do_action( 'maharat_elementor_data_cloned', $new_id, $source_id, $target_lang );
    }

    /**
     * Clone WPBakery page builder data.
     *
     * WPBakery stores content as shortcodes in post_content, so the main
     * content clone handles it. We just ensure the custom CSS is copied.
     *
     * @param int    $new_id       New post ID.
     * @param int    $source_id    Source post ID.
     * @param string $target_lang  Target language.
     */
    public function clone_wpbakery_data( int $new_id, int $source_id, string $target_lang ): void {
        $custom_css = get_post_meta( $source_id, '_wpb_shortcodes_custom_css', true );
        if ( ! empty( $custom_css ) ) {
            update_post_meta( $new_id, '_wpb_shortcodes_custom_css', $custom_css );
        }

        $post_custom_css = get_post_meta( $source_id, '_wpb_post_custom_css', true );
        if ( ! empty( $post_custom_css ) ) {
            update_post_meta( $new_id, '_wpb_post_custom_css', $post_custom_css );
        }

        do_action( 'maharat_wpbakery_data_cloned', $new_id, $source_id, $target_lang );
    }

    /**
     * Clone Divi builder data.
     *
     * @param int    $new_id       New post ID.
     * @param int    $source_id    Source post ID.
     * @param string $target_lang  Target language.
     */
    public function clone_divi_data( int $new_id, int $source_id, string $target_lang ): void {
        $divi_keys = [
            '_et_pb_use_builder',
            '_et_pb_old_content',
            '_et_pb_built_for_post_type',
            '_et_pb_ab_subjects',
            '_et_pb_custom_css',
        ];

        foreach ( $divi_keys as $key ) {
            $value = get_post_meta( $source_id, $key, true );
            if ( '' !== $value && false !== $value ) {
                update_post_meta( $new_id, $key, $value );
            }
        }

        do_action( 'maharat_divi_data_cloned', $new_id, $source_id, $target_lang );
    }

    /**
     * Clone Beaver Builder data.
     *
     * @param int    $new_id       New post ID.
     * @param int    $source_id    Source post ID.
     * @param string $target_lang  Target language.
     */
    public function clone_beaver_data( int $new_id, int $source_id, string $target_lang ): void {
        $bb_data = get_post_meta( $source_id, '_fl_builder_data', true );
        if ( ! empty( $bb_data ) ) {
            update_post_meta( $new_id, '_fl_builder_data', $bb_data );
        }

        $bb_settings = get_post_meta( $source_id, '_fl_builder_data_settings', true );
        if ( ! empty( $bb_settings ) ) {
            update_post_meta( $new_id, '_fl_builder_data_settings', $bb_settings );
        }

        $bb_draft = get_post_meta( $source_id, '_fl_builder_draft', true );
        if ( ! empty( $bb_draft ) ) {
            update_post_meta( $new_id, '_fl_builder_draft', $bb_draft );
        }

        $bb_enabled = get_post_meta( $source_id, '_fl_builder_enabled', true );
        if ( $bb_enabled ) {
            update_post_meta( $new_id, '_fl_builder_enabled', $bb_enabled );
        }

        do_action( 'maharat_beaver_data_cloned', $new_id, $source_id, $target_lang );
    }

    /**
     * Clone Bricks Builder data.
     *
     * @param int    $new_id       New post ID.
     * @param int    $source_id    Source post ID.
     * @param string $target_lang  Target language.
     */
    public function clone_bricks_data( int $new_id, int $source_id, string $target_lang ): void {
        $bricks_keys = [
            '_bricks_page_content_2',
            '_bricks_page_header_2',
            '_bricks_page_footer_2',
        ];

        foreach ( $bricks_keys as $key ) {
            $value = get_post_meta( $source_id, $key, true );
            if ( ! empty( $value ) ) {
                update_post_meta( $new_id, $key, $value );
            }
        }

        do_action( 'maharat_bricks_data_cloned', $new_id, $source_id, $target_lang );
    }

    /**
     * Get the builder used for a specific post.
     *
     * @param int $post_id Post ID.
     * @return string Builder name or 'classic'.
     */
    public function get_post_builder( int $post_id ): string {
        // Elementor.
        if ( $this->is_active( 'elementor' ) ) {
            $mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
            if ( 'builder' === $mode ) {
                return 'elementor';
            }
        }

        // Divi.
        if ( $this->is_active( 'divi' ) ) {
            $use = get_post_meta( $post_id, '_et_pb_use_builder', true );
            if ( 'on' === $use ) {
                return 'divi';
            }
        }

        // Beaver Builder.
        if ( $this->is_active( 'beaver' ) ) {
            $enabled = get_post_meta( $post_id, '_fl_builder_enabled', true );
            if ( $enabled ) {
                return 'beaver';
            }
        }

        // Bricks.
        if ( $this->is_active( 'bricks' ) ) {
            $content = get_post_meta( $post_id, '_bricks_page_content_2', true );
            if ( ! empty( $content ) ) {
                return 'bricks';
            }
        }

        // WPBakery uses shortcodes in post_content - detect by checking for [vc_ shortcodes.
        if ( $this->is_active( 'wpbakery' ) ) {
            $post = get_post( $post_id );
            if ( $post && str_contains( $post->post_content, '[vc_' ) ) {
                return 'wpbakery';
            }
        }

        // Check for Gutenberg blocks.
        $post = get_post( $post_id );
        if ( $post && has_blocks( $post->post_content ) ) {
            return 'gutenberg';
        }

        return 'classic';
    }
}

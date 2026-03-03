<?php
/**
 * Language Switcher.
 *
 * Provides shortcode, widget, nav-menu item, Elementor widget, and floating switcher
 * for language switching on the frontend.
 *
 * @package Maharat\Multilingual\Frontend
 */

namespace Maharat\Multilingual\Frontend;

use Maharat\Multilingual\Core\LanguageManager;
use Maharat\Multilingual\Core\UrlRouter;

defined( 'ABSPATH' ) || exit;

class LanguageSwitcher {

    private LanguageManager $language_manager;
    private UrlRouter $url_router;

    /**
     * Display options (merged with defaults at runtime).
     *
     * @var array<string, mixed>
     */
    private array $default_options = [
        'style'       => 'dropdown',   // dropdown | list | inline | flags
        'show_flags'  => true,
        'show_names'  => true,
        'show_native' => true,
        'hide_current' => false,
        'skip_missing' => false,
    ];

    public function __construct( LanguageManager $language_manager, UrlRouter $url_router ) {
        $this->language_manager = $language_manager;
        $this->url_router       = $url_router;
    }

    /* ------------------------------------------------------------------
     * Bootstrap
     * ----------------------------------------------------------------*/

    /**
     * Initialise frontend hooks.
     */
    public function init(): void {
        // Shortcode.
        add_shortcode( 'maharat_language_switcher', [ $this, 'shortcode' ] );

        // Enqueue frontend styles.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Register widget.
        add_action( 'widgets_init', [ $this, 'register_widget' ] );

        // Nav menu language items.
        add_filter( 'wp_nav_menu_items', [ $this, 'append_nav_menu_items' ], 20, 2 );

        // Floating switcher (footer).
        add_action( 'wp_footer', [ $this, 'render_floating_switcher' ] );

        // Register Elementor widget if Elementor is active.
        add_action( 'elementor/widgets/register', [ $this, 'register_elementor_widget' ] );
    }

    /* ------------------------------------------------------------------
     * Asset Enqueue
     * ----------------------------------------------------------------*/

    /**
     * Enqueue frontend CSS and JS.
     */
    public function enqueue_assets(): void {
        wp_enqueue_style(
            'maharat-frontend',
            MAHARAT_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            MAHARAT_VERSION
        );

        wp_enqueue_script(
            'maharat-frontend',
            MAHARAT_PLUGIN_URL . 'assets/js/frontend.js',
            [],
            MAHARAT_VERSION,
            true
        );

        wp_localize_script( 'maharat-frontend', 'maharatFront', [
            'currentLang' => $this->language_manager->get_current_language(),
        ] );
    }

    /* ------------------------------------------------------------------
     * Shortcode: [maharat_language_switcher]
     * ----------------------------------------------------------------*/

    /**
     * Render the language switcher shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function shortcode( $atts = [] ): string {
        $atts = shortcode_atts( $this->default_options, (array) $atts, 'maharat_language_switcher' );

        // Cast booleans.
        foreach ( [ 'show_flags', 'show_names', 'show_native', 'hide_current', 'skip_missing' ] as $key ) {
            $atts[ $key ] = filter_var( $atts[ $key ], FILTER_VALIDATE_BOOLEAN );
        }

        return $this->render( $atts );
    }

    /* ------------------------------------------------------------------
     * Rendering Engine
     * ----------------------------------------------------------------*/

    /**
     * Build language list data.
     *
     * @param array $options Display options.
     * @return array<int, array{code: string, name: string, native_name: string, flag_url: string, url: string, is_current: bool}>
     */
    private function build_language_list( array $options ): array {
        $languages    = $this->language_manager->get_languages();
        $current_code = $this->language_manager->get_current_language();
        $post_id      = get_queried_object_id();
        $items        = [];

        foreach ( $languages as $lang ) {
            $is_current = ( $lang->code === $current_code );

            if ( $is_current && $options['hide_current'] ) {
                continue;
            }

            // Resolve the URL for this language.
            $url = $this->resolve_url( $post_id, $lang->code );

            // Skip if no translation exists for this post.
            if ( $options['skip_missing'] && ! $is_current && empty( $url ) ) {
                continue;
            }

            $items[] = [
                'code'        => $lang->code,
                'name'        => $lang->name,
                'native_name' => $lang->native_name,
                'flag_url'    => $this->get_flag_url( $lang->code ),
                'url'         => $url ?: $this->url_router->get_language_url( $lang->code ),
                'is_current'  => $is_current,
            ];
        }

        return $items;
    }

    /**
     * Resolve the URL of the current page in a given language.
     *
     * @param int    $post_id      Current post / page ID.
     * @param string $language_code Target language code.
     * @return string The URL, or empty string if no translation exists.
     */
    private function resolve_url( int $post_id, string $language_code ): string {
        if ( $post_id > 0 ) {
            $url = $this->url_router->get_translated_url( $post_id, $language_code );
            if ( $url ) {
                return $url;
            }
        }

        // Fall back to the homepage in that language.
        return $this->url_router->get_language_url( $language_code );
    }

    /**
     * Render the switcher HTML.
     *
     * @param array $options Display options.
     * @return string
     */
    private function render( array $options ): string {
        $items = $this->build_language_list( $options );

        if ( empty( $items ) ) {
            return '';
        }

        ob_start();

        $template = locate_template( 'maharat/language-switcher.php' );
        if ( ! $template ) {
            $template = MAHARAT_PLUGIN_DIR . 'templates/language-switcher.php';
        }

        /**
         * Filters the path of the language switcher template.
         *
         * @param string $template Template path.
         * @param array  $options  Display options.
         */
        $template = apply_filters( 'maharat_switcher_template', $template, $options );

        // Make variables available to the template.
        $switcher_style = $options['style'];
        $show_flags     = $options['show_flags'];
        $show_names     = $options['show_names'];
        $show_native    = $options['show_native'];
        $current_lang   = $this->language_manager->get_current_language();

        include $template;

        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Flag Helpers
     * ----------------------------------------------------------------*/

    /**
     * Get the flag image URL for a language code.
     *
     * @param string $code ISO 639-1 language code.
     * @return string URL to the flag SVG/PNG.
     */
    private function get_flag_url( string $code ): string {
        // Check for a custom flag first.
        $custom = MAHARAT_PLUGIN_DIR . 'assets/flags/' . $code . '.svg';
        if ( file_exists( $custom ) ) {
            return MAHARAT_PLUGIN_URL . 'assets/flags/' . $code . '.svg';
        }

        /**
         * Filter the flag URL for a language code.
         *
         * Allows themes/plugins to provide custom flag assets.
         *
         * @param string $url  Default flag URL.
         * @param string $code Language code.
         */
        return apply_filters(
            'maharat_flag_url',
            MAHARAT_PLUGIN_URL . 'assets/flags/' . $code . '.svg',
            $code
        );
    }

    /* ------------------------------------------------------------------
     * Widget
     * ----------------------------------------------------------------*/

    /**
     * Register the language switcher widget.
     */
    public function register_widget(): void {
        register_widget( Maharat_Language_Switcher_Widget::class );
    }

    /* ------------------------------------------------------------------
     * Nav Menu Integration
     * ----------------------------------------------------------------*/

    /**
     * Optionally append language links to a nav menu.
     *
     * @param string   $items Menu HTML.
     * @param \stdClass $args  Menu arguments.
     * @return string
     */
    public function append_nav_menu_items( string $items, $args ): string {
        $target_menus = (array) get_option( 'maharat_nav_menu_switcher', [] );

        if ( empty( $target_menus ) || ! isset( $args->theme_location ) ) {
            return $items;
        }

        if ( ! in_array( $args->theme_location, $target_menus, true ) ) {
            return $items;
        }

        $languages = $this->build_language_list( $this->default_options );

        foreach ( $languages as $lang ) {
            $active = $lang['is_current'] ? ' class="current-language"' : '';
            $items .= sprintf(
                '<li%s><a href="%s">%s%s</a></li>',
                $active,
                esc_url( $lang['url'] ),
                $this->default_options['show_flags']
                    ? '<img src="' . esc_url( $lang['flag_url'] ) . '" alt="' . esc_attr( $lang['name'] ) . '" class="maharat-flag" /> '
                    : '',
                esc_html( $lang['native_name'] )
            );
        }

        return $items;
    }

    /* ------------------------------------------------------------------
     * Floating Switcher
     * ----------------------------------------------------------------*/

    /**
     * Render a floating language switcher in the footer.
     */
    public function render_floating_switcher(): void {
        if ( ! get_option( 'maharat_floating_switcher', false ) ) {
            return;
        }

        $position = get_option( 'maharat_floating_switcher_position', 'bottom-right' );

        echo $this->render( array_merge( $this->default_options, [
            'style' => 'dropdown',
        ] ) );

        printf(
            '<script>document.querySelector(".maharat-switcher")?.classList.add("maharat-floating","maharat-float-%s");</script>',
            esc_attr( $position )
        );
    }

    /* ------------------------------------------------------------------
     * Elementor Widget Registration
     * ----------------------------------------------------------------*/

    /**
     * Register our Elementor widget.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
     */
    public function register_elementor_widget( $widgets_manager ): void {
        if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
            return;
        }

        require_once MAHARAT_PLUGIN_DIR . 'includes/Frontend/Elementor_Language_Switcher_Widget.php';

        $widgets_manager->register(
            new Elementor_Language_Switcher_Widget( [], null, $this )
        );
    }

    /* ------------------------------------------------------------------
     * Public Getters (for use in templates)
     * ----------------------------------------------------------------*/

    /**
     * Get the built language list (useful in templates/widgets).
     *
     * @param array $options Override options.
     * @return array
     */
    public function get_language_list( array $options = [] ): array {
        return $this->build_language_list( array_merge( $this->default_options, $options ) );
    }
}

/* ======================================================================
 * Classic WordPress Widget
 * ====================================================================*/

/**
 * Language Switcher Widget for classic widgets screen.
 */
class Maharat_Language_Switcher_Widget extends \WP_Widget {

    public function __construct() {
        parent::__construct(
            'maharat_language_switcher',
            __( 'Maharat Language Switcher', 'maharat-multilingual' ),
            [ 'description' => __( 'Display a language switcher.', 'maharat-multilingual' ) ]
        );
    }

    /**
     * Front-end output.
     *
     * @param array $args     Widget area arguments.
     * @param array $instance Widget settings.
     */
    public function widget( $args, $instance ): void {
        echo $args['before_widget'];

        if ( ! empty( $instance['title'] ) ) {
            echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title'];
        }

        /** @var LanguageSwitcher $switcher */
        $switcher = \maharat()->language_switcher;

        $options = [
            'style'       => $instance['style'] ?? 'dropdown',
            'show_flags'  => (bool) ( $instance['show_flags'] ?? true ),
            'show_names'  => (bool) ( $instance['show_names'] ?? true ),
            'show_native' => (bool) ( $instance['show_native'] ?? true ),
            'hide_current' => (bool) ( $instance['hide_current'] ?? false ),
            'skip_missing' => (bool) ( $instance['skip_missing'] ?? false ),
        ];

        echo $switcher->shortcode( $options );

        echo $args['after_widget'];
    }

    /**
     * Admin form.
     *
     * @param array $instance Current settings.
     */
    public function form( $instance ): void {
        $title       = $instance['title'] ?? '';
        $style       = $instance['style'] ?? 'dropdown';
        $show_flags  = (bool) ( $instance['show_flags'] ?? true );
        $show_names  = (bool) ( $instance['show_names'] ?? true );
        $show_native = (bool) ( $instance['show_native'] ?? true );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_html_e( 'Title:', 'maharat-multilingual' ); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>">
                <?php esc_html_e( 'Style:', 'maharat-multilingual' ); ?>
            </label>
            <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"
                    name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
                <option value="dropdown" <?php selected( $style, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'maharat-multilingual' ); ?></option>
                <option value="list" <?php selected( $style, 'list' ); ?>><?php esc_html_e( 'List', 'maharat-multilingual' ); ?></option>
                <option value="inline" <?php selected( $style, 'inline' ); ?>><?php esc_html_e( 'Inline', 'maharat-multilingual' ); ?></option>
                <option value="flags" <?php selected( $style, 'flags' ); ?>><?php esc_html_e( 'Flags Only', 'maharat-multilingual' ); ?></option>
            </select>
        </p>
        <p>
            <input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'show_flags' ) ); ?>"
                   value="1" <?php checked( $show_flags ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'show_flags' ) ); ?>">
                <?php esc_html_e( 'Show flags', 'maharat-multilingual' ); ?>
            </label>
        </p>
        <p>
            <input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_names' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'show_names' ) ); ?>"
                   value="1" <?php checked( $show_names ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'show_names' ) ); ?>">
                <?php esc_html_e( 'Show names', 'maharat-multilingual' ); ?>
            </label>
        </p>
        <p>
            <input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_native' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'show_native' ) ); ?>"
                   value="1" <?php checked( $show_native ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id( 'show_native' ) ); ?>">
                <?php esc_html_e( 'Show native names', 'maharat-multilingual' ); ?>
            </label>
        </p>
        <?php
    }

    /**
     * Sanitize widget form values.
     *
     * @param array $new_instance New values.
     * @param array $old_instance Old values.
     * @return array Sanitized values.
     */
    public function update( $new_instance, $old_instance ): array {
        return [
            'title'       => sanitize_text_field( $new_instance['title'] ?? '' ),
            'style'       => in_array( $new_instance['style'] ?? '', [ 'dropdown', 'list', 'inline', 'flags' ], true )
                ? $new_instance['style'] : 'dropdown',
            'show_flags'  => ! empty( $new_instance['show_flags'] ),
            'show_names'  => ! empty( $new_instance['show_names'] ),
            'show_native' => ! empty( $new_instance['show_native'] ),
            'hide_current' => ! empty( $new_instance['hide_current'] ),
            'skip_missing' => ! empty( $new_instance['skip_missing'] ),
        ];
    }
}

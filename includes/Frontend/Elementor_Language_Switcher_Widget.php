<?php
/**
 * Elementor Language Switcher Widget.
 *
 * Provides an Elementor widget for the language switcher.
 *
 * @package Maharat\Multilingual\Frontend
 */

namespace Maharat\Multilingual\Frontend;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
    return;
}

class Elementor_Language_Switcher_Widget extends \Elementor\Widget_Base {

    /**
     * Reference to the main switcher service.
     */
    private LanguageSwitcher $switcher;

    /**
     * @param array               $data     Widget data.
     * @param array|null          $args     Widget arguments.
     * @param LanguageSwitcher    $switcher Switcher service.
     */
    public function __construct( $data = [], $args = null, ?LanguageSwitcher $switcher = null ) {
        parent::__construct( $data, $args );

        $this->switcher = $switcher ?? \maharat()->language_switcher;
    }

    public function get_name(): string {
        return 'maharat_language_switcher';
    }

    public function get_title(): string {
        return __( 'Language Switcher', 'maharat-multilingual' );
    }

    public function get_icon(): string {
        return 'eicon-globe';
    }

    public function get_categories(): array {
        return [ 'general' ];
    }

    /**
     * Register widget controls.
     */
    protected function register_controls(): void {
        $this->start_controls_section( 'content_section', [
            'label' => __( 'Switcher Settings', 'maharat-multilingual' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'style', [
            'label'   => __( 'Style', 'maharat-multilingual' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'dropdown',
            'options' => [
                'dropdown' => __( 'Dropdown', 'maharat-multilingual' ),
                'list'     => __( 'Vertical List', 'maharat-multilingual' ),
                'inline'   => __( 'Inline', 'maharat-multilingual' ),
                'flags'    => __( 'Flags Only', 'maharat-multilingual' ),
            ],
        ] );

        $this->add_control( 'show_flags', [
            'label'        => __( 'Show Flags', 'maharat-multilingual' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
        ] );

        $this->add_control( 'show_names', [
            'label'        => __( 'Show Language Names', 'maharat-multilingual' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
        ] );

        $this->add_control( 'show_native', [
            'label'        => __( 'Show Native Names', 'maharat-multilingual' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'return_value' => 'yes',
        ] );

        $this->add_control( 'hide_current', [
            'label'        => __( 'Hide Current Language', 'maharat-multilingual' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => '',
            'return_value' => 'yes',
        ] );

        $this->add_control( 'skip_missing', [
            'label'        => __( 'Skip Missing Translations', 'maharat-multilingual' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'default'      => '',
            'return_value' => 'yes',
        ] );

        $this->end_controls_section();
    }

    /**
     * Render widget output on the frontend.
     */
    protected function render(): void {
        $settings = $this->get_settings_for_display();

        echo $this->switcher->shortcode( [
            'style'        => $settings['style'] ?? 'dropdown',
            'show_flags'   => 'yes' === ( $settings['show_flags'] ?? 'yes' ),
            'show_names'   => 'yes' === ( $settings['show_names'] ?? 'yes' ),
            'show_native'  => 'yes' === ( $settings['show_native'] ?? 'yes' ),
            'hide_current' => 'yes' === ( $settings['hide_current'] ?? '' ),
            'skip_missing' => 'yes' === ( $settings['skip_missing'] ?? '' ),
        ] );
    }
}

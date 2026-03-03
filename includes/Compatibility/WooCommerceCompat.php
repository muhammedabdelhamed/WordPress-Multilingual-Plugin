<?php
/**
 * WooCommerce Compatibility.
 *
 * Handles translation of products, variations, attributes, categories, and checkout.
 *
 * @package Maharat\Multilingual\Compatibility
 */

namespace Maharat\Multilingual\Compatibility;

use Maharat\Multilingual\Core\LanguageManager;
use Maharat\Multilingual\Translation\TranslationManager;

defined( 'ABSPATH' ) || exit;

class WooCommerceCompat {

    private LanguageManager $language_manager;
    private TranslationManager $translation_manager;

    public function __construct( LanguageManager $language_manager, TranslationManager $translation_manager ) {
        $this->language_manager    = $language_manager;
        $this->translation_manager = $translation_manager;
    }

    /**
     * Initialise WooCommerce hooks.
     */
    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Product translation support.
        add_action( 'maharat_after_translation_created', [ $this, 'clone_product_data' ], 15, 3 );

        // Filter product queries by language.
        add_action( 'woocommerce_product_query', [ $this, 'filter_product_query' ] );

        // Translate product attributes.
        add_filter( 'woocommerce_attribute_label', [ $this, 'translate_attribute_label' ], 10, 3 );

        // Sync stock across translations.
        if ( get_option( 'maharat_woo_sync_stock', '1' ) === '1' ) {
            add_action( 'woocommerce_product_set_stock', [ $this, 'sync_stock' ] );
            add_action( 'woocommerce_variation_set_stock', [ $this, 'sync_stock' ] );
        }

        // Translate checkout fields.
        add_filter( 'woocommerce_checkout_fields', [ $this, 'translate_checkout_fields' ] );

        // Translate email strings.
        add_filter( 'woocommerce_email_heading_new_order', [ $this, 'translate_email_string' ] );
        add_filter( 'woocommerce_email_subject_new_order', [ $this, 'translate_email_string' ] );
        add_filter( 'woocommerce_email_heading_processing_order', [ $this, 'translate_email_string' ] );
        add_filter( 'woocommerce_email_subject_processing_order', [ $this, 'translate_email_string' ] );

        // Translate cart item names.
        add_filter( 'woocommerce_cart_item_name', [ $this, 'translate_cart_item_name' ], 10, 3 );

        // Price display currency.
        add_filter( 'woocommerce_currency', [ $this, 'filter_currency' ] );

        /**
         * Fires after WooCommerce compatibility hooks are registered.
         */
        do_action( 'maharat_woocommerce_compat_init' );
    }

    /**
     * Clone WooCommerce product data when creating a translation.
     *
     * @param int    $new_id      New post ID.
     * @param int    $source_id   Source post ID.
     * @param string $target_lang Target language.
     */
    public function clone_product_data( int $new_id, int $source_id, string $target_lang ): void {
        $post_type = get_post_type( $source_id );
        if ( 'product' !== $post_type ) {
            return;
        }

        // Clone WooCommerce-specific meta.
        $woo_meta_keys = [
            '_sku',
            '_regular_price',
            '_sale_price',
            '_sale_price_dates_from',
            '_sale_price_dates_to',
            '_price',
            '_weight',
            '_length',
            '_width',
            '_height',
            '_tax_status',
            '_tax_class',
            '_manage_stock',
            '_stock',
            '_stock_status',
            '_backorders',
            '_low_stock_amount',
            '_sold_individually',
            '_virtual',
            '_downloadable',
            '_product_image_gallery',
            '_product_attributes',
        ];

        /**
         * Filter the WooCommerce meta keys to clone.
         *
         * @param array $woo_meta_keys Meta keys.
         * @param int   $source_id     Source product ID.
         */
        $woo_meta_keys = apply_filters( 'maharat_woo_clone_meta_keys', $woo_meta_keys, $source_id );

        foreach ( $woo_meta_keys as $key ) {
            $value = get_post_meta( $source_id, $key, true );
            if ( '' !== $value && false !== $value ) {
                update_post_meta( $new_id, $key, $value );
            }
        }

        // Clone product variations.
        $this->clone_variations( $source_id, $new_id, $target_lang );

        do_action( 'maharat_woo_product_cloned', $new_id, $source_id, $target_lang );
    }

    /**
     * Clone product variations.
     *
     * @param int    $source_id   Source product ID.
     * @param int    $new_id      New product ID.
     * @param string $target_lang Target language.
     */
    private function clone_variations( int $source_id, int $new_id, string $target_lang ): void {
        $variations = get_posts( [
            'post_type'   => 'product_variation',
            'post_parent' => $source_id,
            'numberposts' => -1,
            'post_status' => 'any',
        ] );

        foreach ( $variations as $variation ) {
            $new_variation_data = [
                'post_title'   => $variation->post_title,
                'post_content' => $variation->post_content,
                'post_excerpt' => $variation->post_excerpt,
                'post_status'  => $variation->post_status,
                'post_type'    => 'product_variation',
                'post_parent'  => $new_id,
                'menu_order'   => $variation->menu_order,
            ];

            $new_var_id = wp_insert_post( $new_variation_data );
            if ( is_wp_error( $new_var_id ) ) {
                continue;
            }

            // Clone variation meta.
            $meta = get_post_meta( $variation->ID );
            foreach ( $meta as $key => $values ) {
                if ( str_starts_with( $key, '_edit_' ) ) {
                    continue;
                }
                foreach ( $values as $value ) {
                    add_post_meta( $new_var_id, $key, maybe_unserialize( $value ) );
                }
            }
        }
    }

    /**
     * Filter WooCommerce product queries to current language.
     *
     * @param \WC_Query $query WooCommerce query.
     */
    public function filter_product_query( $query ): void {
        // Language filtering is handled by TranslationManager::filter_posts_by_language.
        // This hook is available for additional WooCommerce-specific filtering.

        /**
         * Filter the WooCommerce product query.
         *
         * @param \WC_Query $query The query.
         */
        do_action( 'maharat_woo_product_query', $query );
    }

    /**
     * Translate a product attribute label.
     *
     * @param string $label     Attribute label.
     * @param string $name      Attribute name.
     * @param object $product   Product object.
     * @return string
     */
    public function translate_attribute_label( string $label, string $name, $product ): string {
        /**
         * Filter the translated attribute label.
         *
         * @param string $label   Label.
         * @param string $name    Attribute name.
         * @param object $product Product.
         */
        return apply_filters( 'maharat_translate_string', $label, 'woocommerce', "attribute_{$name}" );
    }

    /**
     * Sync stock levels across product translations.
     *
     * @param \WC_Product $product The product.
     */
    public function sync_stock( $product ): void {
        $post_id = $product->get_id();
        $translations = $this->translation_manager->get_post_translations( $post_id );

        foreach ( $translations as $lang => $tid ) {
            if ( $tid === $post_id ) {
                continue;
            }

            $stock_qty    = $product->get_stock_quantity();
            $stock_status = $product->get_stock_status();

            update_post_meta( $tid, '_stock', $stock_qty );
            update_post_meta( $tid, '_stock_status', $stock_status );
        }
    }

    /**
     * Translate WooCommerce checkout fields.
     *
     * @param array $fields Checkout fields.
     * @return array
     */
    public function translate_checkout_fields( array $fields ): array {
        foreach ( $fields as $section => &$section_fields ) {
            foreach ( $section_fields as $key => &$field ) {
                if ( isset( $field['label'] ) ) {
                    $field['label'] = apply_filters( 'maharat_translate_string', $field['label'], 'woocommerce', "checkout_{$key}" );
                }
                if ( isset( $field['placeholder'] ) ) {
                    $field['placeholder'] = apply_filters( 'maharat_translate_string', $field['placeholder'], 'woocommerce', "checkout_{$key}_placeholder" );
                }
            }
        }
        return $fields;
    }

    /**
     * Translate WooCommerce email strings.
     *
     * @param string $string The string.
     * @return string
     */
    public function translate_email_string( string $string ): string {
        return apply_filters( 'maharat_translate_string', $string, 'woocommerce', 'email' );
    }

    /**
     * Translate cart item product name.
     *
     * @param string $name     Product name.
     * @param array  $cart_item Cart item data.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function translate_cart_item_name( string $name, $cart_item, $cart_item_key ): string {
        if ( empty( $cart_item['product_id'] ) ) {
            return $name;
        }

        $current_lang = $this->language_manager->get_current_language();
        $translations = $this->translation_manager->get_post_translations( $cart_item['product_id'] );

        if ( isset( $translations[ $current_lang ] ) ) {
            $translated_product = get_post( $translations[ $current_lang ] );
            if ( $translated_product ) {
                return $translated_product->post_title;
            }
        }

        return $name;
    }

    /**
     * Filter currency per language (if configured).
     *
     * @param string $currency Currency code.
     * @return string
     */
    public function filter_currency( string $currency ): string {
        $current_lang   = $this->language_manager->get_current_language();
        $lang_currencies = get_option( 'maharat_woo_currencies', [] );

        if ( is_array( $lang_currencies ) && isset( $lang_currencies[ $current_lang ] ) ) {
            return $lang_currencies[ $current_lang ];
        }

        return $currency;
    }
}

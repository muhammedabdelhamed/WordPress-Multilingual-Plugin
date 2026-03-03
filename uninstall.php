<?php
/**
 * Uninstall Maharat Multilingual.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * through the WordPress admin.
 *
 * @package Maharat\Multilingual
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Only run if the user explicitly opted in to remove data.
$remove_data = get_option( 'maharat_remove_data_on_uninstall', false );

if ( ! $remove_data ) {
    return;
}

global $wpdb;

/* ------------------------------------------------------------------
 * 1. Drop custom tables.
 * ----------------------------------------------------------------*/

$tables = [
    $wpdb->prefix . 'maharat_languages',
    $wpdb->prefix . 'maharat_translations',
    $wpdb->prefix . 'maharat_taxonomy_translations',
    $wpdb->prefix . 'maharat_string_translations',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

/* ------------------------------------------------------------------
 * 2. Remove options.
 * ----------------------------------------------------------------*/

$options = [
    'maharat_default_language',
    'maharat_url_mode',
    'maharat_db_version',
    'maharat_active',
    'maharat_remove_data_on_uninstall',
    'maharat_floating_switcher',
    'maharat_floating_switcher_position',
    'maharat_nav_menu_switcher',
    'maharat_auto_translate_provider',
    'maharat_google_translate_api_key',
    'maharat_deepl_api_key',
    'maharat_openai_api_key',
    'maharat_auto_translate_usage',
    'maharat_woocommerce_sync_stock',
    'maharat_woocommerce_currency_per_lang',
    'maharat_seo_hreflang',
    'maharat_seo_canonical',
    'maharat_browser_redirect',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

/* ------------------------------------------------------------------
 * 3. Remove post meta.
 * ----------------------------------------------------------------*/

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_maharat_%'" ); // phpcs:ignore

/* ------------------------------------------------------------------
 * 4. Remove term meta.
 * ----------------------------------------------------------------*/

$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '_maharat_%'" ); // phpcs:ignore

/* ------------------------------------------------------------------
 * 5. Clear transients and object cache.
 * ----------------------------------------------------------------*/

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_maharat_%'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_maharat_%'" ); // phpcs:ignore

wp_cache_flush();

/* ------------------------------------------------------------------
 * 6. Flush rewrite rules (remove language prefixes).
 * ----------------------------------------------------------------*/

flush_rewrite_rules();

<?php
/**
 * Plugin Name: Maharat WordPress Multilingual
 * Plugin URI:  https://maharat.dev
 * Description: A universal, lightweight, future-proof multilingual plugin for WordPress. Translates any content type, works with all page builders, and never conflicts with themes or plugins.
 * Version:     1.0.0
 * Author:      Maharat
 * Author URI:  https://maharat.dev
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: maharat-multilingual
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package Maharat\Multilingual
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'MAHARAT_VERSION', '1.0.0' );
define( 'MAHARAT_PLUGIN_FILE', __FILE__ );
define( 'MAHARAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAHARAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAHARAT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MAHARAT_DB_VERSION', '1.0.0' );
define( 'MAHARAT_MIN_WP_VERSION', '6.0' );
define( 'MAHARAT_MIN_PHP_VERSION', '8.0' );

// Autoloader.
require_once MAHARAT_PLUGIN_DIR . 'includes/autoload.php';

/**
 * Run activation routine.
 */
function maharat_activate(): void {
    $installer = new Maharat\Multilingual\Core\Installer();
    $installer->activate();
}
register_activation_hook( __FILE__, 'maharat_activate' );

/**
 * Run deactivation routine.
 */
function maharat_deactivate(): void {
    $installer = new Maharat\Multilingual\Core\Installer();
    $installer->deactivate();
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'maharat_deactivate' );

/**
 * Return the main plugin instance.
 *
 * @return Maharat\Multilingual\Core\Plugin
 */
function maharat(): Maharat\Multilingual\Core\Plugin {
    return Maharat\Multilingual\Core\Plugin::instance();
}

/**
 * Check whether the required database tables exist.
 *
 * @return bool True if the primary languages table exists.
 */
function maharat_tables_exist(): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'maharat_languages';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

// Boot the plugin.
add_action( 'plugins_loaded', static function (): void {
    // Load text domain.
    load_plugin_textdomain( 'maharat-multilingual', false, dirname( MAHARAT_PLUGIN_BASENAME ) . '/languages' );

    // Auto-create / upgrade tables if DB version has changed or tables are missing.
    $installed_db_version = get_option( 'maharat_db_version', '' );
    if ( $installed_db_version !== MAHARAT_DB_VERSION || ! maharat_tables_exist() ) {
        $installer = new Maharat\Multilingual\Core\Installer();
        $installer->activate();
    }

    // Initialize.
    maharat()->init();

    /**
     * Fires after Maharat Multilingual is fully loaded.
     *
     * @param Maharat\Multilingual\Core\Plugin $plugin The plugin instance.
     */
    do_action( 'maharat_loaded', maharat() );
}, 5 );

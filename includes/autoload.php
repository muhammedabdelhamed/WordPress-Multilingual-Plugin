<?php
/**
 * PSR-4 Autoloader for Maharat Multilingual.
 *
 * Maps the Maharat\Multilingual namespace to the includes/ directory.
 *
 * @package Maharat\Multilingual
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register( static function ( string $class ): void {
    $prefix    = 'Maharat\\Multilingual\\';
    $base_dir  = __DIR__ . '/';

    // Only handle our namespace.
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    // Build the file path.
    $relative_class = substr( $class, $len );
    $file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

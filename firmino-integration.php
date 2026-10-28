<?php
/**
 * Plugin Name: Firmino Integration
 * Description: Integrates WooCommerce orders with the Firmino API.
 * Version: 2.0.0
 * Author: Marcin Bojarski
 * Text Domain: firmino-integration
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// The plugin has no Composer dependencies; map the PSR-4 namespace directly.
	spl_autoload_register( static function ( string $class ): void {
		$prefix = 'FirminoIntegration\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	} );
}

add_action( 'plugins_loaded', [ FirminoIntegration\Plugin::class, 'init' ] );

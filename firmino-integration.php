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

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', [ FirminoIntegration\Plugin::class, 'init' ] );

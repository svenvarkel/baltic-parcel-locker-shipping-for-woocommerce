<?php
/**
 * Plugin Name:       Baltic Parcel Locker Shipping for WooCommerce
 * Plugin URI:        https://github.com/svenvarkel/baltic-parcel-locker-shipping-for-woocommerce
 * Description:       Omniva and SmartPost parcel machines: checkout picker, one-click shipments and printable labels.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Sven Varkel
 * Author URI:        https://github.com/svenvarkel
 * Text Domain:       baltic-parcel-locker-shipping-for-woocommerce
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.4
 */

defined( 'ABSPATH' ) || exit;

const BPL_VERSION = '0.1.0';

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'init', function () {
	load_plugin_textdomain( 'baltic-parcel-locker-shipping-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-bpl-crypto.php';
	require_once __DIR__ . '/includes/class-bpl-settings.php';
	require_once __DIR__ . '/includes/class-bpl-carriers.php';
	require_once __DIR__ . '/includes/class-bpl-shipping-method.php';
	BPL_Settings::init();
	BPL_Shipping_Method::init();
	if ( is_admin() ) {
		require_once __DIR__ . '/includes/class-bpl-admin.php';
		BPL_Admin::init();
	}
} );

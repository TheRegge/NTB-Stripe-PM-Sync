<?php
/**
 * Plugin Name: NTB Stripe PM Sync
 * Description: Patches a metadata refresh bug in WooCommerce Stripe Gateway fingerprint deduplication.
 * Version:     1.0.0
 * Author:      NTB Insiders
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NTB_STRIPE_PM_SYNC_VERSION', '1.0.0' );
define( 'NTB_STRIPE_PM_SYNC_DIR', plugin_dir_path( __FILE__ ) );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	if ( ! class_exists( 'WC_Stripe' ) ) {
		return;
	}

	require_once NTB_STRIPE_PM_SYNC_DIR . 'includes/class-ntb-token-refresher.php';
	require_once NTB_STRIPE_PM_SYNC_DIR . 'includes/class-ntb-admin-diagnostics.php';

	new NTB\StripePMSync\Token_Refresher();
	new NTB\StripePMSync\Admin_Diagnostics();
} );

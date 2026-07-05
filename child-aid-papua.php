<?php
/**
 * Plugin Name:       Child Aid Papua – 1% Spende
 * Plugin URI:        https://3ag.education
 * Description:       Zeigt die Child-Aid-Papua-Story unter /child-aid-papua, informiert im Checkout über die 1%-Spende vom Umsatz und liefert einen Spendenbericht im Backend.
 * Version:           1.0.1
 * Author:            3ag.education
 * License:           GPL-2.0-or-later
 * Text Domain:       child-aid-papua
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CAP_PLUGIN_FILE', __FILE__ );
define( 'CAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CAP_PAGE_SLUG', 'child-aid-papua' );
define( 'CAP_DEFAULT_PERCENTAGE', 1.0 );

require_once CAP_PLUGIN_DIR . 'includes/class-cap-page.php';
require_once CAP_PLUGIN_DIR . 'includes/class-cap-checkout.php';
require_once CAP_PLUGIN_DIR . 'includes/class-cap-report.php';

// WooCommerce HPOS (custom order tables) compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * The donation percentage (e.g. 1.0 = 1% of revenue).
 */
function cap_get_donation_percentage() {
	$pct = (float) get_option( 'cap_donation_percentage', CAP_DEFAULT_PERCENTAGE );
	if ( $pct <= 0 ) {
		$pct = CAP_DEFAULT_PERCENTAGE;
	}
	return (float) apply_filters( 'cap_donation_percentage', $pct );
}

/**
 * Donation amount for a given revenue base.
 */
function cap_calculate_donation( $base, $percentage = null ) {
	$percentage = null === $percentage ? cap_get_donation_percentage() : (float) $percentage;
	return round( max( 0, (float) $base ) * $percentage / 100, 2 );
}

/**
 * URL of the story page.
 */
function cap_get_page_url() {
	return home_url( '/' . CAP_PAGE_SLUG . '/' );
}

CAP_Page::init();
CAP_Checkout::init();
CAP_Report::init();

register_activation_hook( __FILE__, function () {
	CAP_Page::register_rewrite();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

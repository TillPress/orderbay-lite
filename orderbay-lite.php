<?php
/**
 * Plugin Name:       OrderBay Lite
 * Plugin URI:        https://tillpress.com/orderbay/
 * Description:       Free WooCommerce invoices: printable invoices, sequential invoice numbers, credit notes, customer My Account invoice download, and UBL 2.1 / Factur-X CII e-invoice XML export.
 * Version:           1.0.1
 * Author:            Tillpress
 * Author URI:        https://tillpress.com
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       orderbay-lite
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OBLT_VERSION', '1.0.1' );
define( 'OBLT_BRAND_NAME', 'OrderBay Lite' );
define( 'OBLT_PLUGIN_FILE', __FILE__ );
define( 'OBLT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OBLT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OBLT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-plugin.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-documents.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-invoicing.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-einvoice.php';

/**
 * Whether the paid OrderBay plugin is active. Lite steps aside so a store
 * never gets duplicate invoice buttons or a second number sequence.
 *
 * @return bool
 */
function oblt_pro_is_active() {
	return defined( 'OB_VERSION' ) || class_exists( 'OB_Plugin', false );
}

/**
 * Bootstrap when WooCommerce is active.
 */
function oblt_init() {
	if ( oblt_pro_is_active() ) {
		add_action( 'admin_notices', 'oblt_pro_active_notice' );
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'oblt_woocommerce_missing_notice' );
		return;
	}

	OBLT_Plugin::instance();
	OBLT_Documents::instance();
	OBLT_Invoicing::instance();
	OBLT_EInvoice::instance();
}
add_action( 'plugins_loaded', 'oblt_init', 20 );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OBLT_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', OBLT_PLUGIN_FILE, true );
		}
	}
);

function oblt_pro_active_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}
	echo '<div class="notice notice-info"><p>';
	echo esc_html__( 'OrderBay (paid) is active, so OrderBay Lite is idle. You can deactivate Lite — the paid plugin already includes these invoicing tools.', 'orderbay-lite' );
	echo '</p></div>';
}

function oblt_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo wp_kses_post(
		sprintf(
			/* translators: %s: plugin name */
			__( '<strong>%s</strong> requires WooCommerce to be active.', 'orderbay-lite' ),
			OBLT_BRAND_NAME
		)
	);
	echo '</p></div>';
}

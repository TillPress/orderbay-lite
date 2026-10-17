<?php
/**
 * Plugin Name:       OrderBay Lite
 * Plugin URI:        https://tillpress.com/orderbay/
 * Description:       Free WooCommerce invoices: PDF invoices attached to order emails, printable invoices, sequential invoice numbers, credit notes, customer My Account PDF download, and UBL 2.1 / Factur-X CII e-invoice XML export.
 * Version:           1.3.0
 * Author:            Tillpress
 * Author URI:        https://tillpress.com
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:  11.2
 * Requires Plugins:  woocommerce
 * Text Domain:       orderbay-lite
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OBLT_VERSION', '1.3.0' );
define( 'OBLT_BRAND_NAME', 'OrderBay Lite' );
define( 'OBLT_PLUGIN_FILE', __FILE__ );
define( 'OBLT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OBLT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OBLT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-plugin.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-documents.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-pdf.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-invoicing.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-einvoice.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-withdrawal.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-demo.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-support.php';
require_once OBLT_PLUGIN_DIR . 'includes/class-oblt-hub.php';

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
	OBLT_Pdf::instance();
	OBLT_Invoicing::instance();
	OBLT_EInvoice::instance();
	OBLT_Withdrawal::instance();
	if ( is_admin() ) {
		OBLT_Support::instance();
		OBLT_Hub::instance();
	}
}
add_action( 'plugins_loaded', 'oblt_init', 20 );

register_activation_hook( __FILE__, 'oblt_activate' );
/**
 * Raise Lite counters to max(lite, paid) and drop the one-shot high-water marker
 * so the first allocation after a paid stretch cannot reuse INV-N.
 */
function oblt_activate() {
	if ( class_exists( 'OBLT_Invoicing' ) ) {
		OBLT_Invoicing::migrate_paid_settings();
		delete_option( OBLT_Invoicing::highwater_marker_key( OBLT_Plugin::OPT_INVOICE_NEXT ) );
		delete_option( OBLT_Invoicing::highwater_marker_key( OBLT_Plugin::OPT_CREDIT_NEXT ) );
	}
}

register_deactivation_hook( __FILE__, 'oblt_deactivate' );
/**
 * Stop the hourly sweep of temporary email PDFs.
 */
function oblt_deactivate() {
	wp_clear_scheduled_hook( 'oblt_pdf_tmp_sweep' );
}

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

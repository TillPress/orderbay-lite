<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core: shared constants, document settings, admin menu shell.
 */
class OBLT_Plugin {

	const OPT_DOCS           = 'oblt_documents_settings';
	const OPT_INVOICE_PREFIX = 'oblt_invoice_prefix';
	const OPT_INVOICE_NEXT   = 'oblt_invoice_next';
	const OPT_INVOICE_FORMAT = 'oblt_invoice_format';
	const OPT_INVOICE_RESET  = 'oblt_invoice_reset';
	const OPT_CREDIT_PREFIX  = 'oblt_credit_prefix';
	const OPT_CREDIT_NEXT    = 'oblt_credit_next';
	const OPT_CREDIT_FORMAT  = 'oblt_credit_format';
	const OPT_CREDIT_RESET   = 'oblt_credit_reset';

	const META_INVOICE_NUMBER = '_oblt_invoice_number';
	const META_CREDIT_NUMBER  = '_oblt_credit_note_number';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 55 );
		self::register_settings_capability();
	}

	/**
	 * The settings form posts to options.php, which requires manage_options
	 * by default. Let whoever can open the page (manage_woocommerce, as in
	 * register_menu()) also save it, so shop managers are not refused.
	 */
	public static function register_settings_capability() {
		add_filter( 'option_page_capability_oblt_documents', array( __CLASS__, 'settings_capability' ) );
	}

	/**
	 * @return string
	 */
	public static function settings_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Default document settings.
	 *
	 * @return array
	 */
	public static function default_doc_settings() {
		return array(
			'logo_url'        => '',
			'from_lines'      => get_bloginfo( 'name' ) . "\n" . get_option( 'woocommerce_store_address', '' ),
			'footer_text'     => __( 'Thank you for your order.', 'orderbay-lite' ),
			'paper'           => 'letter',
			'tax_id'          => '',
			'peppol_id'       => '',
			'seller_country'  => '',
			// PDF invoices in emails: on by default for these customer emails.
			'email_attach_on' => '1',
			'email_attach'    => array( 'customer_completed_order', 'customer_invoice' ),
		);
	}

	/**
	 * @return array
	 */
	public static function get_doc_settings() {
		$raw = get_option( self::OPT_DOCS, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return wp_parse_args( $raw, self::default_doc_settings() );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'OrderBay Lite invoices', 'orderbay-lite' ),
			__( 'OrderBay Lite', 'orderbay-lite' ),
			'manage_woocommerce',
			'orderbay-lite',
			array( 'OBLT_Documents', 'render_settings_static' )
		);
	}
}

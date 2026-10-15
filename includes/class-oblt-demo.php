<?php
/**
 * Demo data for OrderBay Lite: one completed order with a DEMO- invoice number
 * and a partial refund, so invoice, credit note, UBL, and CII can be opened.
 * The real invoice / credit note counters are never touched.
 *
 * @package OrderBayLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Demo order create/remove + report lines.
 */
class OBLT_Demo {

	const OPTION  = 'oblt_demo_ids';
	const META    = '_oblt_demo';
	const CREATED = 'oblt-demo';

	/**
	 * Stored demo object IDs.
	 *
	 * @return array{orders:int[]}
	 */
	public static function ids() {
		$ids = get_option( self::OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();
		return array( 'orders' => array_values( array_filter( array_map( 'absint', (array) ( $ids['orders'] ?? array() ) ) ) ) );
	}

	/**
	 * Whether demo data exists.
	 *
	 * @return bool
	 */
	public static function exists() {
		$ids = self::ids();
		foreach ( $ids['orders'] as $id ) {
			if ( function_exists( 'wc_get_order' ) && wc_get_order( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create the demo order. Sends no email; does not advance real counters.
	 *
	 * @return int|WP_Error Order ID.
	 */
	public static function create() {
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_create_refund' ) ) {
			return new WP_Error( 'oblt_demo_no_wc', 'WooCommerce is not active.' );
		}
		if ( self::exists() ) {
			$ids = self::ids();
			return $ids['orders'][0];
		}
		self::mute_emails( true );
		try {
			$order = wc_create_order( array( 'created_via' => self::CREATED ) );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$lines = array(
				array( __( 'Demo: Linen apron', 'orderbay-lite' ), 2, 24.00 ),
				array( __( 'Demo: Ceramic pour-over set', 'orderbay-lite' ), 1, 38.00 ),
			);
			foreach ( $lines as $line ) {
				$item = new WC_Order_Item_Fee();
				/* translators: 1: item name, 2: quantity. */
				$item->set_name( sprintf( __( '%1$s x %2$d', 'orderbay-lite' ), $line[0], $line[1] ) );
				$item->set_amount( $line[1] * $line[2] );
				$item->set_total( $line[1] * $line[2] );
				$item->set_tax_status( 'none' );
				$order->add_item( $item );
			}
			$address = array(
				'first_name' => 'Jamie',
				'last_name'  => 'Demo',
				'company'    => 'Demo Customer Ltd',
				'address_1'  => '1 Example Street',
				'city'       => 'Springfield',
				'postcode'   => '12345',
				'country'    => strtoupper( substr( (string) get_option( 'woocommerce_default_country', 'US' ), 0, 2 ) ),
				'email'      => 'demo.customer@example.com',
				'phone'      => '+12025550142',
			);
			$order->set_address( $address, 'billing' );
			$order->set_payment_method_title( __( 'Demo (no payment taken)', 'orderbay-lite' ) );
			$order->update_meta_data( self::META, 1 );
			// Separate DEMO- numbers so the store's real invoice sequence is untouched.
			$order->update_meta_data( OBLT_Plugin::META_INVOICE_NUMBER, 'DEMO-INV-0001' );
			$order->calculate_totals( false );
			$order->add_order_note( __( 'OrderBay Lite demo order. Uses DEMO- numbers; your real invoice counter is unchanged. Remove it from WooCommerce > OrderBay Lite help.', 'orderbay-lite' ) );
			$order->set_status( 'completed' );
			$order->save();

			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => 24.00,
					'reason'         => __( 'Demo: one apron returned', 'orderbay-lite' ),
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
			$order = wc_get_order( $order->get_id() );
			if ( ! is_wp_error( $refund ) && $order ) {
				$order->update_meta_data( OBLT_Plugin::META_CREDIT_NUMBER, 'DEMO-CN-0001' );
				$order->save();
			}

			update_option( self::OPTION, array( 'orders' => array( $order->get_id() ) ), false );
			return $order->get_id();
		} finally {
			self::mute_emails( false );
		}
	}

	/**
	 * Turn every WooCommerce email (and wp_mail) off or back on around demo writes,
	 * so demo orders never email the store owner or the fictional customer, even
	 * when an SMTP plugin replaces wp_mail().
	 *
	 * @param bool $on True to mute, false to restore.
	 */
	private static function mute_emails( $on ) {
		// Refund emails switch their id to customer_partially_refunded_order at send time.
		$ids = array( 'new_order', 'customer_completed_order', 'customer_processing_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_invoice', 'customer_note' );
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'mailer' ) ) {
			foreach ( (array) WC()->mailer()->get_emails() as $email ) {
				if ( is_object( $email ) && ! empty( $email->id ) ) {
					$ids[] = $email->id;
				}
			}
		}
		foreach ( array_unique( $ids ) as $id ) {
			if ( $on ) {
				add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 999 );
			} else {
				remove_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 999 );
			}
		}
		if ( $on ) {
			add_filter( 'pre_wp_mail', '__return_false', 999 );
		} else {
			remove_filter( 'pre_wp_mail', '__return_false', 999 );
		}
	}

	/**
	 * Delete demo data (refunds are deleted with the parent order).
	 */
	public static function remove() {
		$ids = self::ids();
		foreach ( $ids['orders'] as $id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
			if ( $order && $order->get_meta( self::META ) ) {
				foreach ( $order->get_refunds() as $refund ) {
					$refund->delete( true );
				}
				$order->delete( true );
			}
		}
		delete_option( self::OPTION );
	}

	/**
	 * Links shown when demo data exists.
	 *
	 * @return array[]
	 */
	public static function links() {
		$out = array();
		$ids = self::ids();
		foreach ( $ids['orders'] as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$out[] = array(
				/* translators: %s: order number. */
				'label' => sprintf( __( 'Open demo order #%s (Invoice, UBL, CII buttons)', 'orderbay-lite' ), $order->get_order_number() ),
				'url'   => $order->get_edit_order_url(),
			);
			$out[] = array(
				'label'   => __( 'Print the demo invoice (DEMO-INV-0001)', 'orderbay-lite' ),
				'url'     => OBLT_Documents::instance()->print_url( $id ),
				'new_tab' => true,
			);
			$out[] = array(
				'label'   => __( 'Print the demo credit note (DEMO-CN-0001)', 'orderbay-lite' ),
				'url'     => wp_nonce_url( admin_url( 'admin-post.php?action=oblt_print_credit_note&order_id=' . $id ), 'oblt_print_credit_note_' . $id ),
				'new_tab' => true,
			);
			if ( class_exists( 'OBLT_EInvoice' ) ) {
				$out[] = array(
					'label' => __( 'Download demo UBL 2.1 XML', 'orderbay-lite' ),
					'url'   => OBLT_EInvoice::download_url( $id, 'ubl' ),
				);
				$out[] = array(
					'label' => __( 'Download demo CII (Factur-X) XML', 'orderbay-lite' ),
					'url'   => OBLT_EInvoice::download_url( $id, 'cii' ),
				);
			}
		}
		$out[] = array(
			'label' => __( 'Invoice settings (business details, number format)', 'orderbay-lite' ),
			'url'   => admin_url( 'admin.php?page=orderbay-lite' ),
		);
		return $out;
	}

	/**
	 * Extra note under the links.
	 */
	public static function render_extra() {
		echo '<p class="description">' . esc_html__( 'The invoice uses your business details from the settings page. Fill those in first to see a complete invoice and a compliant e-invoice.', 'orderbay-lite' ) . '</p>';
	}

	/**
	 * Diagnostics for the support report.
	 *
	 * @return array
	 */
	public static function report_lines() {
		$s      = OBLT_Plugin::get_doc_settings();
		$issues = class_exists( 'OBLT_EInvoice' ) ? OBLT_EInvoice::seller_issues( OBLT_EInvoice::seller_data() ) : array();
		return array(
			'Next invoice number'      => (int) get_option( OBLT_Plugin::OPT_INVOICE_NEXT, 1 ),
			'Invoice number format'    => (string) get_option( OBLT_Plugin::OPT_INVOICE_FORMAT, '{PREFIX}{SEQ}' ),
			'Invoice counter reset'    => (string) get_option( OBLT_Plugin::OPT_INVOICE_RESET, 'none' ),
			'Next credit note number'  => (int) get_option( OBLT_Plugin::OPT_CREDIT_NEXT, 1 ),
			'Paper'                    => (string) ( $s['paper'] ?? 'letter' ),
			'PDF engine setting'       => (string) ( $s['pdf_engine'] ?? 'browser' ),
			'Host PDF engine detected' => class_exists( 'OBLT_Documents' ) && OBLT_Documents::detect_pdf_engine() ? OBLT_Documents::detect_pdf_engine() : 'none',
			'E-invoice seller issues'  => count( $issues ),
			'Paid OrderBay active'     => function_exists( 'oblt_pro_is_active' ) ? oblt_pro_is_active() : false,
			'Demo data present'        => self::exists(),
		);
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-invoicing: standards-compliant XML export for an order.
 *
 * Produces UBL 2.1 (Peppol BIS Billing 3.0) and UN/CEFACT CII (Factur-X EN16931 profile)
 * invoice XML. The CII output is the XML a later Factur-X PDF/A-3 embeds.
 *
 * Scope note: this is an EN16931 *baseline* covering the mandatory core (parties, lines,
 * per-rate tax subtotals, monetary totals). It is NOT yet validated against the official
 * EN16931 Schematron rules, and the Factur-X PDF/A-3 assembly + Peppol transmission are
 * separate follow-ups. Validate a real order's output through an EN16931/Peppol validator
 * before relying on it in production.
 *
 * The builders take a normalized data array (not a WC_Order) so they are pure and unit
 * testable without WordPress; order_to_invoice_data() is the thin WooCommerce-facing map.
 */
class OBLT_EInvoice {

	const FORMATS = array( 'ubl', 'cii' );

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_oblt_einvoice', array( $this, 'handle_download' ) );
		add_action( 'admin_post_oblt_facturx', array( $this, 'handle_facturx' ) );
	}

	/**
	 * Whether a Factur-X PDF can be assembled on this host: a Factur-X/ZUGFeRD library
	 * (horstoeko/zugferd) AND a base-PDF engine (Dompdf/TCPDF) must both be present.
	 *
	 * @return bool
	 */
	public static function facturx_available() {
		return class_exists( '\\horstoeko\\zugferd\\ZugferdDocumentPdfBuilder' )
			&& class_exists( 'OBLT_Documents' )
			&& (bool) OBLT_Documents::detect_pdf_engine();
	}

	/**
	 * Nonce-protected Factur-X download URL.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function facturx_url( $order_id ) {
		$order_id = absint( $order_id );
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=oblt_facturx&order_id=' . $order_id ),
			'oblt_facturx_' . $order_id
		);
	}

	/**
	 * Assemble and stream a Factur-X PDF (PDF/A-3 with the embedded CII XML) using an
	 * optional host library. Only reachable when facturx_available().
	 *
	 * NOTE: the integration is written to the documented horstoeko/zugferd API but cannot
	 * be executed in this environment (no library). The produced PDF must pass a Factur-X /
	 * ZUGFeRD validator before production use.
	 */
	public function handle_facturx() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'oblt_facturx_' . $order_id );

		if ( ! self::facturx_available() ) {
			wp_die( esc_html__( 'Factur-X needs a PDF engine (Dompdf/TCPDF) and the horstoeko/zugferd library on this host.', 'orderbay-lite' ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay-lite' ) );
		}
		if ( class_exists( 'OBLT_Invoicing' ) ) {
			$number = OBLT_Invoicing::ensure_invoice_number( $order );
			if ( '' === (string) $number ) {
				wp_die( esc_html__( 'An invoice number could not be allocated. The document was not exported.', 'orderbay-lite' ) );
			}
		}

		$pdf_bytes = OBLT_Documents::render_pdf_bytes( $order );
		if ( '' === $pdf_bytes ) {
			wp_die( esc_html__( 'Could not render the base invoice PDF.', 'orderbay-lite' ) );
		}
		$xml = self::build_cii( self::order_to_invoice_data( $order ) );

		try {
			$builder = new \horstoeko\zugferd\ZugferdDocumentPdfBuilder( $xml, $pdf_bytes );
			$builder->generateDocument();
			$name = 'facturx-' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) ) . '.pdf';
			if ( method_exists( $builder, 'downloadString' ) ) {
				$facturx = (string) $builder->downloadString( $name );
			} else {
				$tmp = wp_tempnam( 'facturx' );
				$builder->saveDocument( $tmp );
				$facturx = (string) file_get_contents( $tmp );
				wp_delete_file( $tmp );
			}
		} catch ( \Throwable $e ) {
			wp_die( esc_html__( 'Factur-X assembly failed: ', 'orderbay-lite' ) . esc_html( $e->getMessage() ) );
		}

		if ( '' === $facturx ) {
			wp_die( esc_html__( 'Factur-X assembly produced no output.', 'orderbay-lite' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename=' . $name );
		echo $facturx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * Nonce-protected download URL for a given order + format.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $format   ubl|cii.
	 * @return string
	 */
	public static function download_url( $order_id, $format ) {
		$order_id = absint( $order_id );
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=oblt_einvoice&order_id=' . $order_id . '&format=' . rawurlencode( $format ) ),
			'oblt_einvoice_' . $order_id
		);
	}

	/**
	 * Stream the e-invoice XML for an order as a file download.
	 */
	public function handle_download() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'oblt_einvoice_' . $order_id );

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'ubl'; // phpcs:ignore
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			$format = 'ubl';
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay-lite' ) );
		}
		if ( class_exists( 'OBLT_Invoicing' ) ) {
			$number = OBLT_Invoicing::ensure_invoice_number( $order );
			if ( '' === (string) $number ) {
				wp_die( esc_html__( 'An invoice number could not be allocated. The document was not exported.', 'orderbay-lite' ) );
			}
		}

		$data = self::order_to_invoice_data( $order );
		$xml  = ( 'cii' === $format ) ? self::build_cii( $data ) : self::build_ubl( $data );

		$num  = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $data['invoice_number'] );
		$num  = '' !== $num ? $num : (string) $order_id;
		$name = 'einvoice-' . $format . '-' . $num . '.xml';

		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name );
		// XML from DOMDocument::saveXML — already well-formed and value-escaped.
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Seller (supplier) party derived from OrderBay Lite document settings + WooCommerce store
	 * settings. Shared by the order mapper and the settings readiness checklist.
	 *
	 * @return array
	 */
	public static function seller_data() {
		$settings = class_exists( 'OBLT_Plugin' ) ? OBLT_Plugin::get_doc_settings() : array();

		$country = strtoupper( substr( (string) get_option( 'woocommerce_default_country', '' ), 0, 2 ) );
		if ( ! empty( $settings['seller_country'] ) ) {
			$country = strtoupper( substr( (string) $settings['seller_country'], 0, 2 ) );
		}

		$from_lines = isset( $settings['from_lines'] ) ? (string) $settings['from_lines'] : '';
		$from_parts = array_values( array_filter( array_map( 'trim', explode( "\n", $from_lines ) ) ) );
		$name       = $from_parts ? $from_parts[0] : get_bloginfo( 'name' );

		return array(
			'name'     => $name,
			'street'   => (string) get_option( 'woocommerce_store_address', '' ),
			'street2'  => (string) get_option( 'woocommerce_store_address_2', '' ),
			'city'     => (string) get_option( 'woocommerce_store_city', '' ),
			'postcode' => (string) get_option( 'woocommerce_store_postcode', '' ),
			'country'  => $country,
			'vat'      => isset( $settings['tax_id'] ) ? (string) $settings['tax_id'] : '',
			'email'    => (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
		);
	}

	/**
	 * Map a WooCommerce order to the normalized invoice data array.
	 *
	 * NOTE: this is the WordPress/WooCommerce-facing layer — verify on a live WP+Woo stack
	 * (tax rounding, discounts, multi-rate carts) before production use.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order_to_invoice_data( $order ) {
		$seller = self::seller_data();

		$lines = array();
		$idx   = 0;

		foreach ( $order->get_items() as $item ) {
			++$idx;
			$lines[] = self::line_from( (string) $idx, $item->get_name(), (float) $item->get_quantity(), (float) $item->get_total(), (float) $item->get_total_tax() );
		}
		foreach ( $order->get_fees() as $fee ) {
			++$idx;
			$lines[] = self::line_from( (string) $idx, $fee->get_name(), 1.0, (float) $fee->get_total(), (float) $fee->get_total_tax() );
		}
		$ship_net = (float) $order->get_shipping_total();
		if ( $ship_net > 0 ) {
			++$idx;
			$lines[] = self::line_from( (string) $idx, __( 'Shipping', 'orderbay-lite' ), 1.0, $ship_net, (float) $order->get_shipping_tax() );
		}

		$tax_subtotals  = self::group_tax( $lines );
		$line_extension = 0.0;
		foreach ( $lines as $ln ) {
			$line_extension += $ln['line_net'];
		}
		$line_extension = round( $line_extension, 2 );
		$tax_total      = 0.0;
		foreach ( $tax_subtotals as $g ) {
			$tax_total += $g['tax'];
		}
		$tax_total     = round( $tax_total, 2 );
		$tax_inclusive = round( $line_extension + $tax_total, 2 );
		$payable       = round( (float) $order->get_total(), 2 );
		$prepaid       = round( $tax_inclusive - $payable, 2 );
		$prepaid       = $prepaid > 0 ? $prepaid : 0.0;

		$buyer_name = trim( (string) $order->get_formatted_billing_full_name() );
		if ( '' === $buyer_name ) {
			$buyer_name = (string) $order->get_billing_company();
		}
		$buyer = array(
			'name'     => $buyer_name,
			'street'   => (string) $order->get_billing_address_1(),
			'street2'  => (string) $order->get_billing_address_2(),
			'city'     => (string) $order->get_billing_city(),
			'postcode' => (string) $order->get_billing_postcode(),
			'country'  => strtoupper( (string) $order->get_billing_country() ),
			'vat'      => (string) $order->get_meta( '_billing_vat' ),
			'email'    => (string) $order->get_billing_email(),
		);

		$date = $order->get_date_created();

		return array(
			'invoice_number' => (string) ( $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) ? $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) : $order->get_order_number() ),
			'issue_date'     => $date ? $date->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'due_date'       => '',
			'currency'       => (string) $order->get_currency(),
			'type_code'      => '380',
			'note'           => (string) $order->get_customer_note(),
			'seller'         => $seller,
			'buyer'          => $buyer,
			'lines'          => $lines,
			'tax_subtotals'  => $tax_subtotals,
			'totals'         => array(
				'line_extension' => $line_extension,
				'tax_exclusive'  => $line_extension,
				'tax_total'      => $tax_total,
				'tax_inclusive'  => $tax_inclusive,
				'payable'        => $payable,
				'prepaid'        => $prepaid,
			),
		);
	}

	/**
	 * @param string $id  Line id.
	 * @param string $name Item name.
	 * @param float  $qty Quantity.
	 * @param float  $net Net line amount (ex tax).
	 * @param float  $tax Line tax amount.
	 * @return array
	 */
	private static function line_from( $id, $name, $qty, $net, $tax ) {
		$percent = ( abs( $net ) > 0.00001 && abs( $tax ) > 0.00001 ) ? round( $tax / $net * 100, 2 ) : 0.0;
		$unit    = $qty > 0 ? round( $net / $qty, 2 ) : round( $net, 2 );
		return array(
			'id'           => $id,
			'name'         => (string) $name,
			'qty'          => (float) $qty,
			'unit'         => 'C62',
			'unit_price'   => $unit,
			'line_net'     => round( $net, 2 ),
			'tax_percent'  => $percent,
			'tax_category' => $percent > 0 ? 'S' : 'Z',
		);
	}

	/**
	 * Group lines into per-category, per-rate tax subtotals.
	 *
	 * @param array $lines Lines.
	 * @return array
	 */
	private static function group_tax( array $lines ) {
		$groups = array();
		foreach ( $lines as $ln ) {
			$key = $ln['tax_category'] . '|' . $ln['tax_percent'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'category' => $ln['tax_category'],
					'percent'  => $ln['tax_percent'],
					'taxable'  => 0.0,
					'tax'      => 0.0,
				);
			}
			$groups[ $key ]['taxable'] += $ln['line_net'];
		}
		foreach ( $groups as &$g ) {
			$g['taxable'] = round( $g['taxable'], 2 );
			$g['tax']     = round( $g['taxable'] * $g['percent'] / 100, 2 );
		}
		unset( $g );
		return array_values( $groups );
	}

	/**
	 * Seller-side required-field checks (reused by the settings readiness checklist).
	 *
	 * @param array $seller Seller party data.
	 * @return string[] Human-readable issues (empty when ready).
	 */
	public static function seller_issues( array $seller ) {
		$issues = array();
		if ( empty( $seller['name'] ) ) {
			$issues[] = __( 'Seller name is missing (set From name/address in Documents settings).', 'orderbay-lite' );
		}
		if ( empty( $seller['street'] ) || empty( $seller['city'] ) || empty( $seller['postcode'] ) ) {
			$issues[] = __( 'Seller postal address is incomplete (set the WooCommerce store address).', 'orderbay-lite' );
		}
		if ( empty( $seller['country'] ) || 2 !== strlen( (string) $seller['country'] ) ) {
			$issues[] = __( 'Seller country code is missing or invalid.', 'orderbay-lite' );
		}
		if ( empty( $seller['vat'] ) ) {
			$issues[] = __( 'Seller VAT / Tax ID is missing (set Company VAT / Tax ID in Documents settings).', 'orderbay-lite' );
		}
		return $issues;
	}

	/**
	 * @param array $d Normalized invoice data.
	 * @return string[]
	 */
	public static function compliance_issues( array $d ) {
		$seller = isset( $d['seller'] ) && is_array( $d['seller'] ) ? $d['seller'] : array();
		$buyer  = isset( $d['buyer'] ) && is_array( $d['buyer'] ) ? $d['buyer'] : array();
		$issues = self::seller_issues( $seller );

		if ( empty( $d['currency'] ) ) {
			$issues[] = __( 'Invoice currency is missing.', 'orderbay-lite' );
		}
		if ( empty( $buyer['country'] ) || 2 !== strlen( (string) $buyer['country'] ) ) {
			$issues[] = __( 'Buyer country code is missing or invalid.', 'orderbay-lite' );
		}
		if ( empty( $d['lines'] ) ) {
			$issues[] = __( 'Invoice has no lines.', 'orderbay-lite' );
		}

		// Totals must reconcile: line extension + tax total = tax inclusive.
		$t = isset( $d['totals'] ) && is_array( $d['totals'] ) ? $d['totals'] : array();
		if ( $t ) {
			$expected = round( (float) ( $t['line_extension'] ?? 0 ) + (float) ( $t['tax_total'] ?? 0 ), 2 );
			if ( abs( $expected - (float) ( $t['tax_inclusive'] ?? 0 ) ) > 0.01 ) {
				$issues[] = __( 'Invoice totals do not reconcile (line extension + tax ≠ tax-inclusive amount).', 'orderbay-lite' );
			}
		}
		return $issues;
	}

	/* ─── UBL 2.1 (Peppol BIS Billing 3.0) ─────────────────────────────── */

	/**
	 * @param array $d Normalized invoice data.
	 * @return string UBL 2.1 Invoice XML.
	 */
	public static function build_ubl( array $d ) {
		$doc               = new DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;

		$ns_inv = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
		$ns_cac = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
		$ns_cbc = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

		$root = $doc->createElementNS( $ns_inv, 'Invoice' );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cac', $ns_cac );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cbc', $ns_cbc );
		$doc->appendChild( $root );

		$cur = (string) $d['currency'];

		self::el( $doc, $root, 'cbc:CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0' );
		self::el( $doc, $root, 'cbc:ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0' );
		self::el( $doc, $root, 'cbc:ID', (string) $d['invoice_number'] );
		self::el( $doc, $root, 'cbc:IssueDate', (string) $d['issue_date'] );
		if ( ! empty( $d['due_date'] ) ) {
			self::el( $doc, $root, 'cbc:DueDate', (string) $d['due_date'] );
		}
		self::el( $doc, $root, 'cbc:InvoiceTypeCode', (string) $d['type_code'] );
		if ( ! empty( $d['note'] ) ) {
			self::el( $doc, $root, 'cbc:Note', (string) $d['note'] );
		}
		self::el( $doc, $root, 'cbc:DocumentCurrencyCode', $cur );

		// Parties.
		$supplier = self::el( $doc, $root, 'cac:AccountingSupplierParty' );
		self::ubl_party( $doc, $supplier, $d['seller'] );
		$customer = self::el( $doc, $root, 'cac:AccountingCustomerParty' );
		self::ubl_party( $doc, $customer, $d['buyer'] );

		// Tax total.
		$tax_total = self::el( $doc, $root, 'cac:TaxTotal' );
		self::el( $doc, $tax_total, 'cbc:TaxAmount', self::money( $d['totals']['tax_total'] ), array( 'currencyID' => $cur ) );
		foreach ( $d['tax_subtotals'] as $g ) {
			$sub = self::el( $doc, $tax_total, 'cac:TaxSubtotal' );
			self::el( $doc, $sub, 'cbc:TaxableAmount', self::money( $g['taxable'] ), array( 'currencyID' => $cur ) );
			self::el( $doc, $sub, 'cbc:TaxAmount', self::money( $g['tax'] ), array( 'currencyID' => $cur ) );
			$cat = self::el( $doc, $sub, 'cac:TaxCategory' );
			self::el( $doc, $cat, 'cbc:ID', (string) $g['category'] );
			self::el( $doc, $cat, 'cbc:Percent', self::money( $g['percent'] ) );
			$scheme = self::el( $doc, $cat, 'cac:TaxScheme' );
			self::el( $doc, $scheme, 'cbc:ID', 'VAT' );
		}

		// Monetary totals.
		$mon = self::el( $doc, $root, 'cac:LegalMonetaryTotal' );
		self::el( $doc, $mon, 'cbc:LineExtensionAmount', self::money( $d['totals']['line_extension'] ), array( 'currencyID' => $cur ) );
		self::el( $doc, $mon, 'cbc:TaxExclusiveAmount', self::money( $d['totals']['tax_exclusive'] ), array( 'currencyID' => $cur ) );
		self::el( $doc, $mon, 'cbc:TaxInclusiveAmount', self::money( $d['totals']['tax_inclusive'] ), array( 'currencyID' => $cur ) );
		if ( (float) $d['totals']['prepaid'] > 0 ) {
			self::el( $doc, $mon, 'cbc:PrepaidAmount', self::money( $d['totals']['prepaid'] ), array( 'currencyID' => $cur ) );
		}
		self::el( $doc, $mon, 'cbc:PayableAmount', self::money( $d['totals']['payable'] ), array( 'currencyID' => $cur ) );

		// Lines.
		foreach ( $d['lines'] as $ln ) {
			$line = self::el( $doc, $root, 'cac:InvoiceLine' );
			self::el( $doc, $line, 'cbc:ID', (string) $ln['id'] );
			self::el( $doc, $line, 'cbc:InvoicedQuantity', self::qty( $ln['qty'] ), array( 'unitCode' => $ln['unit'] ) );
			self::el( $doc, $line, 'cbc:LineExtensionAmount', self::money( $ln['line_net'] ), array( 'currencyID' => $cur ) );
			$item = self::el( $doc, $line, 'cac:Item' );
			self::el( $doc, $item, 'cbc:Name', (string) $ln['name'] );
			$ctc = self::el( $doc, $item, 'cac:ClassifiedTaxCategory' );
			self::el( $doc, $ctc, 'cbc:ID', (string) $ln['tax_category'] );
			self::el( $doc, $ctc, 'cbc:Percent', self::money( $ln['tax_percent'] ) );
			$cscheme = self::el( $doc, $ctc, 'cac:TaxScheme' );
			self::el( $doc, $cscheme, 'cbc:ID', 'VAT' );
			$price = self::el( $doc, $line, 'cac:Price' );
			self::el( $doc, $price, 'cbc:PriceAmount', self::money( $ln['unit_price'] ), array( 'currencyID' => $cur ) );
		}

		return (string) $doc->saveXML();
	}

	/**
	 * @param DOMDocument $doc    Document.
	 * @param DOMElement  $parent AccountingSupplierParty / AccountingCustomerParty.
	 * @param array       $p      Party data.
	 */
	private static function ubl_party( DOMDocument $doc, DOMElement $parent, array $p ) {
		$party = self::el( $doc, $parent, 'cac:Party' );
		$addr  = self::el( $doc, $party, 'cac:PostalAddress' );
		self::el( $doc, $addr, 'cbc:StreetName', (string) ( $p['street'] ?? '' ) );
		if ( ! empty( $p['street2'] ) ) {
			self::el( $doc, $addr, 'cbc:AdditionalStreetName', (string) $p['street2'] );
		}
		self::el( $doc, $addr, 'cbc:CityName', (string) ( $p['city'] ?? '' ) );
		self::el( $doc, $addr, 'cbc:PostalZone', (string) ( $p['postcode'] ?? '' ) );
		$country = self::el( $doc, $addr, 'cac:Country' );
		self::el( $doc, $country, 'cbc:IdentificationCode', (string) ( $p['country'] ?? '' ) );

		if ( ! empty( $p['vat'] ) ) {
			$ptax = self::el( $doc, $party, 'cac:PartyTaxScheme' );
			self::el( $doc, $ptax, 'cbc:CompanyID', (string) $p['vat'] );
			$scheme = self::el( $doc, $ptax, 'cac:TaxScheme' );
			self::el( $doc, $scheme, 'cbc:ID', 'VAT' );
		}
		$legal = self::el( $doc, $party, 'cac:PartyLegalEntity' );
		self::el( $doc, $legal, 'cbc:RegistrationName', (string) ( $p['name'] ?? '' ) );
	}

	/* ─── UN/CEFACT CII (Factur-X EN16931 profile) ─────────────────────── */

	/**
	 * @param array $d Normalized invoice data.
	 * @return string CII XML.
	 */
	public static function build_cii( array $d ) {
		$doc               = new DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;

		$rsm = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
		$ram = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
		$udt = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

		$root = $doc->createElementNS( $rsm, 'rsm:CrossIndustryInvoice' );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ram', $ram );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:udt', $udt );
		$doc->appendChild( $root );

		$cur = (string) $d['currency'];

		// Context: EN16931 guideline.
		$ctx  = self::el( $doc, $root, 'rsm:ExchangedDocumentContext' );
		$gcp  = self::el( $doc, $ctx, 'ram:GuidelineSpecifiedDocumentContextParameter' );
		self::el( $doc, $gcp, 'ram:ID', 'urn:cen.eu:en16931:2017' );

		// Header document.
		$hdr = self::el( $doc, $root, 'rsm:ExchangedDocument' );
		self::el( $doc, $hdr, 'ram:ID', (string) $d['invoice_number'] );
		self::el( $doc, $hdr, 'ram:TypeCode', (string) $d['type_code'] );
		$dt  = self::el( $doc, $hdr, 'ram:IssueDateTime' );
		self::el( $doc, $dt, 'udt:DateTimeString', str_replace( '-', '', (string) $d['issue_date'] ), array( 'format' => '102' ) );
		if ( ! empty( $d['note'] ) ) {
			$note = self::el( $doc, $hdr, 'ram:IncludedNote' );
			self::el( $doc, $note, 'ram:Content', (string) $d['note'] );
		}

		$tx = self::el( $doc, $root, 'rsm:SupplyChainTradeTransaction' );

		// Lines.
		foreach ( $d['lines'] as $ln ) {
			$li  = self::el( $doc, $tx, 'ram:IncludedSupplyChainTradeLineItem' );
			$adl = self::el( $doc, $li, 'ram:AssociatedDocumentLineDocument' );
			self::el( $doc, $adl, 'ram:LineID', (string) $ln['id'] );
			$prod = self::el( $doc, $li, 'ram:SpecifiedTradeProduct' );
			self::el( $doc, $prod, 'ram:Name', (string) $ln['name'] );
			$agr   = self::el( $doc, $li, 'ram:SpecifiedLineTradeAgreement' );
			$price = self::el( $doc, $agr, 'ram:NetPriceProductTradePrice' );
			self::el( $doc, $price, 'ram:ChargeAmount', self::money( $ln['unit_price'] ) );
			$del = self::el( $doc, $li, 'ram:SpecifiedLineTradeDelivery' );
			self::el( $doc, $del, 'ram:BilledQuantity', self::qty( $ln['qty'] ), array( 'unitCode' => $ln['unit'] ) );
			$set = self::el( $doc, $li, 'ram:SpecifiedLineTradeSettlement' );
			$ltx = self::el( $doc, $set, 'ram:ApplicableTradeTax' );
			self::el( $doc, $ltx, 'ram:TypeCode', 'VAT' );
			self::el( $doc, $ltx, 'ram:CategoryCode', (string) $ln['tax_category'] );
			self::el( $doc, $ltx, 'ram:RateApplicablePercent', self::money( $ln['tax_percent'] ) );
			$sum = self::el( $doc, $set, 'ram:SpecifiedTradeSettlementLineMonetarySummation' );
			self::el( $doc, $sum, 'ram:LineTotalAmount', self::money( $ln['line_net'] ) );
		}

		// Header trade agreement (parties).
		$hta = self::el( $doc, $tx, 'ram:ApplicableHeaderTradeAgreement' );
		self::cii_party( $doc, $hta, 'ram:SellerTradeParty', $d['seller'] );
		self::cii_party( $doc, $hta, 'ram:BuyerTradeParty', $d['buyer'] );

		// Delivery (required element, may be empty).
		self::el( $doc, $tx, 'ram:ApplicableHeaderTradeDelivery' );

		// Settlement.
		$hts = self::el( $doc, $tx, 'ram:ApplicableHeaderTradeSettlement' );
		self::el( $doc, $hts, 'ram:InvoiceCurrencyCode', $cur );
		foreach ( $d['tax_subtotals'] as $g ) {
			$t = self::el( $doc, $hts, 'ram:ApplicableTradeTax' );
			self::el( $doc, $t, 'ram:CalculatedAmount', self::money( $g['tax'] ) );
			self::el( $doc, $t, 'ram:TypeCode', 'VAT' );
			self::el( $doc, $t, 'ram:BasisAmount', self::money( $g['taxable'] ) );
			self::el( $doc, $t, 'ram:CategoryCode', (string) $g['category'] );
			self::el( $doc, $t, 'ram:RateApplicablePercent', self::money( $g['percent'] ) );
		}
		$mon = self::el( $doc, $hts, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation' );
		self::el( $doc, $mon, 'ram:LineTotalAmount', self::money( $d['totals']['line_extension'] ) );
		self::el( $doc, $mon, 'ram:TaxBasisTotalAmount', self::money( $d['totals']['tax_exclusive'] ) );
		self::el( $doc, $mon, 'ram:TaxTotalAmount', self::money( $d['totals']['tax_total'] ), array( 'currencyID' => $cur ) );
		self::el( $doc, $mon, 'ram:GrandTotalAmount', self::money( $d['totals']['tax_inclusive'] ) );
		if ( (float) $d['totals']['prepaid'] > 0 ) {
			self::el( $doc, $mon, 'ram:TotalPrepaidAmount', self::money( $d['totals']['prepaid'] ) );
		}
		self::el( $doc, $mon, 'ram:DuePayableAmount', self::money( $d['totals']['payable'] ) );

		return (string) $doc->saveXML();
	}

	/**
	 * @param DOMDocument $doc  Document.
	 * @param DOMElement  $parent Header trade agreement.
	 * @param string      $tag  ram:SellerTradeParty|ram:BuyerTradeParty.
	 * @param array       $p    Party data.
	 */
	private static function cii_party( DOMDocument $doc, DOMElement $parent, $tag, array $p ) {
		$party = self::el( $doc, $parent, $tag );
		self::el( $doc, $party, 'ram:Name', (string) ( $p['name'] ?? '' ) );
		$addr = self::el( $doc, $party, 'ram:PostalTradeAddress' );
		self::el( $doc, $addr, 'ram:PostcodeCode', (string) ( $p['postcode'] ?? '' ) );
		self::el( $doc, $addr, 'ram:LineOne', (string) ( $p['street'] ?? '' ) );
		if ( ! empty( $p['street2'] ) ) {
			self::el( $doc, $addr, 'ram:LineTwo', (string) $p['street2'] );
		}
		self::el( $doc, $addr, 'ram:CityName', (string) ( $p['city'] ?? '' ) );
		self::el( $doc, $addr, 'ram:CountryID', (string) ( $p['country'] ?? '' ) );
		if ( ! empty( $p['vat'] ) ) {
			$reg = self::el( $doc, $party, 'ram:SpecifiedTaxRegistration' );
			self::el( $doc, $reg, 'ram:ID', (string) $p['vat'], array( 'schemeID' => 'VA' ) );
		}
	}

	/* ─── helpers ──────────────────────────────────────────────────────── */

	/**
	 * Create a namespaced element with optional text + attributes and append it.
	 *
	 * @param DOMDocument $doc    Document.
	 * @param DOMNode     $parent Parent node.
	 * @param string      $name   Qualified name (prefix:local).
	 * @param string|null $value  Optional text content.
	 * @param array       $attrs  Optional attribute map.
	 * @return DOMElement
	 */
	private static function el( DOMDocument $doc, DOMNode $parent, $name, $value = null, array $attrs = array() ) {
		$node = $doc->createElement( $name );
		if ( null !== $value && '' !== $value ) {
			$node->appendChild( $doc->createTextNode( (string) $value ) );
		}
		foreach ( $attrs as $k => $v ) {
			$node->setAttribute( $k, (string) $v );
		}
		$parent->appendChild( $node );
		return $node;
	}

	/**
	 * @param mixed $n Amount.
	 * @return string 2-decimal fixed.
	 */
	private static function money( $n ) {
		return number_format( (float) $n, 2, '.', '' );
	}

	/**
	 * @param mixed $n Quantity.
	 * @return string Trimmed decimal.
	 */
	private static function qty( $n ) {
		$s = number_format( (float) $n, 4, '.', '' );
		$s = rtrim( rtrim( $s, '0' ), '.' );
		return '' === $s ? '0' : $s;
	}
}

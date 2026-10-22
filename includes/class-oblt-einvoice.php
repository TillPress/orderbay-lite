<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-invoicing: standards-compliant XML export for an order.
 *
 * Produces UBL 2.1 (Peppol BIS Billing 3.0), UN/CEFACT CII (Factur-X / ZUGFeRD EN 16931
 * profile) and XRechnung 3.0 invoice XML, plus the Factur-X / ZUGFeRD PDF (PDF/A-3b with
 * the CII embedded, built by OBLT_FacturX on the bundled Dompdf). Export only: OrderBay
 * Lite does not connect to the Peppol network or any PDP / access point.
 *
 * The sample invoices in tests/ pass the KoSIT validator (EN 16931 and XRechnung 3.0
 * schematron) and the Mustang validator (veraPDF PDF/A-3b + Factur-X rules).
 * Check a real order through a validator once before relying on it.
 *
 * The builders take a normalized data array (not a WC_Order) so they are pure and unit
 * testable without WordPress; order_to_invoice_data() is the thin WooCommerce-facing map.
 */
class OBLT_EInvoice {

	const FORMATS = array( 'ubl', 'cii', 'xrechnung' );

	/** XRechnung 3.0 specification identifier (BT-24). */
	const XRECHNUNG_ID = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

	/** Peppol BIS Billing 3.0 specification identifier (BT-24). */
	const PEPPOL_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

	/** Business process (BT-23) used by Peppol and XRechnung. */
	const BUSINESS_PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

	/** Factur-X PDF profiles: EN 16931 (Factur-X / ZUGFeRD) or XRechnung. */
	const PDF_PROFILES = array( 'en16931', 'xrechnung' );

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
		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'admin_billing_fields' ) );
		add_filter( 'woocommerce_billing_fields', array( $this, 'checkout_billing_fields' ), 20 );
		add_action( 'woocommerce_init', array( $this, 'register_block_checkout_fields' ) );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'store_block_checkout_field' ), 10, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 10, 2 );
	}

	/**
	 * Add VAT number and Peppol ID to the order screen's billing fields so e-invoices can
	 * carry the buyer's Peppol address. WooCommerce saves them as _billing_vat and
	 * _billing_peppol_id, _billing_buyer_reference. A field another plugin already added is left alone.
	 *
	 * @param array $fields Billing fields.
	 * @return array
	 */
	public function admin_billing_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		if ( ! isset( $fields['vat'] ) ) {
			$fields['vat'] = array(
				'label' => __( 'VAT number', 'orderbay-lite' ),
				'show'  => false,
			);
		}
		if ( ! isset( $fields['peppol_id'] ) ) {
			$fields['peppol_id'] = array(
				'label'       => __( 'Peppol ID', 'orderbay-lite' ),
				'show'        => false,
				'placeholder' => '0208:0123456789',
			);
		}
		if ( ! isset( $fields['buyer_reference'] ) ) {
			$fields['buyer_reference'] = array(
				'label'       => __( 'Buyer reference / Leitweg-ID', 'orderbay-lite' ),
				'show'        => false,
				'placeholder' => '991-01234-56',
				'description' => __( 'Goes into the e-invoice as BT-10. German public buyers need their Leitweg-ID here; otherwise the order number is used.', 'orderbay-lite' ),
			);
		}
		return $fields;
	}

	/**
	 * Which optional checkout fields the store turned on (E-invoicing settings).
	 *
	 * @param array|null $s Document settings (null = load).
	 * @return array{vat:bool,ref:bool}
	 */
	public static function checkout_fields_on( $s = null ) {
		$s = is_array( $s ) ? $s : ( class_exists( 'OBLT_Plugin' ) ? OBLT_Plugin::get_doc_settings() : array() );
		return array(
			'vat' => '1' === (string) ( $s['checkout_vat'] ?? '0' ),
			'ref' => '1' === (string) ( $s['checkout_buyer_ref'] ?? '0' ),
		);
	}

	/**
	 * Classic checkout (and My Account address form): optional VAT ID and buyer reference.
	 * WooCommerce saves them on the order as _billing_vat and _billing_buyer_reference,
	 * the keys the e-invoice reads. A field another plugin already added is left alone.
	 *
	 * @param array $fields Billing fields.
	 * @return array
	 */
	public function checkout_billing_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		$on = self::checkout_fields_on();
		if ( $on['vat'] && ! isset( $fields['billing_vat'] ) ) {
			$fields['billing_vat'] = array(
				'label'       => __( 'VAT ID', 'orderbay-lite' ),
				'placeholder' => 'DE123456789',
				'required'    => false,
				'class'       => array( 'form-row-wide' ),
				'priority'    => 31,
			);
		}
		if ( $on['ref'] && ! isset( $fields['billing_buyer_reference'] ) ) {
			$fields['billing_buyer_reference'] = array(
				'label'       => __( 'Buyer reference / Leitweg-ID', 'orderbay-lite' ),
				'description' => __( 'For business and public-sector invoices, if your accounts team gave you one.', 'orderbay-lite' ),
				'required'    => false,
				'class'       => array( 'form-row-wide' ),
				'priority'    => 32,
			);
		}
		return $fields;
	}

	/**
	 * Block checkout: the same two optional fields, in the order section.
	 */
	public function register_block_checkout_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$on = self::checkout_fields_on();
		try {
			if ( $on['vat'] ) {
				woocommerce_register_additional_checkout_field(
					array(
						'id'                => 'orderbay-lite/vat-id',
						'label'             => __( 'VAT ID', 'orderbay-lite' ),
						'location'          => 'order',
						'type'              => 'text',
						'required'          => false,
						'validate_callback' => array( __CLASS__, 'validate_vat_field' ),
					)
				);
			}
			if ( $on['ref'] ) {
				woocommerce_register_additional_checkout_field(
					array(
						'id'       => 'orderbay-lite/buyer-reference',
						'label'    => __( 'Buyer reference / Leitweg-ID', 'orderbay-lite' ),
						'location' => 'order',
						'type'     => 'text',
						'required' => false,
					)
				);
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Already registered or blocks unavailable: the classic fields still work.
		}
	}

	/**
	 * Copy a block-checkout field onto the order meta the e-invoice reads.
	 *
	 * @param string $key       Field id.
	 * @param mixed  $value     Value.
	 * @param string $group     Group.
	 * @param mixed  $wc_object Order or customer.
	 */
	public function store_block_checkout_field( $key, $value, $group, $wc_object ) {
		$map = array(
			'orderbay-lite/vat-id'          => '_billing_vat',
			'orderbay-lite/buyer-reference' => '_billing_buyer_reference',
		);
		if ( ! isset( $map[ $key ] ) || ! $wc_object instanceof WC_Order ) {
			return;
		}
		$clean = trim( wp_specialchars_decode( sanitize_text_field( (string) $value ), ENT_QUOTES ) );
		if ( '_billing_vat' === $map[ $key ] ) {
			$clean = self::normalize_vat( $clean );
		}
		$wc_object->update_meta_data( $map[ $key ], $clean );
	}

	/**
	 * VAT ID as stored and exported: tags, spaces, dots and dashes removed, upper case. Pure.
	 *
	 * @param string $vat Raw input.
	 * @return string
	 */
	public static function normalize_vat( $vat ) {
		$vat = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $vat ) : (string) preg_replace( '/<[^>]*>/', '', (string) $vat );
		return strtoupper( (string) preg_replace( '/[\s.\-]+/', '', html_entity_decode( $vat, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Whether a VAT ID looks valid: an EU (or GB, XI, CH, NO) country prefix, then 2-13
	 * letters or digits with at least one digit. Format only, no VIES lookup. Pure.
	 *
	 * @param string $vat VAT ID.
	 * @return bool
	 */
	public static function is_valid_vat( $vat ) {
		$vat = self::normalize_vat( $vat );
		if ( ! preg_match( '/^([A-Z]{2})([0-9A-Z]{2,13})$/', $vat, $m ) || ! preg_match( '/\d/', $m[2] ) ) {
			return false;
		}
		return in_array( $m[1], array_merge( self::EU_COUNTRIES, array( 'EL', 'XI', 'GB', 'CH', 'NO' ) ), true );
	}

	/**
	 * Block checkout validation for the VAT ID field. Empty is fine (the field is optional).
	 *
	 * @param mixed $value Field value.
	 * @return true|WP_Error
	 */
	public static function validate_vat_field( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || self::is_valid_vat( $value ) ) {
			return true;
		}
		return new WP_Error( 'invalid_vat_id', self::invalid_vat_message() );
	}

	/**
	 * Classic checkout validation for the VAT ID field.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors.
	 */
	public function validate_classic_checkout( $data, $errors ) {
		if ( ! is_array( $data ) || ! $errors instanceof WP_Error || ! self::checkout_fields_on()['vat'] ) {
			return;
		}
		$vat = trim( (string) ( $data['billing_vat'] ?? '' ) );
		if ( '' !== $vat && ! self::is_valid_vat( $vat ) ) {
			$errors->add( 'invalid_vat_id', self::invalid_vat_message() );
		}
	}

	/**
	 * @return string
	 */
	private static function invalid_vat_message() {
		return __( 'Please enter a valid VAT ID (for example DE123456789), or leave the field empty.', 'orderbay-lite' );
	}

	/**
	 * Buyer VAT ID from the order: OrderBay's key first, then keys common EU VAT plugins use.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function buyer_vat( $order ) {
		foreach ( array( '_billing_vat', '_billing_vat_number', '_vat_number', 'vat_number', '_billing_eu_vat_number' ) as $k ) {
			$v = trim( (string) $order->get_meta( $k ) );
			if ( '' !== $v ) {
				return self::normalize_vat( $v );
			}
		}
		return '';
	}

	/**
	 * Whether a Factur-X / ZUGFeRD PDF can be made here: the Dompdf in use
	 * (bundled 3.x, or a host copy that is recent enough) can write PDF/A-3 with attachments.
	 *
	 * @return bool
	 */
	public static function facturx_available() {
		return class_exists( 'OBLT_FacturX' )
			&& OBLT_FacturX::engine_ready();
	}

	/**
	 * The PDF profile picked in Documents settings: 'en16931' (Factur-X / ZUGFeRD) or
	 * 'xrechnung' (ZUGFeRD with XRechnung XML).
	 *
	 * @param array|null $settings Document settings (null = read them).
	 * @return string
	 */
	public static function pdf_profile( $settings = null ) {
		if ( null === $settings ) {
			$settings = class_exists( 'OBLT_Plugin' ) ? OBLT_Plugin::get_doc_settings() : array();
		}
		$p = isset( $settings['einv_pdf_profile'] ) ? (string) $settings['einv_pdf_profile'] : 'en16931';
		return in_array( $p, self::PDF_PROFILES, true ) ? $p : 'en16931';
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
	 * Factur-X / ZUGFeRD PDF bytes for an order: the invoice PDF as PDF/A-3b with the CII
	 * XML embedded. The caller assigns the invoice number first.
	 *
	 * @param WC_Order    $order   Order.
	 * @param string|null $profile 'en16931' | 'xrechnung' (null = settings).
	 * @return string PDF bytes.
	 * @throws RuntimeException When the PDF cannot be made.
	 */
	public static function facturx_bytes( $order, $profile = null ) {
		$profile = in_array( $profile, self::PDF_PROFILES, true ) ? $profile : self::pdf_profile();
		if ( ! class_exists( 'OBLT_FacturX' ) || ! OBLT_FacturX::engine_ready() ) {
			throw new RuntimeException( 'The PDF engine on this site cannot write PDF/A-3 files.' );
		}
		$xml   = self::build_cii( self::order_to_invoice_data( $order ), $profile );
		return OBLT_Pdf::render(
			$order,
			'invoice',
			array(
				'xml'     => $xml,
				'profile' => $profile,
			)
		);
	}

	/**
	 * Stream the Factur-X / ZUGFeRD PDF for an order.
	 */
	public function handle_facturx() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'oblt_facturx_' . $order_id );

		if ( ! self::facturx_available() ) {
			wp_die( esc_html__( 'Factur-X needs the bundled PDF engine (Dompdf 3). Another plugin loaded an older Dompdf first.', 'orderbay-lite' ) );
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
		$profile = self::pdf_profile();
		try {
			$facturx = self::facturx_bytes( $order, $profile );
		} catch ( \Throwable $e ) {
			wp_die( esc_html__( 'Factur-X assembly failed: ', 'orderbay-lite' ) . esc_html( $e->getMessage() ) );
		}
		$name = self::facturx_filename( $profile, (string) $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $facturx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * Download / attachment name for a Factur-X PDF. Pure.
	 *
	 * @param string $profile 'en16931' | 'xrechnung'.
	 * @param string $number  Invoice number.
	 * @return string
	 */
	public static function facturx_filename( $profile, $number ) {
		$n = trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $number ), '-.' );
		return ( 'xrechnung' === $profile ? 'zugferd-xrechnung-' : 'facturx-' ) . ( '' !== $n ? $n : 'invoice' ) . '.pdf';
	}

	/**
	 * Nonce-protected download URL for a given order + format.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $format   ubl|cii|xrechnung.
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
		if ( 'cii' === $format ) {
			$xml = self::build_cii( $data );
		} elseif ( 'xrechnung' === $format ) {
			$xml = self::build_ubl( $data, 'xrechnung' );
		} else {
			$xml = self::build_ubl( $data );
		}

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
	 * Sanitize the e-invoicing settings from the Documents form into $out.
	 *
	 * @param array $input Raw input.
	 * @param array $out   Settings being built.
	 * @return array
	 */
	public static function sanitize_settings( $input, $out ) {
		$input = is_array( $input ) ? $input : array();
		foreach ( array( 'einv_contact', 'einv_phone', 'einv_account_name' ) as $k ) {
			$out[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( wp_unslash( $input[ $k ] ) ) : '';
		}
		$out['einv_iban'] = isset( $input['einv_iban'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['einv_iban'] ) ) : '';
		$out['einv_bic']  = isset( $input['einv_bic'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['einv_bic'] ) ) : '';
		$days             = isset( $input['einv_due_days'] ) ? trim( (string) $input['einv_due_days'] ) : '14';
		$out['einv_due_days'] = '' === $days ? '14' : (string) min( 365, max( 0, (int) $days ) );
		$profile                 = isset( $input['einv_pdf_profile'] ) ? sanitize_key( $input['einv_pdf_profile'] ) : 'en16931';
		$out['einv_pdf_profile'] = in_array( $profile, self::PDF_PROFILES, true ) ? $profile : 'en16931';
		$out['einv_email_facturx'] = ! empty( $input['einv_email_facturx'] ) ? '1' : '0';
		$out['checkout_vat']       = ! empty( $input['checkout_vat'] ) ? '1' : '0';
		$out['checkout_buyer_ref'] = ! empty( $input['checkout_buyer_ref'] ) ? '1' : '0';
		$out['einv_eu_b2b']        = ( isset( $input['einv_eu_b2b'] ) && 'services' === $input['einv_eu_b2b'] ) ? 'services' : 'goods';
		return $out;
	}

	/**
	 * IBAN check digits (ISO 13616 mod 97). Pure.
	 *
	 * @param string $iban IBAN, spaces allowed.
	 * @return bool
	 */
	public static function valid_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban ) ) {
			return false;
		}
		$moved  = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$digits = '';
		foreach ( str_split( $moved ) as $ch ) {
			$digits .= ctype_alpha( $ch ) ? (string) ( ord( $ch ) - 55 ) : $ch;
		}
		$rest = 0;
		foreach ( str_split( $digits, 7 ) as $chunk ) {
			$rest = (int) ( $rest . $chunk ) % 97;
		}
		return 1 === $rest;
	}

	/**
	 * E-invoicing rows for the Documents settings form.
	 *
	 * @param array $s Document settings.
	 */
	public static function render_settings_fields( array $s ) {
		$opt  = OBLT_Plugin::OPT_DOCS;
		$text = static function ( $key, $label, $help = '', $placeholder = '' ) use ( $s, $opt ) {
			echo '<tr><th><label for="oblt_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="text" class="regular-text" id="oblt_' . esc_attr( $key ) . '" name="' . esc_attr( $opt ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) ( $s[ $key ] ?? '' ) ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
			if ( '' !== $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		echo '<table class="form-table">';
		$seller = self::seller_data();
		$addr   = trim( implode( ', ', array_filter( array( $seller['street'], $seller['street2'], trim( $seller['postcode'] . ' ' . $seller['city'] ), $seller['country'] ) ) ) );
		echo '<tr><th>' . esc_html__( 'Seller address and email in e-invoices', 'orderbay-lite' ) . '</th><td>';
		echo '<p><strong>' . esc_html( '' !== $addr ? $addr : __( '(no store address set)', 'orderbay-lite' ) ) . '</strong><br />' . esc_html( (string) $seller['email'] ) . '</p>';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: 1: link to WooCommerce general settings, 2: link to WooCommerce email settings. */
				__( 'E-invoices (Factur-X, XRechnung, UBL, CII) need a structured address, so they take it from the %1$s and the email from the %2$s, not from the From name/address box above.', 'orderbay-lite' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'WooCommerce store address', 'orderbay-lite' ) . '</a>',
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce email "From" address', 'orderbay-lite' ) . '</a>'
			)
		) . '</p>';
		if ( self::address_mismatch( (string) ( $s['from_lines'] ?? '' ), $seller ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The From name/address above does not contain your WooCommerce store street or postcode, so your printed invoice and your e-invoice show different addresses. Make them match before sending e-invoices.', 'orderbay-lite' ) . '</p></div>';
		}
		echo '</td></tr>';
		$profile = self::pdf_profile( $s );
		echo '<tr><th><label for="oblt_einv_pdf_profile">' . esc_html__( 'Factur-X / ZUGFeRD PDF profile', 'orderbay-lite' ) . '</label></th><td>';
		echo '<select id="oblt_einv_pdf_profile" name="' . esc_attr( $opt ) . '[einv_pdf_profile]">';
		echo '<option value="en16931"' . selected( $profile, 'en16931', false ) . '>' . esc_html__( 'EN 16931 (Factur-X / ZUGFeRD, most buyers)', 'orderbay-lite' ) . '</option>';
		echo '<option value="xrechnung"' . selected( $profile, 'xrechnung', false ) . '>' . esc_html__( 'XRechnung (German public sector)', 'orderbay-lite' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Emailed invoices', 'orderbay-lite' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $opt ) . '[einv_email_facturx]" value="1"' . checked( '1', (string) ( $s['einv_email_facturx'] ?? '0' ), false ) . ' /> ';
		echo esc_html__( 'Attach the Factur-X / ZUGFeRD PDF instead of the plain invoice PDF', 'orderbay-lite' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Customers see the same invoice; their accounting software can read the data inside it.', 'orderbay-lite' ) . '</p></td></tr>';
		$eu_b2b = (string) ( $s['einv_eu_b2b'] ?? 'goods' );
		echo '<tr><th><label for="ob_einv_eu_b2b">' . esc_html__( 'Tax-free sales to EU businesses', 'orderbay-lite' ) . '</label></th><td>';
		echo '<select id="ob_einv_eu_b2b" name="' . esc_attr( $opt ) . '[einv_eu_b2b]">';
		echo '<option value="goods"' . selected( $eu_b2b, 'goods', false ) . '>' . esc_html__( 'Goods: intra-community supply', 'orderbay-lite' ) . '</option>';
		echo '<option value="services"' . selected( $eu_b2b, 'services', false ) . '>' . esc_html__( 'Services: reverse charge', 'orderbay-lite' ) . '</option>';
		echo '</select><p class="description">' . esc_html__( 'When WooCommerce charged 0% VAT to a business with a VAT ID in another EU country, invoices and e-invoices name the reason. Deliveries outside the EU are marked as exports. Your tax setup in WooCommerce still decides whether VAT is charged.', 'orderbay-lite' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Checkout fields', 'orderbay-lite' ) . '</th><td>';
		echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $opt ) . '[checkout_vat]" value="1"' . checked( '1', (string) ( $s['checkout_vat'] ?? '0' ), false ) . ' /> ' . esc_html__( 'Ask for the buyer\'s VAT ID (optional field)', 'orderbay-lite' ) . '</label>';
		echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $opt ) . '[checkout_buyer_ref]" value="1"' . checked( '1', (string) ( $s['checkout_buyer_ref'] ?? '0' ), false ) . ' /> ' . esc_html__( 'Ask for a buyer reference / Leitweg-ID (optional field)', 'orderbay-lite' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Works with the classic and the block checkout. The values go on the invoice and into the e-invoice; a business buyer\'s VAT ID from another EU country lets a 0% order be marked as an intra-EU supply.', 'orderbay-lite' ) . '</p></td></tr>';
		$text( 'einv_contact', __( 'Invoice contact name', 'orderbay-lite' ), __( 'Shown as the seller contact. Leave empty to use the company name.', 'orderbay-lite' ) );
		$text( 'einv_phone', __( 'Invoice contact phone', 'orderbay-lite' ), __( 'Required for XRechnung.', 'orderbay-lite' ), '+49 30 1234567' );
		$text( 'einv_iban', __( 'IBAN for bank transfer', 'orderbay-lite' ), __( 'Optional. When set, unpaid invoices ask for a SEPA transfer to this account.', 'orderbay-lite' ) );
		$text( 'einv_bic', __( 'BIC', 'orderbay-lite' ) );
		$text( 'einv_account_name', __( 'Account holder', 'orderbay-lite' ) );
		echo '<tr><th><label for="oblt_einv_due_days">' . esc_html__( 'Payment due after (days)', 'orderbay-lite' ) . '</label></th><td>';
		echo '<input type="number" min="0" max="365" class="small-text" id="oblt_einv_due_days" name="' . esc_attr( $opt ) . '[einv_due_days]" value="' . esc_attr( (string) ( $s['einv_due_days'] ?? '14' ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'For orders not paid yet. Paid orders show the payment and nothing due.', 'orderbay-lite' ) . '</p></td></tr>';
		echo '</table>';
	}

	/**
	 * Seller (supplier) party derived from OrderBay document settings + WooCommerce store
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
			'contact'  => isset( $settings['einv_contact'] ) ? (string) $settings['einv_contact'] : '',
			'phone'    => isset( $settings['einv_phone'] ) ? (string) $settings['einv_phone'] : '',
			'iban'     => isset( $settings['einv_iban'] ) ? (string) $settings['einv_iban'] : '',
			'endpoint' => self::peppol_endpoint(
				isset( $settings['peppol_id'] ) ? (string) $settings['peppol_id'] : '',
				isset( $settings['tax_id'] ) ? (string) $settings['tax_id'] : '',
				$country
			),
		);
	}

	/**
	 * Peppol EAS scheme for a VAT number, keyed by its country prefix (Greece uses EL).
	 *
	 * @var array<string,string>
	 */
	private static $vat_eas = array(
		'AD' => '9922',
		'AL' => '9923',
		'AT' => '9914',
		'BA' => '9924',
		'BG' => '9926',
		'CH' => '9927',
		'CY' => '9928',
		'CZ' => '9929',
		'DE' => '9930',
		'EE' => '9931',
		'EL' => '9933',
		'ES' => '9920',
		'FR' => '9957',
		'GB' => '9932',
		'GR' => '9933',
		'HR' => '9934',
		'HU' => '9910',
		'IE' => '9935',
		'IT' => '0211',
		'LI' => '9936',
		'LT' => '9937',
		'LU' => '9938',
		'LV' => '9939',
		'MC' => '9940',
		'ME' => '9941',
		'MK' => '9942',
		'MT' => '9943',
		'NL' => '9944',
		'PL' => '9945',
		'PT' => '9946',
		'RO' => '9947',
		'RS' => '9948',
		'SI' => '9949',
		'SK' => '9950',
		'SM' => '9951',
		'TR' => '9952',
		'VA' => '9953',
	);

	/**
	 * A party's Peppol address: the one already worked out, else derived from its VAT number.
	 *
	 * @param array $p Party data.
	 * @return array{scheme:string,id:string}|array{}
	 */
	public static function party_endpoint( array $p ) {
		if ( isset( $p['endpoint'] ) && is_array( $p['endpoint'] ) ) {
			return ( ! empty( $p['endpoint']['scheme'] ) && ! empty( $p['endpoint']['id'] ) ) ? array(
				'scheme' => (string) $p['endpoint']['scheme'],
				'id'     => (string) $p['endpoint']['id'],
			) : array();
		}
		return self::peppol_endpoint( '', isset( $p['vat'] ) ? (string) $p['vat'] : '', isset( $p['country'] ) ? (string) $p['country'] : '' );
	}

	/**
	 * Peppol electronic address (BT-34 / BT-49) for a party.
	 *
	 * An explicit Peppol ID ("0208:0123456789", optionally prefixed with
	 * "iso6523-actorid-upis::") wins. Otherwise it is derived from the VAT number:
	 * Belgian VAT becomes the enterprise number under scheme 0208 (what Belgian
	 * receivers register), other European VAT numbers use their EAS VAT scheme.
	 *
	 * @param string $explicit Peppol ID entered by the merchant or customer.
	 * @param string $vat      VAT number.
	 * @param string $country  ISO country code, used when the VAT has no prefix.
	 * @return array{scheme:string,id:string}|array{} Empty when none can be found.
	 */
	public static function peppol_endpoint( $explicit, $vat, $country = '' ) {
		$explicit = trim( (string) $explicit );
		if ( '' !== $explicit ) {
			$explicit = preg_replace( '/^iso6523-actorid-upis::/i', '', $explicit );
			if ( preg_match( '/^([0-9]{4})\s*:\s*(\S.*)$/', $explicit, $m ) ) {
				return array(
					'scheme' => $m[1],
					'id'     => trim( $m[2] ),
				);
			}
		}

		$vat = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $vat ) );
		if ( '' === $vat ) {
			return array();
		}
		if ( preg_match( '/^[A-Z]{2}/', $vat ) ) {
			$prefix = substr( $vat, 0, 2 );
		} else {
			$prefix = strtoupper( substr( (string) $country, 0, 2 ) );
			$prefix = 'GR' === $prefix ? 'EL' : $prefix;
			$vat    = $prefix . $vat;
		}
		if ( 'BE' === $prefix ) {
			$digits = substr( $vat, 2 );
			if ( 9 === strlen( $digits ) ) {
				$digits = '0' . $digits;
			}
			return preg_match( '/^[0-9]{10}$/', $digits ) ? array(
				'scheme' => '0208',
				'id'     => $digits,
			) : array();
		}
		if ( ! isset( self::$vat_eas[ $prefix ] ) ) {
			return array();
		}
		return array(
			'scheme' => self::$vat_eas[ $prefix ],
			'id'     => $vat,
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

		// The company is the legal buyer on a business invoice (BT-44); else the person.
		$buyer_name = trim( (string) $order->get_billing_company() );
		if ( '' === $buyer_name ) {
			$buyer_name = trim( (string) $order->get_formatted_billing_full_name() );
		}
		$buyer = array(
			'name'     => $buyer_name,
			'street'   => (string) $order->get_billing_address_1(),
			'street2'  => (string) $order->get_billing_address_2(),
			'city'     => (string) $order->get_billing_city(),
			'postcode' => (string) $order->get_billing_postcode(),
			'country'  => strtoupper( (string) $order->get_billing_country() ),
			'vat'      => self::buyer_vat( $order ),
			'email'    => (string) $order->get_billing_email(),
		);
		$buyer['endpoint'] = self::peppol_endpoint( (string) $order->get_meta( '_billing_peppol_id' ), $buyer['vat'], $buyer['country'] );

		// Zero-rate lines: say why there is no VAT (intra-EU supply, reverse charge or export).
		$ship_country = method_exists( $order, 'get_shipping_country' ) ? strtoupper( (string) $order->get_shipping_country() ) : '';
		$deliver_to   = '' !== $ship_country ? $ship_country : $buyer['country'];
		$deliver_addr = '' !== $ship_country
			? array(
				'street'   => (string) $order->get_shipping_address_1(),
				'city'     => (string) $order->get_shipping_city(),
				'postcode' => (string) $order->get_shipping_postcode(),
				'country'  => $ship_country,
			)
			: array(
				'street'   => $buyer['street'],
				'city'     => $buyer['city'],
				'postcode' => $buyer['postcode'],
				'country'  => $buyer['country'],
			);
		$doc_settings = class_exists( 'OBLT_Plugin' ) ? OBLT_Plugin::get_doc_settings() : array();
		$zero_cat     = self::zero_rate_category( (string) $seller['country'], $buyer['country'], $deliver_to, $buyer['vat'], $doc_settings );
		// Once the invoice has a number, its tax category is fixed: a later settings change
		// must not rewrite an invoice that was already issued.
		$frozen_cat = (string) $order->get_meta( '_oblt_zero_vat_category' );
		if ( in_array( $frozen_cat, array( 'K', 'AE', 'G', 'Z' ), true ) ) {
			$zero_cat = $frozen_cat;
		} elseif ( '' !== (string) $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) && method_exists( $order, 'save_meta_data' ) ) {
			$order->update_meta_data( '_oblt_zero_vat_category', $zero_cat );
			$order->save_meta_data();
		}
		if ( 'Z' !== $zero_cat ) {
			foreach ( $lines as &$zl ) {
				if ( 'Z' === $zl['tax_category'] ) {
					$zl['tax_category'] = $zero_cat;
				}
			}
			unset( $zl );
			$tax_subtotals = self::group_tax( $lines );
		}

		$buyer_reference = trim( html_entity_decode( (string) $order->get_meta( '_billing_buyer_reference' ), ENT_QUOTES, 'UTF-8' ) );

		$date       = $order->get_date_created();
		$issue_date = $date ? $date->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$settings   = class_exists( 'OBLT_Plugin' ) ? OBLT_Plugin::get_doc_settings() : array();

		// A paid order shows its payment as prepaid, so the buyer does not pay twice.
		$paid_on   = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
		$paid      = self::is_settled( $order );
		$done_on   = method_exists( $order, 'get_date_completed' ) ? $order->get_date_completed() : null;
		$method    = method_exists( $order, 'get_payment_method_title' ) ? (string) $order->get_payment_method_title() : '';
		$money     = self::amounts_due( $tax_inclusive, $payable, $paid );
		$due_days  = isset( $settings['einv_due_days'] ) && '' !== (string) $settings['einv_due_days'] ? max( 0, (int) $settings['einv_due_days'] ) : 14;
		$due_date  = ( ! $paid && $money['payable'] > 0 ) ? gmdate( 'Y-m-d', (int) strtotime( $issue_date . ' +' . $due_days . ' days' ) ) : '';
		if ( $paid ) {
			$terms = $paid_on
				/* translators: 1: payment date, 2: payment method */
				? sprintf( __( 'Paid on %1$s (%2$s).', 'orderbay-lite' ), $paid_on->date( 'Y-m-d' ), '' !== $method ? $method : __( 'online', 'orderbay-lite' ) )
				: __( 'Paid.', 'orderbay-lite' );
		} else {
			/* translators: %s: due date */
			$terms = '' !== $due_date ? sprintf( __( 'Payment due by %s.', 'orderbay-lite' ), $due_date ) : '';
		}
		$delivered = $done_on ? $done_on : ( $paid_on ? $paid_on : $date );

		return array(
			'invoice_number' => (string) ( $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) ? $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) : $order->get_order_number() ),
			'issue_date'     => $issue_date,
			'due_date'       => $due_date,
			'delivery_date'  => $delivered ? $delivered->date( 'Y-m-d' ) : $issue_date,
			'payment_terms'  => $terms,
			'payment'        => self::payment_means( $settings, $method, method_exists( $order, 'get_payment_method' ) ? (string) $order->get_payment_method() : '' ),
			'currency'       => (string) $order->get_currency(),
			'type_code'      => '380',
			'note'           => (string) $order->get_customer_note(),
			'buyer_ref'      => '' !== $buyer_reference ? $buyer_reference : (string) $order->get_order_number(),
			'seller'         => $seller,
			'buyer'          => $buyer,
			'deliver_to'     => $deliver_to,
			'deliver_addr'   => $deliver_addr,
			'lines'          => $lines,
			'tax_subtotals'  => $tax_subtotals,
			'totals'         => array(
				'line_extension' => $line_extension,
				'tax_exclusive'  => $line_extension,
				'tax_total'      => $tax_total,
				'tax_inclusive'  => $tax_inclusive,
				'payable'        => $money['payable'],
				'prepaid'        => $money['prepaid'],
				'rounding'       => $money['rounding'],
			),
		);
	}

	/**
	 * Prepaid (BT-113), rounding (BT-114) and amount due (BT-115) so that
	 * due = tax inclusive - prepaid + rounding (BR-CO-16). Pure.
	 *
	 * @param float $tax_inclusive Invoice total with VAT worked out from the lines.
	 * @param float $order_total   What WooCommerce charged.
	 * @param bool  $paid          Whether the order is already paid.
	 * @return array{payable:float,prepaid:float,rounding:float}
	 */
	public static function amounts_due( $tax_inclusive, $order_total, $paid ) {
		$tax_inclusive = round( (float) $tax_inclusive, 2 );
		$order_total   = round( (float) $order_total, 2 );
		$diff          = round( $order_total - $tax_inclusive, 2 );
		$rounding      = ( $diff > 0 && $diff <= 0.05 ) ? $diff : 0.0;
		if ( $paid ) {
			return array(
				'payable'  => 0.0,
				'prepaid'  => round( $tax_inclusive + $rounding, 2 ),
				'rounding' => $rounding,
			);
		}
		$prepaid = round( $tax_inclusive + $rounding - $order_total, 2 );
		return array(
			'payable'  => $order_total,
			'prepaid'  => $prepaid > 0 ? $prepaid : 0.0,
			'rounding' => $rounding,
		);
	}

	/**
	 * Whether the money for an order has arrived. WooCommerce counts every Processing
	 * order as paid, including cash on delivery where nothing has been received yet, so
	 * trust its paid date (set when payment completes) or a Completed status instead.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_settled( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'is_paid' ) || ! $order->is_paid() ) {
			return false;
		}
		$paid_on = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
		return $paid_on || ( method_exists( $order, 'has_status' ) && $order->has_status( 'completed' ) );
	}

	/**
	 * Tax lines for the printed invoice: the buyer's VAT ID and reference, and why a
	 * zero-rated sale has no VAT (the same reasons the e-invoice carries).
	 *
	 * @param WC_Order $order Order.
	 * @return array{buyer_vat:string,buyer_ref:string,reasons:string[]}
	 */
	public static function invoice_tax_notes( $order ) {
		$out = array(
			'buyer_vat' => self::buyer_vat( $order ),
			'buyer_ref' => trim( html_entity_decode( (string) $order->get_meta( '_billing_buyer_reference' ), ENT_QUOTES, 'UTF-8' ) ),
			'reasons'   => array(),
		);
		try {
			$data = self::order_to_invoice_data( $order );
			foreach ( (array) ( $data['tax_subtotals'] ?? array() ) as $g ) {
				if ( ! empty( $g['reason'] ) ) {
					$out['reasons'][] = (string) $g['reason'];
				}
			}
			$out['reasons'] = array_values( array_unique( $out['reasons'] ) );
		} catch ( \Throwable $e ) {
			unset( $e ); // The printed invoice never fails because of the e-invoice mapper.
		}
		return $out;
	}

	/**
	 * Payment block for the printed invoice: how it was paid, or by when and where to pay.
	 * Uses the same paid rule and due date as the e-invoice XML.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $settings Document settings.
	 * @return array{paid:bool,paid_on:string,method:string,due_date:string,iban:string,bic:string,account_name:string}
	 */
	public static function payment_summary( $order, array $settings ) {
		$paid     = self::is_settled( $order );
		$paid_on  = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
		$method   = method_exists( $order, 'get_payment_method_title' ) ? (string) $order->get_payment_method_title() : '';
		$created  = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$issue    = $created ? $created->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$due_days = isset( $settings['einv_due_days'] ) && '' !== (string) $settings['einv_due_days'] ? max( 0, (int) $settings['einv_due_days'] ) : 14;
		$means    = self::payment_means( $settings, $method, method_exists( $order, 'get_payment_method' ) ? (string) $order->get_payment_method() : '' );
		return array(
			'paid'         => $paid,
			'paid_on'      => ( $paid && $paid_on ) ? $paid_on->date( 'Y-m-d' ) : '',
			'method'       => $method,
			'due_date'     => $paid ? '' : gmdate( 'Y-m-d', (int) strtotime( $issue . ' +' . $due_days . ' days' ) ),
			'iban'         => $means['iban'],
			'bic'          => $means['bic'],
			'account_name' => $means['account_name'],
		);
	}

	/**
	 * Payment instructions (BG-16): SEPA credit transfer when an IBAN is set, otherwise
	 * "instrument not defined" with the WooCommerce payment method as text. Pure.
	 *
	 * @param array  $settings Document settings.
	 * @param string $method   Payment method title.
	 * @param string $gateway  Payment gateway id; cash on delivery is paid in cash (code 10), not by transfer.
	 * @return array{code:string,info:string,iban:string,bic:string,account_name:string}
	 */
	public static function payment_means( array $settings, $method = '', $gateway = '' ) {
		if ( 'cod' === $gateway ) {
			return array(
				'code'         => '10',
				'info'         => (string) $method,
				'iban'         => '',
				'bic'          => '',
				'account_name' => '',
			);
		}
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) ( $settings['einv_iban'] ?? '' ) ) );
		$bic  = strtoupper( preg_replace( '/\s+/', '', (string) ( $settings['einv_bic'] ?? '' ) ) );
		if ( '' === $iban && function_exists( 'get_option' ) ) {
			// No IBAN in the e-invoice settings: use the first WooCommerce bank transfer (BACS) account.
			$bacs = get_option( 'woocommerce_bacs_accounts', array() );
			if ( is_array( $bacs ) && isset( $bacs[0] ) && is_array( $bacs[0] ) && ! empty( $bacs[0]['iban'] ) ) {
				$iban = strtoupper( preg_replace( '/\s+/', '', (string) $bacs[0]['iban'] ) );
				$bic  = strtoupper( preg_replace( '/\s+/', '', (string) ( $bacs[0]['bic'] ?? '' ) ) );
				if ( empty( $settings['einv_account_name'] ) && ! empty( $bacs[0]['account_name'] ) ) {
					$settings['einv_account_name'] = (string) $bacs[0]['account_name'];
				}
			}
		}
		return array(
			'code'         => '' !== $iban ? '58' : '1',
			'info'         => '' !== $iban ? __( 'SEPA credit transfer', 'orderbay-lite' ) : (string) $method,
			'iban'         => $iban,
			'bic'          => $bic,
			'account_name' => (string) ( $settings['einv_account_name'] ?? '' ),
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
	 * EU member states (VAT prefixes use EL for Greece; country codes use GR).
	 *
	 * @var string[]
	 */
	const EU_COUNTRIES = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );

	/**
	 * VAT category for lines WooCommerce charged at 0%. Pure.
	 *
	 * - Business buyer with a VAT ID in another EU country: K (intra-community supply of
	 *   goods), or AE (reverse charge) when the store sells services.
	 * - Delivery outside the EU from an EU seller: G (export).
	 * - Anything else stays Z, as before.
	 *
	 * @param string $seller_country Seller country (ISO 3166-1 alpha-2).
	 * @param string $buyer_country  Buyer billing country.
	 * @param string $deliver_to     Delivery country (shipping, else billing).
	 * @param string $buyer_vat      Buyer VAT ID.
	 * @param array  $settings       Document settings (einv_eu_b2b: goods|services).
	 * @return string K|AE|G|Z
	 */
	public static function zero_rate_category( $seller_country, $buyer_country, $deliver_to, $buyer_vat, array $settings = array() ) {
		$seller = strtoupper( (string) $seller_country );
		$buyer  = strtoupper( (string) $buyer_country );
		$dest   = strtoupper( (string) $deliver_to );
		if ( ! in_array( $seller, self::EU_COUNTRIES, true ) ) {
			return 'Z';
		}
		$vat = strtoupper( (string) preg_replace( '/[\s.\-]+/', '', (string) $buyer_vat ) );
		if ( '' !== $vat && in_array( $buyer, self::EU_COUNTRIES, true ) && $buyer !== $seller ) {
			return 'services' === (string) ( $settings['einv_eu_b2b'] ?? 'goods' ) ? 'AE' : 'K';
		}
		if ( '' !== $dest && ! in_array( $dest, self::EU_COUNTRIES, true ) ) {
			return 'G';
		}
		return 'Z';
	}

	/**
	 * VAT exemption reason (BT-121 code, BT-120 text) that EN 16931 requires for K, AE
	 * and G, and forbids for S and Z. Pure.
	 *
	 * @param string $category VAT category code.
	 * @return array{code:string,text:string}
	 */
	public static function exemption_reason( $category ) {
		switch ( (string) $category ) {
			case 'K':
				return array( 'code' => 'VATEX-EU-IC', 'text' => __( 'Intra-community supply (tax-free under Article 138 VAT Directive)', 'orderbay-lite' ) );
			case 'AE':
				return array( 'code' => 'VATEX-EU-AE', 'text' => __( 'Reverse charge: VAT is payable by the customer', 'orderbay-lite' ) );
			case 'G':
				return array( 'code' => 'VATEX-EU-G', 'text' => __( 'Export outside the EU (tax-free)', 'orderbay-lite' ) );
		}
		return array( 'code' => '', 'text' => '' );
	}

	/**
	 * K lines need the deliver-to country (BR-IC-12). Pure.
	 *
	 * @param array $d Invoice data.
	 * @return bool
	 */
	public static function needs_deliver_to( array $d ) {
		if ( empty( $d['deliver_to'] ) || empty( $d['tax_subtotals'] ) ) {
			return false;
		}
		foreach ( (array) $d['tax_subtotals'] as $g ) {
			if ( 'K' === ( $g['category'] ?? '' ) ) {
				return true;
			}
		}
		return false;
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
				$reason         = self::exemption_reason( $ln['tax_category'] );
				$groups[ $key ] = array(
					'category'    => $ln['tax_category'],
					'percent'     => $ln['tax_percent'],
					'taxable'     => 0.0,
					'tax'         => 0.0,
					'reason_code' => $reason['code'],
					'reason'      => $reason['text'],
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
	 * True when the printed From block names a different place than the store address the
	 * e-invoice uses (neither its street nor its postcode appears). Empty From block or postcode: no warning. Pure.
	 *
	 * @param string $from_lines From name/address box.
	 * @param array  $seller     seller_data().
	 * @return bool
	 */
	public static function address_mismatch( $from_lines, array $seller ) {
		$postcode = preg_replace( '/\s+/', '', (string) ( $seller['postcode'] ?? '' ) );
		$street   = preg_replace( '/\s+/', '', (string) ( $seller['street'] ?? '' ) );
		$from     = preg_replace( '/\s+/', '', (string) $from_lines );
		if ( '' === $from || ( '' === $postcode && '' === $street ) ) {
			return false;
		}
		$has_postcode = '' !== $postcode && false !== stripos( $from, $postcode );
		$has_street   = '' !== $street && false !== stripos( $from, $street );
		return ! $has_postcode && ! $has_street;
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
		} elseif ( ! self::party_endpoint( $seller ) ) {
			$issues[] = __( 'Seller Peppol ID is missing and can\'t be worked out from the VAT number (set Peppol ID in Documents settings).', 'orderbay-lite' );
		}
		if ( ! empty( $seller['iban'] ) && ! self::valid_iban( $seller['iban'] ) ) {
			$issues[] = __( 'The IBAN in Documents settings is not valid.', 'orderbay-lite' );
		}
		return $issues;
	}

	/**
	 * @param array  $d       Normalized invoice data.
	 * @param string $profile '' (EN 16931 / Peppol) or 'xrechnung' for the extra German rules.
	 * @return string[]
	 */
	public static function compliance_issues( array $d, $profile = '' ) {
		$seller = isset( $d['seller'] ) && is_array( $d['seller'] ) ? $d['seller'] : array();
		$buyer  = isset( $d['buyer'] ) && is_array( $d['buyer'] ) ? $d['buyer'] : array();
		$issues = self::seller_issues( $seller );

		if ( empty( $d['currency'] ) ) {
			$issues[] = __( 'Invoice currency is missing.', 'orderbay-lite' );
		}
		if ( empty( $buyer['country'] ) || 2 !== strlen( (string) $buyer['country'] ) ) {
			$issues[] = __( 'Buyer country code is missing or invalid.', 'orderbay-lite' );
		}
		if ( ! empty( $buyer['vat'] ) && ! self::party_endpoint( $buyer ) ) {
			$issues[] = __( 'Buyer Peppol ID is missing and can\'t be worked out from their VAT number.', 'orderbay-lite' );
		}
		if ( empty( $d['lines'] ) ) {
			$issues[] = __( 'Invoice has no lines.', 'orderbay-lite' );
		}
		if ( 'xrechnung' === $profile ) {
			$issues = array_merge( $issues, self::xrechnung_issues( $d ) );
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

	/**
	 * XRechnung 3.0 rules on top of EN 16931 (BR-DE-*), for what the merchant can fix.
	 *
	 * @param array $d Normalized invoice data.
	 * @return string[]
	 */
	public static function xrechnung_issues( array $d ) {
		$seller = isset( $d['seller'] ) && is_array( $d['seller'] ) ? $d['seller'] : array();
		$buyer  = isset( $d['buyer'] ) && is_array( $d['buyer'] ) ? $d['buyer'] : array();
		$issues = array();
		if ( empty( $seller['phone'] ) ) {
			$issues[] = __( 'XRechnung needs a seller contact phone number (set it under E-invoicing in Documents settings).', 'orderbay-lite' );
		}
		if ( empty( $seller['email'] ) ) {
			$issues[] = __( 'XRechnung needs a seller contact email (the WooCommerce "From" address).', 'orderbay-lite' );
		}
		if ( empty( $buyer['city'] ) || empty( $buyer['postcode'] ) ) {
			$issues[] = __( 'XRechnung needs the buyer\'s city and postcode.', 'orderbay-lite' );
		}
		if ( ! self::electronic_address( $buyer, true ) ) {
			$issues[] = __( 'XRechnung needs the buyer\'s email or Peppol ID.', 'orderbay-lite' );
		}
		if ( empty( $d['buyer_ref'] ) ) {
			$issues[] = __( 'XRechnung needs a buyer reference (Leitweg-ID for public buyers).', 'orderbay-lite' );
		}
		$pay = isset( $d['payment'] ) && is_array( $d['payment'] ) ? $d['payment'] : array();
		if ( '58' === ( $pay['code'] ?? '' ) && empty( $pay['iban'] ) ) {
			$issues[] = __( 'SEPA credit transfer needs an IBAN.', 'orderbay-lite' );
		}
		return $issues;
	}

	/**
	 * A party's electronic address (BT-34 / BT-49): its Peppol ID, else (when allowed)
	 * its email under scheme EM.
	 *
	 * @param array $p           Party data.
	 * @param bool  $allow_email Fall back to the email address.
	 * @return array{scheme:string,id:string}|array{}
	 */
	public static function electronic_address( array $p, $allow_email ) {
		$endpoint = self::party_endpoint( $p );
		if ( $endpoint ) {
			return $endpoint;
		}
		$email = isset( $p['email'] ) ? trim( (string) $p['email'] ) : '';
		if ( $allow_email && '' !== $email && false !== strpos( $email, '@' ) ) {
			return array(
				'scheme' => 'EM',
				'id'     => $email,
			);
		}
		return array();
	}

	/* ─── UBL 2.1 (Peppol BIS Billing 3.0) ─────────────────────────────── */

	/**
	 * @param array  $d       Normalized invoice data.
	 * @param string $profile 'peppol' (Peppol BIS Billing 3.0) or 'xrechnung' (XRechnung 3.0).
	 * @return string UBL 2.1 Invoice XML.
	 */
	public static function build_ubl( array $d, $profile = 'peppol' ) {
		$xr                = ( 'xrechnung' === $profile );
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
		$t   = $d['totals'];

		self::el( $doc, $root, 'cbc:CustomizationID', $xr ? self::XRECHNUNG_ID : self::PEPPOL_ID );
		self::el( $doc, $root, 'cbc:ProfileID', self::BUSINESS_PROCESS );
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
		if ( ! empty( $d['buyer_ref'] ) ) {
			self::el( $doc, $root, 'cbc:BuyerReference', (string) $d['buyer_ref'] );
		}

		// Parties.
		$supplier = self::el( $doc, $root, 'cac:AccountingSupplierParty' );
		self::ubl_party( $doc, $supplier, $d['seller'], $xr, true );
		$customer = self::el( $doc, $root, 'cac:AccountingCustomerParty' );
		self::ubl_party( $doc, $customer, $d['buyer'], $xr, false );

		$deliver_to = self::needs_deliver_to( $d ) ? (string) $d['deliver_to'] : '';
		if ( ! empty( $d['delivery_date'] ) || '' !== $deliver_to ) {
			$delivery = self::el( $doc, $root, 'cac:Delivery' );
			if ( ! empty( $d['delivery_date'] ) ) {
				self::el( $doc, $delivery, 'cbc:ActualDeliveryDate', (string) $d['delivery_date'] );
			}
			if ( '' !== $deliver_to ) {
				$loc  = self::el( $doc, $delivery, 'cac:DeliveryLocation' );
				$addr = self::el( $doc, $loc, 'cac:Address' );
				$da   = isset( $d['deliver_addr'] ) && is_array( $d['deliver_addr'] ) ? $d['deliver_addr'] : array();
				if ( ! empty( $da['street'] ) ) {
					self::el( $doc, $addr, 'cbc:StreetName', (string) $da['street'] );
				}
				if ( ! empty( $da['city'] ) ) {
					self::el( $doc, $addr, 'cbc:CityName', (string) $da['city'] );
				}
				if ( ! empty( $da['postcode'] ) ) {
					self::el( $doc, $addr, 'cbc:PostalZone', (string) $da['postcode'] );
				}
				$ctry = self::el( $doc, $addr, 'cac:Country' );
				self::el( $doc, $ctry, 'cbc:IdentificationCode', $deliver_to );
			}
		}

		$pay = isset( $d['payment'] ) && is_array( $d['payment'] ) ? $d['payment'] : array();
		if ( ! empty( $pay['code'] ) ) {
			$pm    = self::el( $doc, $root, 'cac:PaymentMeans' );
			$attrs = '' !== (string) ( $pay['info'] ?? '' ) ? array( 'name' => (string) $pay['info'] ) : array();
			self::el( $doc, $pm, 'cbc:PaymentMeansCode', (string) $pay['code'], $attrs );
			self::el( $doc, $pm, 'cbc:PaymentID', (string) $d['invoice_number'] );
			if ( ! empty( $pay['iban'] ) ) {
				$acct = self::el( $doc, $pm, 'cac:PayeeFinancialAccount' );
				self::el( $doc, $acct, 'cbc:ID', (string) $pay['iban'] );
				if ( ! empty( $pay['account_name'] ) ) {
					self::el( $doc, $acct, 'cbc:Name', (string) $pay['account_name'] );
				}
				if ( ! empty( $pay['bic'] ) ) {
					$branch = self::el( $doc, $acct, 'cac:FinancialInstitutionBranch' );
					self::el( $doc, $branch, 'cbc:ID', (string) $pay['bic'] );
				}
			}
		}
		if ( ! empty( $d['payment_terms'] ) ) {
			$terms = self::el( $doc, $root, 'cac:PaymentTerms' );
			self::el( $doc, $terms, 'cbc:Note', (string) $d['payment_terms'] );
		}

		// Tax total.
		$tax_total = self::el( $doc, $root, 'cac:TaxTotal' );
		self::el( $doc, $tax_total, 'cbc:TaxAmount', self::money( $t['tax_total'] ), array( 'currencyID' => $cur ) );
		foreach ( $d['tax_subtotals'] as $g ) {
			$sub = self::el( $doc, $tax_total, 'cac:TaxSubtotal' );
			self::el( $doc, $sub, 'cbc:TaxableAmount', self::money( $g['taxable'] ), array( 'currencyID' => $cur ) );
			self::el( $doc, $sub, 'cbc:TaxAmount', self::money( $g['tax'] ), array( 'currencyID' => $cur ) );
			$cat = self::el( $doc, $sub, 'cac:TaxCategory' );
			self::el( $doc, $cat, 'cbc:ID', (string) $g['category'] );
			self::el( $doc, $cat, 'cbc:Percent', self::money( $g['percent'] ) );
			if ( ! empty( $g['reason_code'] ) ) {
				self::el( $doc, $cat, 'cbc:TaxExemptionReasonCode', (string) $g['reason_code'] );
				self::el( $doc, $cat, 'cbc:TaxExemptionReason', (string) $g['reason'] );
			}
			$scheme = self::el( $doc, $cat, 'cac:TaxScheme' );
			self::el( $doc, $scheme, 'cbc:ID', 'VAT' );
		}

		// Monetary totals.
		$mon = self::el( $doc, $root, 'cac:LegalMonetaryTotal' );
		self::el( $doc, $mon, 'cbc:LineExtensionAmount', self::money( $t['line_extension'] ), array( 'currencyID' => $cur ) );
		self::el( $doc, $mon, 'cbc:TaxExclusiveAmount', self::money( $t['tax_exclusive'] ), array( 'currencyID' => $cur ) );
		self::el( $doc, $mon, 'cbc:TaxInclusiveAmount', self::money( $t['tax_inclusive'] ), array( 'currencyID' => $cur ) );
		if ( (float) $t['prepaid'] > 0 ) {
			self::el( $doc, $mon, 'cbc:PrepaidAmount', self::money( $t['prepaid'] ), array( 'currencyID' => $cur ) );
		}
		if ( (float) ( $t['rounding'] ?? 0 ) > 0 ) {
			self::el( $doc, $mon, 'cbc:PayableRoundingAmount', self::money( $t['rounding'] ), array( 'currencyID' => $cur ) );
		}
		self::el( $doc, $mon, 'cbc:PayableAmount', self::money( $t['payable'] ), array( 'currencyID' => $cur ) );

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
	 * @param DOMDocument $doc         Document.
	 * @param DOMElement  $parent      AccountingSupplierParty / AccountingCustomerParty.
	 * @param array       $p           Party data.
	 * @param bool        $allow_email Use the email as the electronic address when there is no Peppol ID.
	 * @param bool        $is_seller   Seller party (gets the contact group).
	 */
	private static function ubl_party( DOMDocument $doc, DOMElement $parent, array $p, $allow_email = false, $is_seller = false ) {
		$party    = self::el( $doc, $parent, 'cac:Party' );
		$endpoint = self::electronic_address( $p, $allow_email );
		if ( $endpoint ) {
			self::el( $doc, $party, 'cbc:EndpointID', $endpoint['id'], array( 'schemeID' => $endpoint['scheme'] ) );
		}
		$addr = self::el( $doc, $party, 'cac:PostalAddress' );
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

		$contact = $is_seller ? self::seller_contact( $p ) : array();
		if ( $contact ) {
			$c = self::el( $doc, $party, 'cac:Contact' );
			self::el( $doc, $c, 'cbc:Name', $contact['name'] );
			if ( '' !== $contact['phone'] ) {
				self::el( $doc, $c, 'cbc:Telephone', $contact['phone'] );
			}
			if ( '' !== $contact['email'] ) {
				self::el( $doc, $c, 'cbc:ElectronicMail', $contact['email'] );
			}
		}
	}

	/**
	 * Seller contact (BG-6), or empty when there is no phone or email to give.
	 *
	 * @param array $p Seller party data.
	 * @return array{name:string,phone:string,email:string}|array{}
	 */
	private static function seller_contact( array $p ) {
		$phone = trim( (string) ( $p['phone'] ?? '' ) );
		$email = trim( (string) ( $p['email'] ?? '' ) );
		if ( '' === $phone && '' === $email ) {
			return array();
		}
		$name = trim( (string) ( $p['contact'] ?? '' ) );
		return array(
			'name'  => '' !== $name ? $name : (string) ( $p['name'] ?? '' ),
			'phone' => $phone,
			'email' => $email,
		);
	}

	/* ─── UN/CEFACT CII (Factur-X / ZUGFeRD, EN 16931 or XRechnung) ────── */

	/**
	 * @param array  $d       Normalized invoice data.
	 * @param string $profile 'en16931' (Factur-X / ZUGFeRD EN 16931) or 'xrechnung'.
	 * @return string CII XML.
	 */
	public static function build_cii( array $d, $profile = 'en16931' ) {
		$xr                = ( 'xrechnung' === $profile );
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
		$t   = $d['totals'];

		// Context: business process + specification (EN 16931 or XRechnung).
		$ctx = self::el( $doc, $root, 'rsm:ExchangedDocumentContext' );
		if ( $xr ) {
			$bp = self::el( $doc, $ctx, 'ram:BusinessProcessSpecifiedDocumentContextParameter' );
			self::el( $doc, $bp, 'ram:ID', self::BUSINESS_PROCESS );
		}
		$gcp = self::el( $doc, $ctx, 'ram:GuidelineSpecifiedDocumentContextParameter' );
		self::el( $doc, $gcp, 'ram:ID', $xr ? self::XRECHNUNG_ID : 'urn:cen.eu:en16931:2017' );

		// Header document.
		$hdr = self::el( $doc, $root, 'rsm:ExchangedDocument' );
		self::el( $doc, $hdr, 'ram:ID', (string) $d['invoice_number'] );
		self::el( $doc, $hdr, 'ram:TypeCode', (string) $d['type_code'] );
		$dt = self::el( $doc, $hdr, 'ram:IssueDateTime' );
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

		// Header trade agreement (buyer reference, parties).
		$hta = self::el( $doc, $tx, 'ram:ApplicableHeaderTradeAgreement' );
		if ( ! empty( $d['buyer_ref'] ) ) {
			self::el( $doc, $hta, 'ram:BuyerReference', (string) $d['buyer_ref'] );
		}
		self::cii_party( $doc, $hta, 'ram:SellerTradeParty', $d['seller'] );
		self::cii_party( $doc, $hta, 'ram:BuyerTradeParty', $d['buyer'] );

		// Delivery: the actual delivery date (BT-72) when known.
		$htd = self::el( $doc, $tx, 'ram:ApplicableHeaderTradeDelivery' );
		if ( self::needs_deliver_to( $d ) ) {
			$ship = self::el( $doc, $htd, 'ram:ShipToTradeParty' );
			$spa  = self::el( $doc, $ship, 'ram:PostalTradeAddress' );
			$da   = isset( $d['deliver_addr'] ) && is_array( $d['deliver_addr'] ) ? $d['deliver_addr'] : array();
			if ( ! empty( $da['postcode'] ) ) {
				self::el( $doc, $spa, 'ram:PostcodeCode', (string) $da['postcode'] );
			}
			if ( ! empty( $da['street'] ) ) {
				self::el( $doc, $spa, 'ram:LineOne', (string) $da['street'] );
			}
			if ( ! empty( $da['city'] ) ) {
				self::el( $doc, $spa, 'ram:CityName', (string) $da['city'] );
			}
			self::el( $doc, $spa, 'ram:CountryID', (string) $d['deliver_to'] );
		}
		if ( ! empty( $d['delivery_date'] ) ) {
			$ev  = self::el( $doc, $htd, 'ram:ActualDeliverySupplyChainEvent' );
			$odt = self::el( $doc, $ev, 'ram:OccurrenceDateTime' );
			self::el( $doc, $odt, 'udt:DateTimeString', str_replace( '-', '', (string) $d['delivery_date'] ), array( 'format' => '102' ) );
		}

		// Settlement.
		$hts = self::el( $doc, $tx, 'ram:ApplicableHeaderTradeSettlement' );
		$pay = isset( $d['payment'] ) && is_array( $d['payment'] ) ? $d['payment'] : array();
		if ( ! empty( $pay['code'] ) ) {
			self::el( $doc, $hts, 'ram:PaymentReference', (string) $d['invoice_number'] );
		}
		self::el( $doc, $hts, 'ram:InvoiceCurrencyCode', $cur );
		if ( ! empty( $pay['code'] ) ) {
			$pm = self::el( $doc, $hts, 'ram:SpecifiedTradeSettlementPaymentMeans' );
			self::el( $doc, $pm, 'ram:TypeCode', (string) $pay['code'] );
			if ( '' !== (string) ( $pay['info'] ?? '' ) ) {
				self::el( $doc, $pm, 'ram:Information', (string) $pay['info'] );
			}
			if ( ! empty( $pay['iban'] ) ) {
				$acct = self::el( $doc, $pm, 'ram:PayeePartyCreditorFinancialAccount' );
				self::el( $doc, $acct, 'ram:IBANID', (string) $pay['iban'] );
				if ( ! empty( $pay['account_name'] ) ) {
					self::el( $doc, $acct, 'ram:AccountName', (string) $pay['account_name'] );
				}
				if ( ! empty( $pay['bic'] ) ) {
					$inst = self::el( $doc, $pm, 'ram:PayeeSpecifiedCreditorFinancialInstitution' );
					self::el( $doc, $inst, 'ram:BICID', (string) $pay['bic'] );
				}
			}
		}
		foreach ( $d['tax_subtotals'] as $g ) {
			$tax = self::el( $doc, $hts, 'ram:ApplicableTradeTax' );
			self::el( $doc, $tax, 'ram:CalculatedAmount', self::money( $g['tax'] ) );
			self::el( $doc, $tax, 'ram:TypeCode', 'VAT' );
			if ( ! empty( $g['reason_code'] ) ) {
				self::el( $doc, $tax, 'ram:ExemptionReason', (string) $g['reason'] );
			}
			self::el( $doc, $tax, 'ram:BasisAmount', self::money( $g['taxable'] ) );
			self::el( $doc, $tax, 'ram:CategoryCode', (string) $g['category'] );
			if ( ! empty( $g['reason_code'] ) ) {
				self::el( $doc, $tax, 'ram:ExemptionReasonCode', (string) $g['reason_code'] );
			}
			self::el( $doc, $tax, 'ram:RateApplicablePercent', self::money( $g['percent'] ) );
		}
		if ( ! empty( $d['payment_terms'] ) || ! empty( $d['due_date'] ) ) {
			$terms = self::el( $doc, $hts, 'ram:SpecifiedTradePaymentTerms' );
			if ( ! empty( $d['payment_terms'] ) ) {
				self::el( $doc, $terms, 'ram:Description', (string) $d['payment_terms'] );
			}
			if ( ! empty( $d['due_date'] ) ) {
				$due = self::el( $doc, $terms, 'ram:DueDateDateTime' );
				self::el( $doc, $due, 'udt:DateTimeString', str_replace( '-', '', (string) $d['due_date'] ), array( 'format' => '102' ) );
			}
		}
		$mon = self::el( $doc, $hts, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation' );
		self::el( $doc, $mon, 'ram:LineTotalAmount', self::money( $t['line_extension'] ) );
		self::el( $doc, $mon, 'ram:TaxBasisTotalAmount', self::money( $t['tax_exclusive'] ) );
		self::el( $doc, $mon, 'ram:TaxTotalAmount', self::money( $t['tax_total'] ), array( 'currencyID' => $cur ) );
		if ( (float) ( $t['rounding'] ?? 0 ) > 0 ) {
			self::el( $doc, $mon, 'ram:RoundingAmount', self::money( $t['rounding'] ) );
		}
		self::el( $doc, $mon, 'ram:GrandTotalAmount', self::money( $t['tax_inclusive'] ) );
		if ( (float) $t['prepaid'] > 0 ) {
			self::el( $doc, $mon, 'ram:TotalPrepaidAmount', self::money( $t['prepaid'] ) );
		}
		self::el( $doc, $mon, 'ram:DuePayableAmount', self::money( $t['payable'] ) );

		return (string) $doc->saveXML();
	}

	/**
	 * @param DOMDocument $doc    Document.
	 * @param DOMElement  $parent Header trade agreement.
	 * @param string      $tag    ram:SellerTradeParty|ram:BuyerTradeParty.
	 * @param array       $p      Party data.
	 */
	private static function cii_party( DOMDocument $doc, DOMElement $parent, $tag, array $p ) {
		$party = self::el( $doc, $parent, $tag );
		self::el( $doc, $party, 'ram:Name', (string) ( $p['name'] ?? '' ) );
		$contact = ( 'ram:SellerTradeParty' === $tag ) ? self::seller_contact( $p ) : array();
		if ( $contact ) {
			$c = self::el( $doc, $party, 'ram:DefinedTradeContact' );
			self::el( $doc, $c, 'ram:PersonName', $contact['name'] );
			if ( '' !== $contact['phone'] ) {
				$tel = self::el( $doc, $c, 'ram:TelephoneUniversalCommunication' );
				self::el( $doc, $tel, 'ram:CompleteNumber', $contact['phone'] );
			}
			if ( '' !== $contact['email'] ) {
				$mail = self::el( $doc, $c, 'ram:EmailURIUniversalCommunication' );
				self::el( $doc, $mail, 'ram:URIID', $contact['email'] );
			}
		}
		$addr = self::el( $doc, $party, 'ram:PostalTradeAddress' );
		self::el( $doc, $addr, 'ram:PostcodeCode', (string) ( $p['postcode'] ?? '' ) );
		self::el( $doc, $addr, 'ram:LineOne', (string) ( $p['street'] ?? '' ) );
		if ( ! empty( $p['street2'] ) ) {
			self::el( $doc, $addr, 'ram:LineTwo', (string) $p['street2'] );
		}
		self::el( $doc, $addr, 'ram:CityName', (string) ( $p['city'] ?? '' ) );
		self::el( $doc, $addr, 'ram:CountryID', (string) ( $p['country'] ?? '' ) );
		$uri = self::electronic_address( $p, true );
		if ( $uri ) {
			$u = self::el( $doc, $party, 'ram:URIUniversalCommunication' );
			self::el( $doc, $u, 'ram:URIID', $uri['id'], array( 'schemeID' => $uri['scheme'] ) );
		}
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

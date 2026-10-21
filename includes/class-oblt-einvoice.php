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
	}

	/**
	 * Add VAT number and Peppol ID to the order screen's billing fields so e-invoices can
	 * carry the buyer's Peppol address. WooCommerce saves them as _billing_vat and
	 * _billing_peppol_id. A field another plugin already added is left alone.
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
		return $fields;
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
		$profile = self::pdf_profile( $s );
		echo '<tr><th><label for="oblt_einv_pdf_profile">' . esc_html__( 'Factur-X / ZUGFeRD PDF profile', 'orderbay-lite' ) . '</label></th><td>';
		echo '<select id="oblt_einv_pdf_profile" name="' . esc_attr( $opt ) . '[einv_pdf_profile]">';
		echo '<option value="en16931"' . selected( $profile, 'en16931', false ) . '>' . esc_html__( 'EN 16931 (Factur-X / ZUGFeRD, most buyers)', 'orderbay-lite' ) . '</option>';
		echo '<option value="xrechnung"' . selected( $profile, 'xrechnung', false ) . '>' . esc_html__( 'XRechnung (German public sector)', 'orderbay-lite' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Emailed invoices', 'orderbay-lite' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $opt ) . '[einv_email_facturx]" value="1"' . checked( '1', (string) ( $s['einv_email_facturx'] ?? '0' ), false ) . ' /> ';
		echo esc_html__( 'Attach the Factur-X / ZUGFeRD PDF instead of the plain invoice PDF', 'orderbay-lite' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Customers see the same invoice; their accounting software can read the data inside it.', 'orderbay-lite' ) . '</p></td></tr>';
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
			'vat'      => (string) $order->get_meta( '_billing_vat' ),
			'email'    => (string) $order->get_billing_email(),
		);
		$buyer['endpoint'] = self::peppol_endpoint( (string) $order->get_meta( '_billing_peppol_id' ), $buyer['vat'], $buyer['country'] );

		$buyer_reference = trim( (string) $order->get_meta( '_billing_buyer_reference' ) );

		$date       = $order->get_date_created();
		$issue_date = $date ? $date->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$settings   = class_exists( 'OBLT_Plugin' ) ? OBLT_Plugin::get_doc_settings() : array();

		// A paid order shows its payment as prepaid, so the buyer does not pay twice.
		$paid      = method_exists( $order, 'is_paid' ) && $order->is_paid();
		$paid_on   = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
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
			'payment'        => self::payment_means( $settings, $method ),
			'currency'       => (string) $order->get_currency(),
			'type_code'      => '380',
			'note'           => (string) $order->get_customer_note(),
			'buyer_ref'      => '' !== $buyer_reference ? $buyer_reference : (string) $order->get_order_number(),
			'seller'         => $seller,
			'buyer'          => $buyer,
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
	 * Payment instructions (BG-16): SEPA credit transfer when an IBAN is set, otherwise
	 * "instrument not defined" with the WooCommerce payment method as text. Pure.
	 *
	 * @param array  $settings Document settings.
	 * @param string $method   Payment method title.
	 * @return array{code:string,info:string,iban:string,bic:string,account_name:string}
	 */
	public static function payment_means( array $settings, $method = '' ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) ( $settings['einv_iban'] ?? '' ) ) );
		$bic  = strtoupper( preg_replace( '/\s+/', '', (string) ( $settings['einv_bic'] ?? '' ) ) );
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

		if ( ! empty( $d['delivery_date'] ) ) {
			$delivery = self::el( $doc, $root, 'cac:Delivery' );
			self::el( $doc, $delivery, 'cbc:ActualDeliveryDate', (string) $d['delivery_date'] );
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
			self::el( $doc, $tax, 'ram:BasisAmount', self::money( $g['taxable'] ) );
			self::el( $doc, $tax, 'ram:CategoryCode', (string) $g['category'] );
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

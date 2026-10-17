<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invoice print view (browser Print → Save as PDF) and the PDF download, rendered with
 * the bundled Dompdf (see OBLT_Pdf).
 */
class OBLT_Documents {

	private static $instance = null;

	/**
	 * True while a template renders for Dompdf (OBLT_Pdf::document_html()).
	 *
	 * @var bool
	 */
	public static $pdf_mode = false;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_admin_order_actions_end', array( $this, 'list_row_actions' ), 20 );
		add_action( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_oblt_print_invoice', array( $this, 'action_print_invoice' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_screen_buttons' ) );
		add_action( 'admin_post_oblt_print_invoice', array( $this, 'handle_print_invoice' ) );
		add_action( 'admin_post_oblt_print_packing_slip', array( $this, 'handle_print_packing_slip' ) );
		add_action( 'admin_post_oblt_pdf_download', array( $this, 'handle_pdf_download' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			'oblt_documents',
			OBLT_Plugin::OPT_DOCS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => OBLT_Plugin::default_doc_settings(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$out = OBLT_Plugin::default_doc_settings();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$out['logo_url']       = isset( $input['logo_url'] ) ? esc_url_raw( $input['logo_url'] ) : '';
		$out['from_lines']     = isset( $input['from_lines'] ) ? sanitize_textarea_field( $input['from_lines'] ) : '';
		$out['footer_text']    = isset( $input['footer_text'] ) ? sanitize_textarea_field( $input['footer_text'] ) : '';
		$paper                 = isset( $input['paper'] ) ? sanitize_key( $input['paper'] ) : 'letter';
		$out['paper']          = in_array( $paper, array( 'letter', 'a4' ), true ) ? $paper : 'letter';
		$out['tax_id']         = isset( $input['tax_id'] ) ? sanitize_text_field( $input['tax_id'] ) : '';
		$out['peppol_id'] = isset( $input['peppol_id'] ) ? sanitize_text_field( $input['peppol_id'] ) : '';
		$out['seller_country'] = isset( $input['seller_country'] ) ? strtoupper( substr( sanitize_text_field( $input['seller_country'] ), 0, 2 ) ) : '';
		$out                   = OBLT_Pdf::sanitize_email_settings( $input, $out );
		return $out;
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	/**
	 * Print the document stylesheet and print-button script for a standalone document view.
	 *
	 * Documents are full HTML pages (no wp_head), and the host PDF engine needs the CSS inline,
	 * so the CSS is registered as an inline style on a src-less handle and printed with
	 * wp_print_styles(); the print button script the same way with wp_print_scripts().
	 *
	 * @param string $doc   Document type: invoice|credit-note|packing-slip.
	 * @param string $paper Paper size for @page (letter|A4).
	 */
	public static function print_document_assets( $doc, $paper ) {
		$doc    = in_array( $doc, array( 'credit-note', 'packing-slip' ), true ) ? $doc : 'invoice';
		$handle = 'oblt-doc-' . $doc;
		// The packing slip shares the invoice stylesheet.
		$file   = OBLT_PLUGIN_DIR . 'assets/' . ( 'packing-slip' === $doc ? 'invoice' : $doc ) . '.css';
		$css    = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$paper  = 'A4' === $paper ? 'A4' : 'letter';
		$css    = '@page { size: ' . $paper . '; margin: 12mm; }' . "\n" . $css;
		if ( self::$pdf_mode ) {
			// Dompdf input, never sent to a browser: inline the CSS plus the no-flexbox
			// fallbacks. A plain <style> keeps every render in a request styled (a
			// registered handle prints only once per request).
			$pdf_css = OBLT_PLUGIN_DIR . 'assets/document-pdf.css';
			$css    .= "\n" . ( is_readable( $pdf_css ) ? (string) file_get_contents( $pdf_css ) : '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			echo '<style>' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- local plugin stylesheet for the PDF renderer.
			return;
		}
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), OBLT_VERSION );
		}
		wp_add_inline_style( $handle, $css );
		wp_print_styles( array( $handle ) );
	}

	/**
	 * Print the "Print / Save as PDF" button behaviour (attached to .oblt-print-btn).
	 */
	public static function print_document_script() {
		if ( self::$pdf_mode ) {
			return;
		}
		if ( ! wp_script_is( 'oblt-doc-print', 'registered' ) ) {
			wp_register_script( 'oblt-doc-print', false, array(), OBLT_VERSION, true );
			wp_add_inline_script( 'oblt-doc-print', 'document.querySelectorAll(".oblt-print-btn").forEach(function(b){b.addEventListener("click",function(){window.print();});});' );
		}
		wp_print_scripts( array( 'oblt-doc-print' ) );
	}

	/**
	 * Whether a PDF engine is available: the bundled Dompdf, or a Dompdf already loaded.
	 *
	 * @return string|false 'dompdf'|false
	 */
	public static function detect_pdf_engine() {
		return OBLT_Pdf::engine_available() ? 'dompdf' : false;
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$s   = OBLT_Plugin::get_doc_settings();
		$eng = self::detect_pdf_engine();
		echo '<div class="wrap"><h1>' . esc_html__( 'OrderBay Lite invoices', 'orderbay-lite' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Invoices print from the browser (Print / Save as PDF) and download as PDF files made on your own server by the bundled Dompdf engine. Nothing is sent to an outside service.', 'orderbay-lite' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Not tax advice. Invoice layout, numbering, VAT lines, and e-invoice files are tools — confirm legal requirements with your accountant or tax adviser before using them in production.', 'orderbay-lite' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'oblt_documents' );
		echo '<h2>' . esc_html__( 'Business details & appearance', 'orderbay-lite' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="oblt_logo">' . esc_html__( 'Logo URL', 'orderbay-lite' ) . '</label></th><td>';
		echo '<input type="url" class="large-text" id="oblt_logo" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[logo_url]" value="' . esc_attr( $s['logo_url'] ) . '" /></td></tr>';
		echo '<tr><th><label for="oblt_from">' . esc_html__( 'Business name / address', 'orderbay-lite' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="oblt_from" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[from_lines]">' . esc_textarea( $s['from_lines'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="oblt_footer">' . esc_html__( 'Footer text', 'orderbay-lite' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="oblt_footer" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[footer_text]">' . esc_textarea( $s['footer_text'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="oblt_paper">' . esc_html__( 'Paper size', 'orderbay-lite' ) . '</label></th><td>';
		echo '<select id="oblt_paper" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[paper]">';
		echo '<option value="letter"' . selected( $s['paper'] ?? 'letter', 'letter', false ) . '>Letter</option>';
		echo '<option value="a4"' . selected( $s['paper'] ?? 'letter', 'a4', false ) . '>A4</option>';
		echo '</select></td></tr>';
		echo '<tr><th><label for="oblt_tax_id">' . esc_html__( 'Company VAT / Tax ID', 'orderbay-lite' ) . '</label></th><td>';
		echo '<input type="text" id="oblt_tax_id" class="regular-text" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[tax_id]" value="' . esc_attr( $s['tax_id'] ?? '' ) . '" /></td></tr>';
		echo '<tr><th><label for="oblt_peppol_id">' . esc_html__( 'Peppol ID', 'orderbay-lite' ) . '</label></th><td>';
		echo '<input type="text" id="oblt_peppol_id" class="regular-text" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[peppol_id]" value="' . esc_attr( $s['peppol_id'] ?? '' ) . '" placeholder="0208:0123456789" />';
		echo '<p class="description">' . esc_html__( 'Scheme and ID, for example 0208:0123456789. Blank = worked out from the VAT number.', 'orderbay-lite' ) . '</p></td></tr>';
		echo '<tr><th><label for="oblt_seller_country">' . esc_html__( 'Seller country (ISO)', 'orderbay-lite' ) . '</label></th><td>';
		echo '<input type="text" id="oblt_seller_country" class="small-text" maxlength="2" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[seller_country]" value="' . esc_attr( $s['seller_country'] ?? '' ) . '" placeholder="' . esc_attr( strtoupper( substr( (string) get_option( 'woocommerce_default_country', '' ), 0, 2 ) ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'For e-invoicing. Blank = WooCommerce store country.', 'orderbay-lite' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'PDF invoices in emails', 'orderbay-lite' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Attach PDF invoice to emails', 'orderbay-lite' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[email_attach_on]" value="1"' . checked( OBLT_Pdf::attach_enabled( $s ), true, false ) . ' /> ';
		echo esc_html__( 'Attach the invoice PDF to the WooCommerce emails ticked below', 'orderbay-lite' ) . '</label>';
		$labels   = array(
			'customer_processing_order' => __( 'Processing order (customer)', 'orderbay-lite' ),
			'customer_completed_order'  => __( 'Completed order (customer)', 'orderbay-lite' ),
			'customer_invoice'          => __( 'Customer invoice / Order details (customer)', 'orderbay-lite' ),
			'customer_refunded_order'   => __( 'Refunded order (customer): attaches the credit note PDF when the order has a credit note number', 'orderbay-lite' ),
			'new_order'                 => __( 'New order (admin)', 'orderbay-lite' ),
		);
		$selected = OBLT_Pdf::selected_emails( $s );
		echo '<fieldset style="margin-top:8px;">';
		foreach ( OBLT_Pdf::OFFERED_EMAILS as $email_id ) {
			echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="' . esc_attr( OBLT_Plugin::OPT_DOCS ) . '[email_attach][]" value="' . esc_attr( $email_id ) . '"' . checked( in_array( $email_id, $selected, true ), true, false ) . ' /> ' . esc_html( $labels[ $email_id ] ?? $email_id ) . '</label>';
		}
		echo '</fieldset>';
		echo '<p class="description">';
		if ( $eng ) {
			echo esc_html__( 'The invoice number is assigned when the first PDF is attached, if the order does not have one yet. If a PDF cannot be made, the email is still sent without it and the reason is logged under WooCommerce → Status → Logs (source: orderbay-lite).', 'orderbay-lite' );
		} else {
			echo esc_html__( 'PDFs need the PHP mbstring and dom extensions, which this server does not have. Emails are sent without a PDF until they are enabled.', 'orderbay-lite' );
		}
		echo '</p></td></tr>';
		echo '</table>';

		if ( class_exists( 'OBLT_EInvoice' ) ) {
			echo '<h2>' . esc_html__( 'E-invoicing (UBL / Factur-X)', 'orderbay-lite' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Download UBL (Peppol BIS 3.0) or CII (Factur-X EN16931) invoice XML from the order screen. Export only — OrderBay Lite does not connect to the Peppol network or any PDP/access point. EN16931 baseline: validate against an official validator before relying on it. A "Factur-X PDF" button also appears when the horstoeko/zugferd library is installed on the host (OrderBay Lite does not bundle it); its output must pass a Factur-X validator.', 'orderbay-lite' ) . '</p>';
			$seller_issues = OBLT_EInvoice::seller_issues( OBLT_EInvoice::seller_data() );
			if ( $seller_issues ) {
				echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Seller details needed for compliant e-invoices:', 'orderbay-lite' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
				foreach ( $seller_issues as $iss ) {
					echo '<li>' . esc_html( $iss ) . '</li>';
				}
				echo '</ul></div>';
			} else {
				echo '<p style="color:#1a7f37;">' . esc_html__( 'Seller details look complete for e-invoicing.', 'orderbay-lite' ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Invoice & credit note numbers', 'orderbay-lite' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Format tokens: {PREFIX} {YYYY} {YY} {MM} {DD} {SEQ} {SEQ:5} (zero-padded). Default {PREFIX}{SEQ} keeps existing numbers. A sequence token is always enforced. Yearly/monthly reset restarts the counter at 1 each period.', 'orderbay-lite' ) . '</p>';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Invoice prefix', 'orderbay-lite' ) . '</th><td><input type="text" name="oblt_invoice_prefix" value="' . esc_attr( get_option( OBLT_Plugin::OPT_INVOICE_PREFIX, 'INV-' ) ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Next invoice #', 'orderbay-lite' ) . '</th><td><input type="number" min="1" name="oblt_invoice_next" value="' . esc_attr( (string) max( 1, (int) get_option( OBLT_Plugin::OPT_INVOICE_NEXT, 1 ) ) ) . '" /></td></tr>';
		$this->numbering_format_rows( __( 'Invoice', 'orderbay-lite' ), OBLT_Plugin::OPT_INVOICE_FORMAT, OBLT_Plugin::OPT_INVOICE_RESET );
		echo '<tr><th>' . esc_html__( 'Credit note prefix', 'orderbay-lite' ) . '</th><td><input type="text" name="oblt_credit_prefix" value="' . esc_attr( get_option( OBLT_Plugin::OPT_CREDIT_PREFIX, 'CN-' ) ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Next credit #', 'orderbay-lite' ) . '</th><td><input type="number" min="1" name="oblt_credit_next" value="' . esc_attr( (string) max( 1, (int) get_option( OBLT_Plugin::OPT_CREDIT_NEXT, 1 ) ) ) . '" /></td></tr>';
		$this->numbering_format_rows( __( 'Credit note', 'orderbay-lite' ), OBLT_Plugin::OPT_CREDIT_FORMAT, OBLT_Plugin::OPT_CREDIT_RESET );
		echo '</table>';

		OBLT_Withdrawal::render_settings_fields();

		submit_button( __( 'Save invoice settings', 'orderbay-lite' ) );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Template customization', 'orderbay-lite' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Override any document template without editing the plugin: copy a file from the plugin\'s templates/ folder into your theme at wp-content/themes/your-theme/orderbay-lite/ (e.g. orderbay-lite/invoice.php). Your copy survives plugin updates. The oblt_before_document / oblt_after_document actions and the oblt_locate_template filter are also available.', 'orderbay-lite' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the format-template + reset-period rows for one document type.
	 *
	 * @param string $label      Human label for the document type.
	 * @param string $opt_format Format option key.
	 * @param string $opt_reset  Reset-period option key.
	 */
	private function numbering_format_rows( $label, $opt_format, $opt_reset ) {
		$format = (string) get_option( $opt_format, '{PREFIX}{SEQ}' );
		if ( '' === $format ) {
			$format = '{PREFIX}{SEQ}';
		}
		$reset = (string) get_option( $opt_reset, 'none' );

		echo '<tr><th>' . esc_html( sprintf( /* translators: %s: document type. */ __( '%s number format', 'orderbay-lite' ), $label ) ) . '</th><td>';
		echo '<input type="text" name="' . esc_attr( $opt_format ) . '" value="' . esc_attr( $format ) . '" class="regular-text" /></td></tr>';

		echo '<tr><th>' . esc_html( sprintf( /* translators: %s: document type. */ __( '%s counter reset', 'orderbay-lite' ), $label ) ) . '</th><td>';
		echo '<select name="' . esc_attr( $opt_reset ) . '">';
		$options = array(
			'none'    => __( 'Never (continuous)', 'orderbay-lite' ),
			'yearly'  => __( 'Yearly (restart at 1 each year)', 'orderbay-lite' ),
			'monthly' => __( 'Monthly (restart at 1 each month)', 'orderbay-lite' ),
		);
		foreach ( $options as $val => $text ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $reset, $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/**
	 * Normalize WooCommerce tax totals into simple per-rate rows for document rendering (pure).
	 * Accepts the array returned by WC_Order::get_tax_totals() (objects with ->label/->amount)
	 * or equivalent arrays, so it can be unit-tested without a WooCommerce runtime.
	 *
	 * @param mixed $tax_totals Iterable of tax totals.
	 * @return array<int,array{label:string,amount:float}>
	 */
	public static function normalize_tax_rows( $tax_totals ) {
		$rows = array();
		if ( ! is_array( $tax_totals ) ) {
			return $rows;
		}
		foreach ( $tax_totals as $t ) {
			if ( is_object( $t ) ) {
				$label  = isset( $t->label ) ? (string) $t->label : '';
				$amount = isset( $t->amount ) ? (float) $t->amount : 0.0;
			} elseif ( is_array( $t ) ) {
				$label  = isset( $t['label'] ) ? (string) $t['label'] : '';
				$amount = isset( $t['amount'] ) ? (float) $t['amount'] : 0.0;
			} else {
				continue;
			}
			if ( '' === $label ) {
				$label = __( 'Tax', 'orderbay-lite' );
			}
			$rows[] = array(
				'label'  => $label,
				'amount' => $amount,
			);
		}
		return $rows;
	}

	/**
	 * Ordered theme→plugin candidate paths for a document template (pure).
	 * A store can override any template by copying it into `wp-content/themes/<theme>/orderbay-lite/`.
	 *
	 * @param string $name           Template filename (e.g. 'invoice.php').
	 * @param string $stylesheet_dir Active (child) theme directory.
	 * @param string $template_dir   Parent theme directory.
	 * @param string $plugin_dir     Plugin templates directory.
	 * @return string[]
	 */
	public static function template_candidates( $name, $stylesheet_dir, $template_dir, $plugin_dir ) {
		$name   = basename( (string) $name );
		$subdir = 'orderbay-lite/';
		$out    = array();
		if ( $stylesheet_dir ) {
			$out[] = rtrim( $stylesheet_dir, '/' ) . '/' . $subdir . $name;
		}
		if ( $template_dir && rtrim( $template_dir, '/' ) !== rtrim( (string) $stylesheet_dir, '/' ) ) {
			$out[] = rtrim( $template_dir, '/' ) . '/' . $subdir . $name;
		}
		$out[] = rtrim( $plugin_dir, '/' ) . '/' . $name;
		return $out;
	}

	/**
	 * Resolve a document template path, preferring a theme override, then the plugin default.
	 * The final path is filterable via `oblt_locate_template`.
	 *
	 * @param string $name Template filename (basename only; traversal is stripped).
	 * @return string Absolute path.
	 */
	public static function locate_template( $name ) {
		$name       = basename( (string) $name );
		$candidates = self::template_candidates(
			$name,
			function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
			function_exists( 'get_template_directory' ) ? get_template_directory() : '',
			rtrim( OBLT_PLUGIN_DIR, '/' ) . '/templates'
		);
		$found = '';
		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				$found = $path;
				break;
			}
		}
		if ( '' === $found ) {
			$found = rtrim( OBLT_PLUGIN_DIR, '/' ) . '/templates/' . $name;
		}
		/**
		 * Filter the resolved OrderBay Lite document template path.
		 *
		 * @param string $found Absolute path.
		 * @param string $name  Template filename.
		 */
		return apply_filters( 'oblt_locate_template', $found, $name );
	}

	public function order_actions( $actions ) {
		$actions['oblt_print_invoice'] = __( 'OrderBay Lite: print invoice', 'orderbay-lite' );
		return $actions;
	}

	public function action_print_invoice( $order ) {
		/* translators: %s: invoice print URL. */
		$order->add_order_note( sprintf( __( 'OrderBay Lite invoice: %s', 'orderbay-lite' ), $this->print_url( $order->get_id() ) ), false, true );
	}

	public function order_screen_buttons( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$inv_no = $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER );
		if ( $inv_no ) {
			echo '<p class="form-field" style="clear:both;padding-left:0;"><strong>' . esc_html__( 'Invoice #', 'orderbay-lite' ) . ':</strong> ' . esc_html( $inv_no ) . '</p>';
		}
		echo '<p class="form-field" style="clear:both;padding-left:0;">';
		echo '<a class="button button-primary" target="_blank" href="' . esc_url( $this->print_url( $order->get_id() ) ) . '" style="margin:0 4px 4px 0;">' . esc_html__( 'Invoice', 'orderbay-lite' ) . '</a> ';
		echo '<a class="button" target="_blank" href="' . esc_url( $this->packing_slip_url( $order->get_id() ) ) . '" style="margin:0 4px 4px 0;">' . esc_html__( 'Packing slip', 'orderbay-lite' ) . '</a> ';
		if ( self::detect_pdf_engine() ) {
			echo '<a class="button" href="' . esc_url( $this->pdf_url( $order->get_id() ) ) . '" style="margin:0 4px 4px 0;">' . esc_html__( 'Download PDF', 'orderbay-lite' ) . '</a> ';
		}
		if ( class_exists( 'OBLT_EInvoice' ) ) {
			echo '<a class="button" href="' . esc_url( OBLT_EInvoice::download_url( $order->get_id(), 'ubl' ) ) . '">' . esc_html__( 'E-invoice UBL', 'orderbay-lite' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( OBLT_EInvoice::download_url( $order->get_id(), 'cii' ) ) . '">' . esc_html__( 'E-invoice CII (Factur-X)', 'orderbay-lite' ) . '</a> ';
			if ( OBLT_EInvoice::facturx_available() ) {
				echo '<a class="button" href="' . esc_url( OBLT_EInvoice::facturx_url( $order->get_id() ) ) . '">' . esc_html__( 'Factur-X PDF', 'orderbay-lite' ) . '</a> ';
			}
		}
		if ( class_exists( 'OBLT_EInvoice' ) ) {
			$einv_issues = OBLT_EInvoice::compliance_issues( OBLT_EInvoice::order_to_invoice_data( $order ) );
			if ( $einv_issues ) {
				echo '<span class="description" style="display:block;margin-top:4px;color:#b32d2e;">';
				echo esc_html__( 'E-invoice XML is a draft — not fully compliant yet:', 'orderbay-lite' ) . ' ' . esc_html( implode( ' ', $einv_issues ) );
				echo '</span>';
			}
		}
		echo '</p>';
	}

	public function list_row_actions( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		printf(
			'<a class="button wc-action-button" href="%s" target="_blank" title="%s">%s</a> ',
			esc_url( $this->print_url( $order->get_id() ) ),
			esc_attr__( 'Invoice', 'orderbay-lite' ),
			esc_html__( 'Inv', 'orderbay-lite' )
		);
		printf(
			'<a class="button wc-action-button" href="%s" target="_blank" title="%s">%s</a> ',
			esc_url( $this->packing_slip_url( $order->get_id() ) ),
			esc_attr__( 'Packing slip', 'orderbay-lite' ),
			esc_html__( 'Slip', 'orderbay-lite' )
		);
		if ( self::detect_pdf_engine() ) {
			printf(
				'<a class="button wc-action-button" href="%s" title="%s">%s</a> ',
				esc_url( $this->pdf_url( $order->get_id() ) ),
				esc_attr__( 'Download PDF invoice', 'orderbay-lite' ),
				esc_html__( 'PDF', 'orderbay-lite' )
			);
		}
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public function print_url( $order_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=oblt_print_invoice&order_id=' . absint( $order_id ) ),
			'oblt_print_invoice_' . absint( $order_id )
		);
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public function packing_slip_url( $order_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=oblt_print_packing_slip&order_id=' . absint( $order_id ) ),
			'oblt_print_packing_slip_' . absint( $order_id )
		);
	}

	public function pdf_url( $order_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=oblt_pdf_download&order_id=' . absint( $order_id ) ),
			'oblt_pdf_' . absint( $order_id )
		);
	}

	public function handle_print_invoice() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'oblt_print_invoice_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay-lite' ) );
		}
		$settings = OBLT_Plugin::get_doc_settings();
		if ( class_exists( 'OBLT_Invoicing' ) ) {
			$number = OBLT_Invoicing::ensure_invoice_number( $order );
			if ( '' === (string) $number ) {
				wp_die( esc_html__( 'An invoice number could not be allocated. The document was not rendered.', 'orderbay-lite' ) );
			}
		}
		$orders = array( $order );
		/**
		 * Fires before an OrderBay Lite document renders (extension point for headers/logos).
		 *
		 * @param string     $type   Document type.
		 * @param WC_Order[] $orders Orders being rendered.
		 */
		do_action( 'oblt_before_document', 'invoice', $orders );
		include self::locate_template( 'invoice.php' );
		do_action( 'oblt_after_document', 'invoice', $orders );
		exit;
	}

	/**
	 * Print view for a packing slip. Unlike the invoice it never allocates an invoice number.
	 */
	public function handle_print_packing_slip() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'oblt_print_packing_slip_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay-lite' ) );
		}
		$settings = OBLT_Plugin::get_doc_settings();
		$orders   = array( $order );
		/** This action is documented in includes/class-oblt-documents.php */
		do_action( 'oblt_before_document', 'packing-slip', $orders );
		include self::locate_template( 'packing-slip.php' );
		do_action( 'oblt_after_document', 'packing-slip', $orders );
		exit;
	}

	/**
	 * Invoice PDF bytes (assigns the invoice number first). Returns '' on any failure.
	 * Reused by the Factur-X assembler.
	 *
	 * @param WC_Order $order Order.
	 * @return string PDF bytes or ''.
	 */
	public static function render_pdf_bytes( $order ) {
		try {
			return OBLT_Pdf::invoice_bytes( $order );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Staff PDF download (edit_shop_orders + per-order nonce).
	 */
	public function handle_pdf_download() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ), 403 );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'oblt_pdf_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay-lite' ) );
		}
		try {
			$bytes = OBLT_Pdf::invoice_bytes( $order );
		} catch ( Throwable $e ) {
			wp_die( esc_html__( 'The PDF could not be made:', 'orderbay-lite' ) . ' ' . esc_html( $e->getMessage() ) );
		}
		OBLT_Pdf::stream( $bytes, OBLT_Pdf::filename( 'invoice', (string) $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ) ) );
	}
}

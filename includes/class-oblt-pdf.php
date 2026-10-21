<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PDF invoices: the bundled Dompdf engine, PDF download, and invoice / credit note
 * PDFs attached to WooCommerce emails.
 *
 * - Engine: Dompdf bundled under lib/vendor/oblt-dompdf, loaded only when \Dompdf\Dompdf is not
 *   already available (the paid OrderBay plugin or another plugin may have loaded it).
 *   Remote fetching, PHP and JavaScript are off and the chroot is the plugin folder; a
 *   logo stored on this site is embedded from disk, so nothing is fetched over HTTP.
 * - Emails: on woocommerce_email_attachments, the emails ticked in settings get the
 *   invoice PDF (refunded-order emails get the credit note PDF when the order already has
 *   a credit note number). The invoice number is assigned at that moment through
 *   OBLT_Invoicing::ensure_invoice_number(), so it is assigned once.
 * - Temp files: written to uploads/orderbay-lite-tmp/<random>/ (index.php + deny-all
 *   .htaccess at the top), deleted after wp_mail succeeds or fails, again on shutdown, and
 *   by an hourly sweep of anything older than an hour.
 * - A PDF failure never blocks the email: it is sent without the attachment and the
 *   error is logged with wc_get_logger() (source: orderbay-lite).
 */
class OBLT_Pdf {

	/** Customer emails that get the invoice PDF out of the box. */
	const DEFAULT_EMAILS = array( 'customer_completed_order', 'customer_invoice' );

	/** Emails offered in settings. new_order is the admin email and is never ticked by default. */
	const OFFERED_EMAILS = array( 'customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_refunded_order', 'new_order' );

	/** WooCommerce sends partial refunds under their own email id. */
	const REFUND_EMAILS = array( 'customer_refunded_order', 'customer_partially_refunded_order' );

	/** Folder under uploads for temp PDFs and the font cache. */
	const TMP_DIR = 'orderbay-lite-tmp';

	/** Hourly sweep of temp PDFs left behind. */
	const CRON_HOOK = 'oblt_pdf_tmp_sweep';

	/** Temp PDFs older than this are swept (seconds). */
	const TMP_MAX_AGE = 3600;

	/** Largest local image embedded in a PDF (bytes). */
	const MAX_IMAGE_BYTES = 2097152;

	/**
	 * Test seam: replaces the Dompdf render. Receives ( $order, $doc ) and returns bytes
	 * or throws.
	 *
	 * @var callable|null
	 */
	public static $test_render = null;

	/**
	 * Test seam: receives ( $bytes, $filename ) instead of headers + echo + exit.
	 *
	 * @var callable|null
	 */
	public static $test_stream = null;

	/** @var OBLT_Pdf|null */
	private static $instance = null;

	/** @var string[] Temp PDFs written by this request, not yet deleted. */
	private $pending = array();

	/**
	 * @return OBLT_Pdf
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach' ), 10, 4 );
		add_action( 'wp_mail_succeeded', array( $this, 'after_mail_sent' ) );
		add_action( 'wp_mail_failed', array( $this, 'after_mail_failed' ) );
		// Late, so WooCommerce's deferred emails (sent on shutdown) go out first.
		add_action( 'shutdown', array( $this, 'cleanup' ), PHP_INT_MAX );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'init', array( __CLASS__, 'schedule_sweep' ) );
	}

	/* ─── Settings (pure) ────────────────────────────────────────── */

	/**
	 * Whether "Attach PDF invoice to emails" is on. Pure. Missing key = on (default).
	 *
	 * @param array $s Document settings.
	 * @return bool
	 */
	public static function attach_enabled( $s ) {
		return '0' !== (string) ( is_array( $s ) && isset( $s['email_attach_on'] ) ? $s['email_attach_on'] : '1' );
	}

	/**
	 * Email ids ticked in settings, limited to the offered list. Pure.
	 *
	 * @param array $s Document settings.
	 * @return string[]
	 */
	public static function selected_emails( $s ) {
		if ( ! is_array( $s ) || ! isset( $s['email_attach'] ) ) {
			return self::DEFAULT_EMAILS;
		}
		$list = is_array( $s['email_attach'] ) ? $s['email_attach'] : array();
		return array_values( array_intersect( self::OFFERED_EMAILS, $list ) );
	}

	/**
	 * Which document an email carries: 'invoice', 'credit-note' or '' (none). Pure.
	 *
	 * @param string $email_id WooCommerce email id.
	 * @param array  $s        Document settings.
	 * @return string
	 */
	public static function document_for_email( $email_id, $s ) {
		if ( ! self::attach_enabled( $s ) ) {
			return '';
		}
		$email_id = (string) $email_id;
		$selected = self::selected_emails( $s );
		if ( in_array( $email_id, self::REFUND_EMAILS, true ) ) {
			return in_array( 'customer_refunded_order', $selected, true ) ? 'credit-note' : '';
		}
		return in_array( $email_id, $selected, true ) ? 'invoice' : '';
	}

	/**
	 * Sanitize the email settings from a posted form into $out. Pure.
	 *
	 * @param array $input Raw input.
	 * @param array $out   Settings being built.
	 * @return array
	 */
	public static function sanitize_email_settings( $input, $out ) {
		$out['email_attach_on'] = ! empty( $input['email_attach_on'] ) ? '1' : '0';
		$list                   = isset( $input['email_attach'] ) && is_array( $input['email_attach'] ) ? array_map( 'sanitize_key', $input['email_attach'] ) : array();
		$out['email_attach']    = array_values( array_intersect( self::OFFERED_EMAILS, $list ) );
		return $out;
	}

	/**
	 * Attachment filename for a document number. Pure.
	 *
	 * @param string $doc    'invoice' | 'credit-note'.
	 * @param string $number Document number.
	 * @return string
	 */
	public static function filename( $doc, $number ) {
		$n = preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $number );
		$n = trim( (string) $n, '-.' );
		return ( 'credit-note' === $doc ? 'credit-note' : 'invoice' ) . '-' . ( '' !== $n ? $n : 'order' ) . '.pdf';
	}

	/* ─── Email attachments ──────────────────────────────────────── */

	/**
	 * woocommerce_email_attachments filter.
	 *
	 * @param array  $attachments Attachment paths.
	 * @param string $email_id    WooCommerce email id.
	 * @param mixed  $object      Usually a WC_Order.
	 * @param mixed  $email       WC_Email (WC 5.6+).
	 * @return array
	 */
	public function attach( $attachments, $email_id = '', $object = null, $email = null ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = array();
		}
		if ( ! $object instanceof WC_Order || ( class_exists( 'WC_Order_Refund', false ) && $object instanceof WC_Order_Refund ) ) {
			return $attachments;
		}
		// Unsaved orders (e.g. WooCommerce's email preview) never get a number or a PDF.
		if ( ! $object->get_id() ) {
			return $attachments;
		}
		$doc = self::document_for_email( $email_id, OBLT_Plugin::get_doc_settings() );
		if ( '' === $doc ) {
			return $attachments;
		}
		try {
			$path = 'credit-note' === $doc ? $this->credit_note_file( $object ) : $this->invoice_file( $object );
			if ( '' !== $path ) {
				$attachments[] = $path;
			}
		} catch ( Throwable $e ) {
			// Never block an order email because a PDF failed.
			self::log(
				'error',
				sprintf( 'Order #%1$s: the %2$s PDF was not attached to the "%3$s" email: %4$s', $object->get_id(), $doc, $email_id, $e->getMessage() )
			);
		}
		return $attachments;
	}

	/**
	 * Assign the invoice number (once) and write the invoice PDF to a temp file.
	 *
	 * @param WC_Order $order Order.
	 * @return string Temp path or ''.
	 */
	private function invoice_file( $order ) {
		$number = OBLT_Invoicing::ensure_invoice_number( $order );
		if ( '' === (string) $number ) {
			self::log( 'error', sprintf( 'Order #%s: no invoice number could be allocated, so the invoice PDF was not attached.', $order->get_id() ) );
			return '';
		}
		$s = OBLT_Plugin::get_doc_settings();
		if ( '1' === (string) ( $s['einv_email_facturx'] ?? '0' ) && class_exists( 'OBLT_EInvoice' ) && OBLT_EInvoice::facturx_available() ) {
			try {
				return $this->write_temp( OBLT_EInvoice::facturx_bytes( $order ), self::filename( 'invoice', $number ) );
			} catch ( Throwable $e ) {
				self::log( 'warning', sprintf( 'Order #%1$s: the Factur-X PDF failed, so the plain invoice PDF was attached: %2$s', $order->get_id(), $e->getMessage() ) );
			}
		}
		return $this->write_temp( self::render( $order, 'invoice' ), self::filename( 'invoice', $number ) );
	}

	/**
	 * Credit note PDF, only when the order already has a credit note number. Lite never
	 * issues a credit note number just because an email is going out.
	 *
	 * @param WC_Order $order Order.
	 * @return string Temp path or ''.
	 */
	private function credit_note_file( $order ) {
		if ( '' === (string) $order->get_meta( OBLT_Plugin::META_CREDIT_NUMBER ) && '' === (string) $order->get_meta( '_ob_credit_note_number' ) ) {
			return '';
		}
		// Adopts the existing number; allocates nothing.
		$number = OBLT_Invoicing::ensure_credit_number( $order );
		if ( '' === (string) $number ) {
			return '';
		}
		return $this->write_temp( self::render( $order, 'credit-note' ), self::filename( 'credit-note', $number ) );
	}

	/* ─── Rendering ──────────────────────────────────────────────── */

	/**
	 * Whether a PDF engine is usable here (without loading it).
	 *
	 * @return bool
	 */
	public static function engine_available() {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			return true;
		}
		return extension_loaded( 'mbstring' ) && extension_loaded( 'dom' ) && is_readable( self::bundled_autoload() );
	}

	/**
	 * @return string
	 */
	private static function bundled_autoload() {
		return OBLT_PLUGIN_DIR . 'lib/vendor/oblt-dompdf/autoload.php';
	}

	/**
	 * Load the bundled Dompdf unless a Dompdf is already available. Safe to call repeatedly.
	 *
	 * @return bool True when \Dompdf\Dompdf can be used.
	 */
	public static function load_engine() {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			return true;
		}
		if ( ! extension_loaded( 'mbstring' ) || ! extension_loaded( 'dom' ) ) {
			return false;
		}
		$auto = self::bundled_autoload();
		if ( ! is_readable( $auto ) ) {
			return false;
		}
		require_once $auto;
		return class_exists( '\\Dompdf\\Dompdf' );
	}

	/**
	 * Invoice PDF bytes for downloads: assigns the invoice number first. Throws on failure.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 * @throws RuntimeException When no number can be allocated or rendering fails.
	 */
	public static function invoice_bytes( $order ) {
		$number = OBLT_Invoicing::ensure_invoice_number( $order );
		if ( '' === (string) $number ) {
			throw new RuntimeException( 'An invoice number could not be allocated.' );
		}
		return self::render( $order, 'invoice' );
	}

	/**
	 * Render a document template to PDF bytes. Throws on any failure.
	 *
	 * With $facturx (keys xml, profile) the PDF is written as PDF/A-3b with the XML embedded
	 * (Factur-X / ZUGFeRD).
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $doc     'invoice' | 'credit-note'.
	 * @param array    $facturx Optional Factur-X data: xml, profile.
	 * @return string
	 * @throws RuntimeException When rendering fails.
	 */
	public static function render( $order, $doc, $facturx = array() ) {
		if ( is_callable( self::$test_render ) ) {
			$bytes = (string) call_user_func( self::$test_render, $order, $doc, $facturx );
		} else {
			if ( ! self::load_engine() ) {
				throw new RuntimeException( 'No PDF engine is available (needs the PHP mbstring and dom extensions).' );
			}
			if ( ! empty( $facturx['xml'] ) && ! OBLT_FacturX::engine_ready() ) {
				throw new RuntimeException( 'The PDF engine on this site cannot write PDF/A-3 files.' );
			}
			$settings = OBLT_Plugin::get_doc_settings();
			$bytes    = self::html_to_pdf( self::document_html( $order, $doc, $settings ), $settings, $facturx );
		}
		if ( 0 !== strpos( $bytes, '%PDF' ) ) {
			throw new RuntimeException( 'The PDF engine returned no PDF.' );
		}
		return $bytes;
	}

	/**
	 * The document template's HTML as rendered for the PDF path.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $doc      'invoice' | 'credit-note'.
	 * @param array    $settings Document settings (read by the template).
	 * @return string
	 */
	public static function document_html( $order, $doc, $settings ) {
		$orders        = array( $order );
		$oblt_ob_level = ob_get_level();
		OBLT_Documents::$pdf_mode = true;
		ob_start();
		try {
			include OBLT_Documents::locate_template( 'credit-note' === $doc ? 'credit-note.php' : 'invoice.php' );
			$html = (string) ob_get_clean();
		} finally {
			OBLT_Documents::$pdf_mode = false;
			while ( ob_get_level() > $oblt_ob_level ) {
				ob_end_clean();
			}
		}
		return $html;
	}

	/**
	 * HTML to PDF bytes with Dompdf (locked-down options).
	 *
	 * @param string $html     Document HTML.
	 * @param array  $settings Document settings (paper).
	 * @param array  $facturx  Optional Factur-X data: xml, profile (PDF/A-3b with the XML).
	 * @return string
	 */
	public static function html_to_pdf( $html, $settings, $facturx = array() ) {
		$html  = preg_replace( '/<p class="no-print">.*?<\/p>/s', '', (string) $html );
		$html  = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
		$html  = self::inline_local_images( (string) $html );
		$paper = ( 'a4' === ( $settings['paper'] ?? 'letter' ) ) ? 'a4' : 'letter';
		if ( empty( $facturx['xml'] ) ) {
			$dompdf = new \Dompdf\Dompdf( self::dompdf_options() );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( $paper );
			$dompdf->render();
			return (string) $dompdf->output();
		}
		$options                  = self::dompdf_options();
		$options['isPdfAEnabled'] = true;
		$html                     = OBLT_FacturX::pdfa_html( $html );
		try {
			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( $paper );
			$dompdf->render();
		} catch ( Throwable $e ) {
			// PDF/A refuses some images (CMYK JPEG): try once more without images.
			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( OBLT_FacturX::strip_images( $html ), 'UTF-8' );
			$dompdf->setPaper( $paper );
			$dompdf->render();
		}
		return OBLT_FacturX::finish( $dompdf, (string) $facturx['xml'], (string) ( $facturx['profile'] ?? 'en16931' ) );
	}

	/**
	 * Dompdf options: no remote fetches, no PHP, no JavaScript, chroot = this plugin.
	 *
	 * @return array
	 */
	public static function dompdf_options() {
		$tmp   = self::private_dir();
		$fonts = '' !== $tmp ? $tmp . '/fonts' : '';
		if ( '' !== $fonts && ! is_dir( $fonts ) ) {
			wp_mkdir_p( $fonts );
		}
		$opts = array(
			'isRemoteEnabled'     => false,
			'isPhpEnabled'        => false,
			'isJavascriptEnabled' => false,
			'defaultFont'         => 'DejaVu Sans',
			'chroot'              => array( rtrim( OBLT_PLUGIN_DIR, '/\\' ) ),
		);
		if ( '' !== $fonts && is_dir( $fonts ) && wp_is_writable( $fonts ) ) {
			$opts['fontCache'] = $fonts;
			$opts['tempDir']   = $tmp;
		}
		return $opts;
	}

	/**
	 * Embed images that live on this site as data URIs (remote fetching is off) and drop
	 * any image that is not a local file, so the PDF never shows a broken-image box.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function inline_local_images( $html ) {
		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) {
				if ( ! preg_match( '/\bsrc\s*=\s*"([^"]*)"/i', $m[0], $src ) ) {
					return '';
				}
				$url = html_entity_decode( $src[1], ENT_QUOTES, 'UTF-8' );
				if ( 0 === strpos( $url, 'data:image/' ) ) {
					return $m[0];
				}
				$data = self::local_image_data_uri( $url );
				return '' === $data ? '' : str_replace( $src[0], 'src="' . $data . '"', $m[0] );
			},
			(string) $html
		);
	}

	/**
	 * Map an uploads / wp-content URL to a readable image file on disk, as a data URI.
	 *
	 * @param string $url Image URL.
	 * @return string Data URI or ''.
	 */
	public static function local_image_data_uri( $url ) {
		$url   = preg_replace( '/[?#].*$/', '', (string) $url );
		$bases = array();
		if ( function_exists( 'wp_upload_dir' ) ) {
			$up = wp_upload_dir( null, false );
			if ( ! empty( $up['baseurl'] ) && ! empty( $up['basedir'] ) ) {
				$bases[ $up['baseurl'] ] = $up['basedir'];
			}
		}
		if ( function_exists( 'content_url' ) && defined( 'WP_CONTENT_DIR' ) ) {
			$bases[ content_url() ] = WP_CONTENT_DIR;
		}
		$types = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
		);
		foreach ( $bases as $base_url => $base_dir ) {
			foreach ( array( $base_url, preg_replace( '#^https?:#i', '', $base_url ) ) as $prefix ) {
				$prefix = rtrim( (string) $prefix, '/' ) . '/';
				if ( '/' === $prefix || 0 !== stripos( $url, $prefix ) ) {
					continue;
				}
				$root = realpath( $base_dir );
				$file = realpath( rtrim( $base_dir, '/\\' ) . '/' . rawurldecode( substr( $url, strlen( $prefix ) ) ) );
				$ext  = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
				if ( ! $root || ! $file || 0 !== strpos( $file, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) || ! isset( $types[ $ext ] ) || ! is_file( $file ) || filesize( $file ) > self::MAX_IMAGE_BYTES ) {
					return '';
				}
				$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
				return false === $bytes ? '' : 'data:' . $types[ $ext ] . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image data URI.
			}
		}
		return '';
	}

	/* ─── Download ───────────────────────────────────────────────── */

	/**
	 * Send PDF bytes as a download and end the request.
	 *
	 * @param string $bytes    PDF bytes.
	 * @param string $filename Download filename.
	 */
	public static function stream( $bytes, $filename ) {
		$filename = preg_replace( '/[^A-Za-z0-9._-]+/', '-', basename( (string) $filename ) );
		if ( is_callable( self::$test_stream ) ) {
			call_user_func( self::$test_stream, $bytes, $filename );
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/* ─── Temp files ─────────────────────────────────────────────── */

	/**
	 * Private folder under uploads, created with index.php and a deny-all .htaccess.
	 *
	 * @return string Absolute path without trailing slash, or '' when uploads is unavailable.
	 */
	public static function private_dir() {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$up = wp_upload_dir( null, false );
		if ( empty( $up['basedir'] ) ) {
			return '';
		}
		$dir = rtrim( $up['basedir'], '/\\' ) . '/' . self::TMP_DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);
		foreach ( $guards as $name => $body ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $dir;
	}

	/**
	 * Write PDF bytes to <private dir>/<random>/<filename>.
	 *
	 * @param string $bytes PDF bytes.
	 * @param string $name  Attachment filename.
	 * @return string Path or ''.
	 * @throws RuntimeException When the file cannot be written.
	 */
	private function write_temp( $bytes, $name ) {
		$dir = self::private_dir();
		if ( '' === $dir ) {
			throw new RuntimeException( 'The uploads folder is not available for the temporary PDF.' );
		}
		$sub = $dir . '/' . bin2hex( random_bytes( 16 ) );
		if ( ! wp_mkdir_p( $sub ) ) {
			throw new RuntimeException( 'Could not create a temporary folder for the PDF.' );
		}
		$path = $sub . '/' . basename( $name );
		if ( false === file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@rmdir( $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			throw new RuntimeException( 'Could not write the temporary PDF.' );
		}
		$this->pending[] = $path;
		return $path;
	}

	/**
	 * Temp PDFs this request still holds (tests and diagnostics).
	 *
	 * @return string[]
	 */
	public function pending_files() {
		return $this->pending;
	}

	/**
	 * wp_mail_succeeded: delete our temp PDFs that this email carried.
	 *
	 * @param array $mail_data to, subject, message, headers, attachments.
	 */
	public function after_mail_sent( $mail_data ) {
		$this->release( is_array( $mail_data ) && isset( $mail_data['attachments'] ) ? $mail_data['attachments'] : array() );
	}

	/**
	 * wp_mail_failed: same, the error data carries the mail data.
	 *
	 * @param WP_Error $error Error.
	 */
	public function after_mail_failed( $error ) {
		$data = is_object( $error ) && method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : array();
		$this->release( is_array( $data ) && isset( $data['attachments'] ) ? $data['attachments'] : array() );
	}

	/**
	 * Delete the given attachments that are ours.
	 *
	 * @param array|string $attachments Paths.
	 */
	private function release( $attachments ) {
		if ( is_string( $attachments ) ) {
			$attachments = preg_split( '/[\r\n]+/', $attachments );
		}
		foreach ( (array) $attachments as $path ) {
			$i = array_search( $path, $this->pending, true );
			if ( false !== $i ) {
				self::delete_temp( $path );
				unset( $this->pending[ $i ] );
			}
		}
		$this->pending = array_values( $this->pending );
	}

	/**
	 * Shutdown: delete every temp PDF this request wrote.
	 */
	public function cleanup() {
		foreach ( $this->pending as $path ) {
			self::delete_temp( $path );
		}
		$this->pending = array();
	}

	/**
	 * Delete one temp PDF and its random folder, only inside the private folder.
	 *
	 * @param string $path File path.
	 */
	private static function delete_temp( $path ) {
		$dir  = self::private_dir();
		$path = (string) $path;
		if ( '' === $dir || 0 !== strpos( $path, $dir . '/' ) || false !== strpos( $path, '..' ) ) {
			return;
		}
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		$parent = dirname( $path );
		if ( $parent !== $dir && is_dir( $parent ) ) {
			@rmdir( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	/**
	 * init: make sure the hourly sweep is scheduled.
	 */
	public static function schedule_sweep() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Hourly: delete temp PDFs (and their folders) older than an hour. The font cache,
	 * index.php and .htaccess stay.
	 *
	 * @param int|null $now Current time (tests).
	 * @return int Files deleted.
	 */
	public static function sweep( $now = null ) {
		$dir = self::private_dir();
		if ( '' === $dir ) {
			return 0;
		}
		$now     = null === $now ? time() : (int) $now;
		$deleted = 0;
		foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $sub ) {
			if ( ! is_string( $sub ) || 'fonts' === basename( $sub ) ) {
				continue;
			}
			foreach ( (array) glob( $sub . '/*' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) && ( $now - (int) filemtime( $file ) ) > self::TMP_MAX_AGE ) {
					wp_delete_file( $file );
					++$deleted;
				}
			}
			if ( array() === (array) glob( $sub . '/*' ) ) {
				@rmdir( $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		return $deleted;
	}

	/**
	 * Log through WooCommerce (WooCommerce → Status → Logs, source orderbay-lite).
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 */
	private static function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'orderbay-lite' ) );
		}
	}
}

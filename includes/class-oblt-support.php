<?php
/**
 * In-plugin support tickets for OrderBay Lite.
 *
 * Sends a ticket to the Tillpress support desk (https://tillpress.com) ONLY when
 * an administrator fills in the form, ticks the consent box, and presses Send.
 * Nothing is sent automatically, in the background, or on a schedule.
 *
 * @package OrderBay Lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Support form + sender.
 */
class OBLT_Support {

	const ENDPOINT     = 'https://tillpress.com/wp-json/tp/v1/support';
	const PRIVACY_URL  = 'https://tillpress.com/privacy/';
	const TERMS_URL    = 'https://tillpress.com/terms/';
	const SUPPORT_URL  = 'https://tillpress.com/support/';
	const SUPPORT_MAIL = 'info@tillpress.com';
	const DOCS_URL     = 'https://tillpress.com/orderbay-lite/';
	const PLUGIN_KEY   = 'orderbay-lite';
	const AJAX         = 'oblt_support_send';
	const PAGE         = 'oblt-support';

	/**
	 * Singleton instance.
	 *
	 * @var OBLT_Support|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return OBLT_Support
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	private function __construct() {
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'ajax_send' ) );
	}

	/**
	 * Capability required to send a ticket.
	 *
	 * @return string
	 */
	public static function capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Enqueue the form script/style. Call from the screen that renders the form.
	 */
	public static function enqueue() {
		wp_enqueue_style( 'oblt-support', OBLT_PLUGIN_URL . 'assets/support.css', array(), OBLT_VERSION );
		wp_enqueue_script( 'oblt-support', OBLT_PLUGIN_URL . 'assets/support.js', array(), OBLT_VERSION, true );
		wp_localize_script(
			'oblt-support',
			'obltSupport',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'action' => self::AJAX,
				'nonce'  => wp_create_nonce( self::AJAX ),
				'plugin' => 'OrderBay Lite',
				'to'     => self::SUPPORT_MAIL,
				'report' => self::system_report(),
				'i18n'   => array(
					'needMessage' => __( 'Please describe the problem first.', 'orderbay-lite' ),
					'needEmail'   => __( 'Please enter the email address we should reply to.', 'orderbay-lite' ),
					'needConsent' => __( 'Please tick the box to allow sending this ticket to Tillpress.', 'orderbay-lite' ),
					'sending'     => __( 'Sending…', 'orderbay-lite' ),
					/* translators: %s: ticket number such as TP-00042. */
					'sent'        => __( 'Sent. Your ticket number is %s. We reply by email, usually within one business day.', 'orderbay-lite' ),
					'failed'      => __( 'Could not send from this site:', 'orderbay-lite' ),
					'tryEmail'    => __( 'Use "Email instead" below; it opens the same ticket in your email app.', 'orderbay-lite' ),
					'opened'      => __( 'Your email app should open with the ticket filled in. The full ticket is also on your clipboard.', 'orderbay-lite' ),
					'truncated'   => __( '[Shortened. The full ticket was copied to the clipboard.]', 'orderbay-lite' ),
					'copied'      => __( 'Ticket copied. Paste it into an email to info@tillpress.com.', 'orderbay-lite' ),
					'copyFailed'  => __( 'Could not copy automatically. Select the system report and copy it by hand.', 'orderbay-lite' ),
				),
			)
		);
	}

	/**
	 * Environment lines. Never includes credentials, keys, or customer data.
	 *
	 * @return array
	 */
	public static function environment_lines() {
		global $wpdb;
		$theme   = wp_get_theme();
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return array(
			'Plugin'         => 'OrderBay Lite ' . OBLT_VERSION,
			'Site'           => home_url( '/' ),
			'WordPress'      => get_bloginfo( 'version' ) . ( is_multisite() ? ' (multisite)' : '' ),
			'WooCommerce'    => defined( 'WC_VERSION' ) ? WC_VERSION : 'not active',
			'PHP'            => PHP_VERSION,
			'Database'       => ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : 'unknown',
			'Theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? ' (block theme)' : '' ),
			'HPOS'           => (bool) $hpos,
			'Language'       => get_locale(),
			'Memory limit'   => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'unknown',
			'WP_DEBUG'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'Active plugins' => $plugins,
		);
	}

	/**
	 * Format report lines as "Label: value" text (pure).
	 *
	 * @param array $lines Label => value.
	 * @return string
	 */
	public static function format_report( $lines ) {
		$out = array();
		foreach ( (array) $lines as $label => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$out[] = $label . ': ' . (string) $value;
		}
		return implode( "\n", $out );
	}

	/**
	 * License summary for the ticket (masked key + status). Empty for Lite.
	 *
	 * @return string
	 */
	public static function license_summary() {
		return '';
	}

	/**
	 * Full system report text.
	 *
	 * @return string
	 */
	public static function system_report() {
		if ( class_exists( 'OBLT_Hub' ) ) {
			return OBLT_Hub::system_report();
		}
		return self::format_report( self::environment_lines() );
	}

	/**
	 * Build the request payload from submitted fields (pure apart from WP sanitizers).
	 *
	 * @param array $in Raw input (unslashed).
	 * @return array
	 */
	public static function build_payload( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'plugin'  => self::PLUGIN_KEY,
			'version' => (string) OBLT_VERSION,
			'name'    => substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 120 ),
			'email'   => sanitize_email( (string) ( $in['email'] ?? '' ) ),
			'topic'   => substr( sanitize_text_field( (string) ( $in['topic'] ?? '' ) ), 0, 80 ),
			'subject' => substr( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ), 0, 200 ),
			'message' => substr( sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ), 0, 10000 ),
			'report'  => ! empty( $in['include_report'] ) ? self::system_report() : '',
			'site'    => home_url( '/' ),
			'license' => self::license_summary(),
			'consent' => ! empty( $in['consent'] ),
		);
	}

	/**
	 * Validate a payload. Returns an error message or ''.
	 *
	 * @param array $p Payload.
	 * @return string
	 */
	public static function validate( $p ) {
		if ( empty( $p['consent'] ) ) {
			return __( 'Please tick the box to allow sending this ticket to Tillpress.', 'orderbay-lite' );
		}
		if ( empty( $p['email'] ) || ! is_email( $p['email'] ) ) {
			return __( 'Please enter the email address we should reply to.', 'orderbay-lite' );
		}
		if ( strlen( trim( (string) $p['message'] ) ) < 5 ) {
			return __( 'Please describe the problem first.', 'orderbay-lite' );
		}
		return '';
	}

	/**
	 * POST the ticket to the Tillpress support desk.
	 *
	 * @param array $p Validated payload.
	 * @return array{ok:bool,ticket?:string,error?:string}
	 */
	public static function send( $p ) {
		$res = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'    => 20,
				'headers'    => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'user-agent' => 'Tillpress-Support/' . self::PLUGIN_KEY . '/' . OBLT_VERSION,
				'body'       => wp_json_encode( $p ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'    => false,
				'error' => $res->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 === $code && is_array( $body ) && ! empty( $body['ok'] ) && ! empty( $body['ticket'] ) ) {
			return array(
				'ok'     => true,
				'ticket' => sanitize_text_field( (string) $body['ticket'] ),
			);
		}
		$err = ( is_array( $body ) && ! empty( $body['error'] ) ) ? sanitize_text_field( (string) $body['error'] ) : sprintf( 'HTTP %d', $code );
		return array(
			'ok'    => false,
			'error' => $err,
		);
	}

	/**
	 * AJAX: send the ticket.
	 */
	public function ajax_send() {
		check_ajax_referer( self::AJAX, 'nonce' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission to do that.', 'orderbay-lite' ) ), 403 );
		}
		$in = array();
		foreach ( array( 'name', 'email', 'topic', 'subject', 'message', 'include_report', 'consent' ) as $k ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in build_payload().
			$in[ $k ] = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		}
		$payload = self::build_payload( $in );
		$error   = self::validate( $payload );
		if ( '' !== $error ) {
			wp_send_json_error( array( 'error' => $error ), 400 );
		}
		$result = self::send( $payload );
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'ticket' => $result['ticket'] ) );
		}
		wp_send_json_error( array( 'error' => $result['error'] ?? 'unknown' ), 502 );
	}

	/**
	 * Render the ticket form (call enqueue() on the same screen).
	 */
	public function render_form() {
		$user = wp_get_current_user();
		$p    = 'oblt';
		echo '<form id="' . esc_attr( $p ) . '-ticket" class="tp-support-ticket" data-prefix="' . esc_attr( $p ) . '" novalidate>';
		echo '<p><label for="' . esc_attr( $p ) . '-t-name">' . esc_html__( 'Your name', 'orderbay-lite' ) . '</label><input type="text" id="' . esc_attr( $p ) . '-t-name" name="name" class="regular-text" autocomplete="name" value="' . esc_attr( $user->display_name ) . '" /></p>';
		echo '<p><label for="' . esc_attr( $p ) . '-t-email">' . esc_html__( 'Reply-to email', 'orderbay-lite' ) . '</label><input type="email" id="' . esc_attr( $p ) . '-t-email" name="email" class="regular-text" autocomplete="email" required value="' . esc_attr( $user->user_email ) . '" /></p>';
		echo '<p><label for="' . esc_attr( $p ) . '-t-topic">' . esc_html__( 'Topic', 'orderbay-lite' ) . '</label><select id="' . esc_attr( $p ) . '-t-topic" name="topic">';
		foreach ( array(
			__( 'Something is not working', 'orderbay-lite' ),
			__( 'Setup question', 'orderbay-lite' ),
			__( 'Feature request', 'orderbay-lite' ),
			__( 'License or billing', 'orderbay-lite' ),
			__( 'Other', 'orderbay-lite' ),
		) as $topic ) {
			echo '<option>' . esc_html( $topic ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="' . esc_attr( $p ) . '-t-subject">' . esc_html__( 'Subject', 'orderbay-lite' ) . '</label><input type="text" id="' . esc_attr( $p ) . '-t-subject" name="subject" class="large-text" maxlength="120" /></p>';
		echo '<p><label for="' . esc_attr( $p ) . '-t-message">' . esc_html__( 'What happened, and what did you expect?', 'orderbay-lite' ) . '</label><textarea id="' . esc_attr( $p ) . '-t-message" name="message" class="large-text" rows="6" required></textarea></p>';
		echo '<p class="tp-check"><label><input type="checkbox" name="include_report" value="1" checked /> ' . esc_html__( 'Include the system report (versions, theme, active plugins; no passwords or customer data)', 'orderbay-lite' ) . '</label></p>';
		echo '<details><summary>' . esc_html__( 'Show system report', 'orderbay-lite' ) . '</summary><textarea class="large-text code" rows="10" readonly>' . esc_textarea( self::system_report() ) . '</textarea></details>';
		echo '<p class="tp-check"><label><input type="checkbox" name="consent" value="1" required /> ';
		echo wp_kses(
			sprintf(
				/* translators: 1: privacy policy URL. */
				__( 'Send this ticket to Tillpress at tillpress.com. It includes your name, email, message, this site\'s address, and (if ticked) the system report. Used only to answer you. <a href="%1$s" target="_blank" rel="noopener noreferrer">Privacy policy</a>.', 'orderbay-lite' ),
				esc_url( self::PRIVACY_URL )
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		);
		echo '</label></p>';
		echo '<p class="tp-support-actions"><button type="submit" class="button button-primary tp-support-send">' . esc_html__( 'Send ticket', 'orderbay-lite' ) . '</button> ';
		echo '<button type="button" class="button tp-support-mail">' . esc_html__( 'Email instead', 'orderbay-lite' ) . '</button> ';
		echo '<button type="button" class="button-link tp-support-copy">' . esc_html__( 'Copy ticket', 'orderbay-lite' ) . '</button></p>';
		echo '<p class="tp-support-status" role="status" aria-live="polite"></p>';
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Replies Monday to Friday, 09:00 to 17:00 Pacific Time. First reply within one business day.', 'orderbay-lite' ) . '</p>';
	}
}

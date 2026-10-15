<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequential invoice / credit note numbers + customer My Account invoice.
 */
class OBLT_Invoicing {

	private static $instance = null;

	/**
	 * Test seam: competing same-order writer after empty identity read.
	 *
	 * @var callable|null
	 */
	public static $test_after_empty_identity_read = null;

	/** Claim lease length (seconds). */
	const CLAIM_TTL = 30;

	/** @var array<string,string> Claim rows this request owns (option name => exact value). */
	private static $claim_tokens = array();

	/** @var int */
	private static $claim_seq = 0;

	/** @var int Loser backoff step in microseconds (tests set 0). */
	public static $claim_wait_us = 25000;

	/** @var callable|null Test hook: after allocation, before the owner-checked save. */
	public static $test_before_claim_save = null;


	/**
	 * Test seam: competing increment after successful CAS claim.
	 *
	 * @var callable|null
	 */
	public static $test_after_cas_claim = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_oblt_print_credit_note', array( $this, 'handle_print_credit' ) );
		add_action( 'admin_post_oblt_issue_credit_note', array( $this, 'handle_issue_credit' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_screen_invoice_meta' ), 15 );
		add_action( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_oblt_issue_credit_note', array( $this, 'action_issue_credit' ) );
		add_action( 'woocommerce_order_action_oblt_print_credit_note', array( $this, 'action_print_credit' ) );

		// Customer My Account — logged-in only.
		add_action( 'init', array( $this, 'add_endpoints' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'account_orders_action' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'account_view_order_link' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_customer_invoice' ) );
	}

	public function register_settings() {
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_INVOICE_PREFIX, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_prefix' ),
			'default'           => 'INV-',
		) );
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_INVOICE_NEXT, array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_next' ),
			'default'           => 1,
		) );
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_INVOICE_FORMAT, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_format' ),
			'default'           => '{PREFIX}{SEQ}',
		) );
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_INVOICE_RESET, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_reset' ),
			'default'           => 'none',
		) );
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_CREDIT_PREFIX, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_credit_prefix' ),
			'default'           => 'CN-',
		) );
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_CREDIT_NEXT, array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_next' ),
			'default'           => 1,
		) );
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_CREDIT_FORMAT, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_format' ),
			'default'           => '{PREFIX}{SEQ}',
		) );
		register_setting( 'oblt_documents', OBLT_Plugin::OPT_CREDIT_RESET, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_reset' ),
			'default'           => 'none',
		) );
	}

	public function sanitize_prefix( $v ) {
		$v = is_string( $v ) ? sanitize_text_field( $v ) : 'INV-';
		return $v;
	}

	public function sanitize_credit_prefix( $v ) {
		$v = is_string( $v ) ? sanitize_text_field( $v ) : 'CN-';
		return $v ? $v : 'CN-';
	}

	public function sanitize_next( $v ) {
		$n = absint( $v );
		return $n > 0 ? $n : 1;
	}

	/**
	 * Sanitize a numbering-format template. Guarantees a sequence token is present so two
	 * documents can never collide on the same number (a legal problem for tax invoices).
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public function sanitize_format( $v ) {
		$v = is_string( $v ) ? trim( wp_strip_all_tags( $v ) ) : '';
		if ( '' === $v ) {
			return '{PREFIX}{SEQ}';
		}
		if ( ! preg_match( '/\{SEQ(:\d{1,2})?\}/', $v ) ) {
			$v .= '{SEQ}';
		}
		return $v;
	}

	/**
	 * Sanitize a reset period. Only none/yearly/monthly are allowed.
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public function sanitize_reset( $v ) {
		$v = is_string( $v ) ? strtolower( trim( $v ) ) : 'none';
		return in_array( $v, array( 'none', 'yearly', 'monthly' ), true ) ? $v : 'none';
	}

	/**
	 * Expand a numbering template into the final document number (pure).
	 *
	 * Supported tokens: {PREFIX}, {YYYY}, {YY}, {MM}, {DD}, {SEQ}, and {SEQ:n} (zero-pad
	 * the sequence to n digits). The default template '{PREFIX}{SEQ}' reproduces the historical
	 * prefix+sequence output exactly, so existing numbers are unchanged.
	 *
	 * @param string $template Template string.
	 * @param int    $seq      Sequence number (>= 1).
	 * @param int    $ts       Unix timestamp used for the date tokens (0 = now).
	 * @param string $prefix   Value substituted for {PREFIX}.
	 * @return string
	 */
	public static function format_number( $template, $seq, $ts = 0, $prefix = '' ) {
		$template = is_string( $template ) ? $template : '';
		if ( '' === trim( $template ) ) {
			$template = '{PREFIX}{SEQ}';
		}
		$seq = (int) $seq;
		if ( $seq < 0 ) {
			$seq = 0;
		}
		$ts = (int) $ts > 0 ? (int) $ts : time();

		// Zero-padded sequence {SEQ:n} first, so it is not shadowed by the bare {SEQ}.
		$out = preg_replace_callback(
			'/\{SEQ:(\d{1,2})\}/',
			function ( $m ) use ( $seq ) {
				return str_pad( (string) $seq, (int) $m[1], '0', STR_PAD_LEFT );
			},
			$template
		);

		return strtr(
			$out,
			array(
				'{PREFIX}' => (string) $prefix,
				'{YYYY}'   => gmdate( 'Y', $ts ),
				'{YY}'     => gmdate( 'y', $ts ),
				'{MM}'     => gmdate( 'm', $ts ),
				'{DD}'     => gmdate( 'd', $ts ),
				'{SEQ}'    => (string) $seq,
			)
		);
	}

	/**
	 * Reset-period bucket key for the counter row. Empty when there is no reset.
	 *
	 * @param string $reset none|yearly|monthly.
	 * @param int    $ts    Local timestamp.
	 * @return string
	 */
	private static function period_key( $reset, $ts ) {
		switch ( $reset ) {
			case 'yearly':
				return gmdate( 'Y', $ts );
			case 'monthly':
				return gmdate( 'Ym', $ts );
			default:
				return '';
		}
	}

	/**
	 * Assign immutable invoice number once.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function ensure_invoice_number( $order ) {
		return self::ensure_document_number(
			$order,
			'invoice',
			OBLT_Plugin::META_INVOICE_NUMBER,
			'_ob_invoice_number',
			OBLT_Plugin::OPT_INVOICE_PREFIX,
			OBLT_Plugin::OPT_INVOICE_NEXT,
			'INV-',
			OBLT_Plugin::OPT_INVOICE_FORMAT,
			OBLT_Plugin::OPT_INVOICE_RESET,
			/* translators: %s: invoice number. */
			__( 'OrderBay Lite invoice number assigned: %s', 'orderbay-lite' )
		);
	}

	/**
	 * Issue credit note number (optional even without refunds if staff forces).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function ensure_credit_number( $order ) {
		return self::ensure_document_number(
			$order,
			'credit',
			OBLT_Plugin::META_CREDIT_NUMBER,
			'_ob_credit_note_number',
			OBLT_Plugin::OPT_CREDIT_PREFIX,
			OBLT_Plugin::OPT_CREDIT_NEXT,
			'CN-',
			OBLT_Plugin::OPT_CREDIT_FORMAT,
			OBLT_Plugin::OPT_CREDIT_RESET,
			/* translators: %s: credit note number. */
			__( 'OrderBay Lite credit note number issued: %s', 'orderbay-lite' )
		);
	}

	/**
	 * Claim order+type, allocate, persist. Empty return = fail closed (nothing saved).
	 *
	 * @param WC_Order $order           Order.
	 * @param string   $type            invoice|credit.
	 * @param string   $meta_key        Lite meta key.
	 * @param string   $sibling_meta    Paid meta key to adopt.
	 * @param string   $opt_prefix      Prefix option.
	 * @param string   $opt_next        Next-counter option.
	 * @param string   $default_prefix  Fallback prefix.
	 * @param string   $opt_format      Format option.
	 * @param string   $opt_reset       Reset option.
	 * @param string   $note_sprintf    Order-note template with %s.
	 * @return string
	 */
	private static function ensure_document_number( $order, $type, $meta_key, $sibling_meta, $opt_prefix, $opt_next, $default_prefix, $opt_format, $opt_reset, $note_sprintf ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$existing = self::adopt_existing_identity( $order, $meta_key, $sibling_meta );
		if ( '' !== $existing ) {
			return $existing;
		}
		if ( is_callable( self::$test_after_empty_identity_read ) ) {
			call_user_func( self::$test_after_empty_identity_read, $order, $type );
			$existing = self::adopt_existing_identity( $order, $meta_key, $sibling_meta );
			if ( '' !== $existing ) {
				return $existing;
			}
		}
		$order_id = (int) $order->get_id();
		if ( ! self::try_claim_document( $order_id, $type ) ) {
			return self::wait_for_winner_identity( $order, $meta_key, $sibling_meta );
		}
		// We own the claim: re-read the order from the database so a number saved by
		// an earlier owner (whose lease we may have taken over) is adopted, not replaced.
		$order    = self::fresh_order( $order );
		$existing = self::adopt_existing_identity( $order, $meta_key, $sibling_meta );
		if ( '' !== $existing ) {
			self::release_document_claim( $order_id, $type );
			return $existing;
		}
		$number = self::allocate_number( $opt_prefix, $opt_next, $default_prefix, $opt_format, $opt_reset );
		if ( is_wp_error( $number ) || ! is_string( $number ) || '' === $number ) {
			self::release_document_claim( $order_id, $type );
			self::record_allocation_failure( $order, $type, $number );
			return '';
		}
		if ( is_callable( self::$test_before_claim_save ) ) {
			call_user_func( self::$test_before_claim_save, $order, $type, $number );
		}
		// Owner-checked save fence: renew our lease only if it is still ours. A worker
		// whose lease expired and was taken over must not write its number.
		if ( ! self::refresh_document_claim( $order_id, $type ) ) {
			self::record_lost_claim( $order, $type, $number );
			return self::wait_for_winner_identity( $order, $meta_key, $sibling_meta );
		}
		$order    = self::fresh_order( $order );
		$existing = self::adopt_existing_identity( $order, $meta_key, $sibling_meta );
		if ( '' !== $existing ) {
			self::release_document_claim( $order_id, $type );
			self::record_lost_claim( $order, $type, $number );
			return $existing;
		}
		$order->update_meta_data( $meta_key, $number );
		$order->add_order_note( sprintf( $note_sprintf, $number ), false, true );
		$order->save();
		self::release_document_claim( $order_id, $type );
		return $number;
	}

	/**
	 * Adopt Lite meta, else copy paid `_ob_` identity onto Lite meta.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $meta_key     Lite meta.
	 * @param string   $sibling_meta Paid meta.
	 * @return string
	 */
	public static function adopt_existing_identity( $order, $meta_key, $sibling_meta ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$mine = $order->get_meta( $meta_key );
		if ( $mine ) {
			return (string) $mine;
		}
		if ( $sibling_meta ) {
			$sib = $order->get_meta( $sibling_meta );
			if ( $sib ) {
				$order->update_meta_data( $meta_key, (string) $sib );
				$order->save();
				return (string) $sib;
			}
		}
		return '';
	}

	/**
	 * Atomic per-order, per-document-type claim (lease).
	 *
	 * The claim row holds "<unix time>|<owner token>|<seq>". A free row is taken with
	 * INSERT IGNORE; an expired row is taken over with a compare-and-swap on its exact
	 * old value, so two workers can never both win and the stale owner's token stops
	 * matching. Nothing is ever deleted that this request does not own.
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Document type.
	 * @return bool True if this caller now owns the claim.
	 */
	public static function try_claim_document( $order_id, $type ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}
		$key   = self::claim_option_key( $order_id, $type );
		$token = self::new_claim_token();
		$value = self::claim_value( $token );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$ins = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$wpdb->options,
					$key,
					$value
				)
			);
			if ( false === $ins ) {
				return false;
			}
			if ( (int) $ins > 0 ) {
				self::$claim_tokens[ $key ] = $value;
				self::forget_claim_cache( $key );
				return true;
			}
			$current = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s',
					$wpdb->options,
					$key
				)
			);
			if ( null === $current || false === $current ) {
				continue; // Released between our insert and read: try the insert once more.
			}
			if ( ! self::claim_is_expired( (string) $current ) ) {
				return false;
			}
			$took = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
					$wpdb->options,
					$value,
					$key,
					(string) $current
				)
			);
			if ( false !== $took && (int) $took > 0 ) {
				self::$claim_tokens[ $key ] = $value;
				self::forget_claim_cache( $key );
				return true;
			}
			return false;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false;
	}

	/**
	 * Renew our lease, but only if the row still holds exactly our value.
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Document type.
	 * @return bool False when another worker has taken the claim over.
	 */
	public static function refresh_document_claim( $order_id, $type ) {
		global $wpdb;
		$key  = self::claim_option_key( $order_id, $type );
		$mine = isset( self::$claim_tokens[ $key ] ) ? (string) self::$claim_tokens[ $key ] : '';
		if ( '' === $mine || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$parts = explode( '|', $mine );
		$next  = self::claim_value( isset( $parts[1] ) ? $parts[1] : self::new_claim_token() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$upd = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$next,
				$key,
				$mine
			)
		);
		if ( false === $upd || (int) $upd < 1 ) {
			unset( self::$claim_tokens[ $key ] );
			return false;
		}
		self::$claim_tokens[ $key ] = $next;
		self::forget_claim_cache( $key );
		return true;
	}

	/**
	 * @param int    $order_id Order id.
	 * @param string $type     Document type.
	 * @return string
	 */
	public static function claim_option_key( $order_id, $type ) {
		return 'oblt_doc_claim_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $type ) ) . '_' . (int) $order_id;
	}

	/**
	 * Release our claim. Owner-checked: a late worker cannot delete a newer owner's row.
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Document type.
	 */
	private static function release_document_claim( $order_id, $type ) {
		global $wpdb;
		$key  = self::claim_option_key( $order_id, $type );
		$mine = isset( self::$claim_tokens[ $key ] ) ? (string) self::$claim_tokens[ $key ] : '';
		unset( self::$claim_tokens[ $key ] );
		if ( '' === $mine || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$key,
				$mine
			)
		);
		self::forget_claim_cache( $key );
	}

	/**
	 * @param string $token Owner token.
	 * @return string
	 */
	private static function claim_value( $token ) {
		++self::$claim_seq;
		return time() . '|' . $token . '|' . self::$claim_seq;
	}

	/** @return string */
	private static function new_claim_token() {
		try {
			return bin2hex( random_bytes( 12 ) );
		} catch ( \Exception $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}

	/**
	 * Legacy rows are a bare timestamp; new rows are "<ts>|<token>|<seq>".
	 *
	 * @param string $value Stored claim value.
	 * @return bool
	 */
	public static function claim_is_expired( $value ) {
		$ts = (int) strtok( (string) $value, '|' );
		return ( time() - $ts ) > self::CLAIM_TTL;
	}

	/**
	 * @param string $key Option name.
	 */
	private static function forget_claim_cache( $key ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * Re-read an order from the database (drop request-local caches first).
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order
	 */
	private static function fresh_order( $order ) {
		if ( ! $order instanceof WC_Order || ! function_exists( 'wc_get_order' ) ) {
			return $order;
		}
		$id = (int) $order->get_id();
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $id, 'orders' );
			wp_cache_delete( $id, 'post_meta' );
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $id );
		}
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( '\Automattic\WooCommerce\Caches\OrderCache' )->remove( $id );
			} catch ( \Throwable $e ) {
				unset( $e ); // Cache helper unavailable: the plain cache deletes above still apply.
			}
		}
		$fresh = wc_get_order( $id );
		return ( $fresh instanceof WC_Order && (int) $fresh->get_id() === $id ) ? $fresh : $order;
	}

	/**
	 * A stalled worker lost its claim after allocating: the number is a gap, never saved.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $type   Document type.
	 * @param string   $number Unused allocated number.
	 */
	private static function record_lost_claim( $order, $type, $number ) {
		if ( $order instanceof WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: document number, 2: document type */
					__( 'Document number %1$s (%2$s) was allocated by a request that lost its lock and was not used. This order keeps its existing number.', 'orderbay-lite' ),
					$number,
					$type
				),
				false,
				true
			);
		}
		$GLOBALS['oblt_lost_claims'][] = array(
			'type'   => $type,
			'number' => $number,
		);
	}

	/**
	 * Loser of the claim re-reads the winner's persisted number (short backoff).
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $meta_key     Meta key.
	 * @param string   $sibling_meta Sibling meta.
	 * @return string
	 */
	private static function wait_for_winner_identity( $order, $meta_key, $sibling_meta ) {
		for ( $i = 0; $i < 8; $i++ ) {
			if ( $i > 0 && self::$claim_wait_us > 0 ) {
				usleep( self::$claim_wait_us * $i );
			}
			$order    = self::fresh_order( $order );
			$existing = self::adopt_existing_identity( $order, $meta_key, $sibling_meta );
			if ( '' !== $existing ) {
				return $existing;
			}
		}
		return '';
	}

	/**
	 * @param WC_Order|null   $order Order.
	 * @param string          $type  Type.
	 * @param WP_Error|string $error Error.
	 */
	public static function record_allocation_failure( $order, $type, $error ) {
		$msg = is_wp_error( $error ) ? $error->get_error_message() : __( 'OrderBay Lite could not allocate a document number. The document was not saved.', 'orderbay-lite' );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( $msg, false, true );
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'orderbay-lite', 'oblt_alloc_failed', $msg, 'error' );
		}
		$GLOBALS['oblt_allocation_failures'][] = array(
			'type'    => $type,
			'message' => $msg,
		);
	}

	/**
	 * Copy paid numbering settings into Lite (downgrade). Idempotent.
	 *
	 * Prefix/format/reset: copy only when Lite has none.
	 * Counters: raise Lite to max(lite, paid).
	 */
	public static function migrate_paid_settings() {
		$map = array(
			'ob_invoice_prefix' => OBLT_Plugin::OPT_INVOICE_PREFIX,
			'ob_invoice_format' => OBLT_Plugin::OPT_INVOICE_FORMAT,
			'ob_invoice_reset'  => OBLT_Plugin::OPT_INVOICE_RESET,
			'ob_invoice_next'   => OBLT_Plugin::OPT_INVOICE_NEXT,
			'ob_credit_prefix'  => OBLT_Plugin::OPT_CREDIT_PREFIX,
			'ob_credit_format'  => OBLT_Plugin::OPT_CREDIT_FORMAT,
			'ob_credit_reset'   => OBLT_Plugin::OPT_CREDIT_RESET,
			'ob_credit_next'    => OBLT_Plugin::OPT_CREDIT_NEXT,
		);
		foreach ( $map as $from => $to ) {
			$paid = get_option( $from, false );
			$lite = get_option( $to, false );
			if ( self::option_is_counter( $to ) ) {
				self::adopt_counter_max( $to, $lite, $paid );
				continue;
			}
			if ( false !== $lite ) {
				continue;
			}
			if ( false !== $paid ) {
				add_option( $to, $paid, '', 'no' );
			}
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && isset( $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->options,
					$wpdb->esc_like( 'ob_invoice_next_' ) . '%',
					$wpdb->esc_like( 'ob_credit_next_' ) . '%'
				)
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$name = is_object( $row ) ? (string) $row->option_name : '';
					$val  = is_object( $row ) ? $row->option_value : '';
					if ( '' === $name || 0 !== strpos( $name, 'ob_' ) || 0 === strpos( $name, 'oblt_' ) ) {
						continue;
					}
					$lite_name = 'oblt_' . substr( $name, 3 );
					self::adopt_counter_max( $lite_name, get_option( $lite_name, false ), $val );
				}
			}
		}
	}

	/**
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function option_is_counter( $name ) {
		return (bool) preg_match( '/_next(_|$)/', (string) $name );
	}

	/**
	 * @param string $dest     Option name.
	 * @param mixed  $existing Current value or false.
	 * @param mixed  $incoming Incoming value or false.
	 */
	public static function adopt_counter_max( $dest, $existing, $incoming ) {
		$max = max( (int) $existing, (int) $incoming );
		if ( $max < 1 ) {
			return;
		}
		if ( false === $existing || null === $existing || '' === $existing ) {
			add_option( $dest, $max, '', 'no' );
			return;
		}
		if ( (int) $existing < $max ) {
			update_option( $dest, $max, 'no' );
		}
	}

	/**
	 * Whether $wpdb is SQLite (Playground / sqlite-database-integration).
	 * INSERT IGNORE and LAST_INSERT_ID() are MySQL-only and error on SQLite.
	 *
	 * @return bool
	 */
	private static function db_is_sqlite() {
		global $wpdb;
		// WordPress Playground / sqlite-database-integration. Check constants first:
		// $wpdb->dbh is WP_MySQL_On_SQLite, not PDO, on current drop-ins.
		if ( defined( 'FQDB' ) || ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) ) {
			return true;
		}
		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- driver detection only; no queries via PDO.
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \PDO ) {
			try {
				return 'sqlite' === $wpdb->dbh->getAttribute( \PDO::ATTR_DRIVER_NAME );
			} catch ( \Exception $e ) {
				return true;
			}
		}
		// phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO
		return ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) && 'WP_MySQL_On_SQLite' === get_class( $wpdb->dbh ) );
	}

	/**
	 * Sequence integer encoded in a stored document number for the current namespace.
	 *
	 * @param string $stored   Stored document number.
	 * @param string $template Format template.
	 * @param string $prefix   Prefix token value.
	 * @param int    $ts       Timestamp used for date tokens.
	 * @return int
	 */
	private static function seq_in_namespace( $stored, $template, $prefix, $ts ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return 0;
		}
		$template = is_string( $template ) && '' !== trim( $template ) ? $template : '{PREFIX}{SEQ}';
		$quoted   = preg_quote( $template, '/' );
		$token_map = array(
			'{PREFIX}' => preg_quote( (string) $prefix, '/' ),
			'{YYYY}'   => preg_quote( gmdate( 'Y', $ts ), '/' ),
			'{YY}'     => preg_quote( gmdate( 'y', $ts ), '/' ),
			'{MM}'     => preg_quote( gmdate( 'm', $ts ), '/' ),
			'{DD}'     => preg_quote( gmdate( 'd', $ts ), '/' ),
		);
		foreach ( $token_map as $token => $replacement ) {
			$quoted = str_replace( preg_quote( $token, '/' ), $replacement, $quoted );
		}
		$quoted = preg_replace( '/\\\\\{SEQ(?::\d{1,2})?\\\\\}/', '(\d+)', $quoted );
		if ( ! is_string( $quoted ) || ! preg_match( '/^' . $quoted . '$/', $stored, $m ) ) {
			return 0;
		}
		$seq = isset( $m[1] ) ? (int) $m[1] : 0;
		return $seq > 0 ? $seq : 0;
	}

	/**
	 * SELECT meta_value only — no wc_get_orders / object hydration.
	 *
	 * @param string[] $meta_keys Meta keys.
	 * @return string[]
	 */
	private static function query_existing_document_numbers( $meta_keys ) {
		$numbers = array();
		$keys    = array();
		foreach ( (array) $meta_keys as $k ) {
			$k = (string) $k;
			if ( '' !== $k ) {
				$keys[] = $k;
			}
		}
		$keys = array_values( array_unique( $keys ) );
		if ( ! $keys ) {
			return $numbers;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return $numbers;
		}
		$tables = array();
		if ( isset( $wpdb->postmeta ) && $wpdb->postmeta ) {
			$tables[] = $wpdb->postmeta;
		}
		if ( isset( $wpdb->prefix ) ) {
			$tables[] = $wpdb->prefix . 'postmeta';
			$tables[] = $wpdb->prefix . 'wc_orders_meta';
		}
		$tables = array_values( array_unique( array_filter( $tables ) ) );
		foreach ( $tables as $table ) {
			if ( ! self::meta_table_exists( $table ) ) {
				continue; // HPOS table not created yet (fresh install / Playground).
			}
			foreach ( $keys as $meta_key ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT meta_value FROM %i WHERE meta_key = %s AND meta_value <> ''",
						$table,
						$meta_key
					)
				);
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						if ( '' !== (string) $row ) {
							$numbers[] = (string) $row;
						}
					}
				}
			}
		}
		return $numbers;
	}

	/**
	 * Does a meta table exist? Cached per request.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function meta_table_exists( $table ) {
		static $seen = array();
		if ( isset( $seen[ $table ] ) ) {
			return $seen[ $table ];
		}
		global $wpdb;
		if ( ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			$seen[ $table ] = true;
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, cached per request.
		$found          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$seen[ $table ] = ( null === $found ) ? false : true;
		return $seen[ $table ];
	}

	/**
	 * @param string[] $meta_keys Meta keys.
	 * @param string   $template  Template.
	 * @param string   $prefix    Prefix.
	 * @param int      $ts        Timestamp.
	 * @return int
	 */
	private static function high_water_seq( $meta_keys, $template, $prefix, $ts ) {
		$max = 0;
		foreach ( self::query_existing_document_numbers( $meta_keys ) as $number ) {
			$seq = self::seq_in_namespace( $number, $template, $prefix, $ts );
			if ( $seq > $max ) {
				$max = $seq;
			}
		}
		return $max;
	}

	/**
	 * @param string $opt_next Counter option.
	 * @param string $period   Period suffix.
	 * @return string
	 */
	public static function highwater_marker_key( $opt_next, $period = '' ) {
		$base = (string) $opt_next;
		$base = preg_replace( '/_next$/', '', $base );
		$key  = $base . '_highwater_checked';
		return '' !== $period ? $key . '_' . $period : $key;
	}

	/**
	 * Compare-and-swap increment for the SQLite drop-in.
	 *
	 * @param object $wpdb        Database.
	 * @param string $counter_opt Option name.
	 * @param int    $seed        Minimum next-to-issue.
	 * @param int    $max_tries   Retry cap.
	 * @return int|WP_Error
	 */
	public static function sqlite_cas_increment( $wpdb, $counter_opt, $seed = 1, $max_tries = 12 ) {
		$seed      = max( 1, (int) $seed );
		$max_tries = max( 1, (int) $max_tries );
		for ( $i = 0; $i < $max_tries; $i++ ) {
			$current = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic document counter; must read the live row, never a cache.
				$wpdb->prepare(
					"SELECT option_value FROM %i WHERE option_name = %s",
					$wpdb->options,
					$counter_opt
				)
			);
			if ( $current < 1 ) {
				$current = 1;
			}
			if ( $current < $seed ) {
				$bumped = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic document counter; must read the live row, never a cache.
					$wpdb->prepare(
						"UPDATE %i SET option_value = %d WHERE option_name = %s AND option_value = %s",
						$wpdb->options,
						$seed,
						$counter_opt,
						(string) $current
					)
				);
				if ( false === $bumped || ( isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) ) {
					return new WP_Error( 'oblt_alloc_db', __( 'OrderBay Lite could not update the document counter.', 'orderbay-lite' ) );
				}
				continue;
			}
			$desired = $current + 1;
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic document counter; must read the live row, never a cache.
				$wpdb->prepare(
					"UPDATE %i SET option_value = %d WHERE option_name = %s AND option_value = %s",
					$wpdb->options,
					$desired,
					$counter_opt,
					(string) $current
				)
			);
			if ( false === $updated || ( isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) ) {
				return new WP_Error( 'oblt_alloc_db', __( 'OrderBay Lite could not update the document counter.', 'orderbay-lite' ) );
			}
			if ( is_numeric( $updated ) && (int) $updated < 1 ) {
				continue;
			}
			if ( is_callable( self::$test_after_cas_claim ) ) {
				call_user_func( self::$test_after_cas_claim, $wpdb, $counter_opt, $desired );
			}
			return $desired;
		}
		return new WP_Error( 'oblt_alloc_exhausted', __( 'OrderBay Lite could not allocate a document number after repeated conflicts. The document was not saved.', 'orderbay-lite' ) );
	}

	/**
	 * Canonical counter both editions increment (paid option name).
	 *
	 * @param string $opt_next Edition counter option (base, no period).
	 * @param string $period   Period suffix or ''.
	 * @return string
	 */
	public static function shared_counter_option( $opt_next, $period = '' ) {
		$map = array(
			'oblt_invoice_next' => 'ob_invoice_next',
			'oblt_credit_next'  => 'ob_credit_next',
			'ob_invoice_next'   => 'ob_invoice_next',
			'ob_credit_next'    => 'ob_credit_next',
		);
		$base = isset( $map[ $opt_next ] ) ? $map[ $opt_next ] : $opt_next;
		return '' !== $period ? $base . '_' . $period : $base;
	}

	/**
	 * @param string $opt_next Edition counter option (base, no period).
	 * @param string $period   Period suffix or ''.
	 * @return string
	 */
	public static function sibling_counter_option( $opt_next, $period = '' ) {
		$map = array(
			'oblt_invoice_next' => 'ob_invoice_next',
			'oblt_credit_next'  => 'ob_credit_next',
			'ob_invoice_next'   => 'oblt_invoice_next',
			'ob_credit_next'    => 'oblt_credit_next',
		);
		if ( ! isset( $map[ $opt_next ] ) ) {
			return '';
		}
		$sib = $map[ $opt_next ];
		return '' !== $period ? $sib . '_' . $period : $sib;
	}

	/**
	 * @param string $name Option name.
	 * @return int
	 */
	public static function read_counter_value( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return 0;
		}
		global $wpdb;
		$n = 0;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && isset( $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM %i WHERE option_name = %s",
					$wpdb->options,
					$name
				)
			);
			if ( null !== $row && false !== $row && '' !== (string) $row ) {
				$n = (int) $row;
			}
		}
		return max( $n, (int) get_option( $name, 0 ) );
	}

	/**
	 * @param string $name Counter option.
	 * @param int    $next Next-to-issue.
	 */
	private static function mirror_counter_value( $name, $next ) {
		$next = (int) $next;
		if ( ! is_string( $name ) || '' === $name || $next < 1 ) {
			return;
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && isset( $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$wpdb->options,
					$name,
					(string) $next
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET option_value = %d WHERE option_name = %s AND option_value + 0 < %d",
					$wpdb->options,
					$next,
					$name,
					$next
				)
			);
		}
		$cur = get_option( $name, false );
		if ( false === $cur ) {
			add_option( $name, $next, '', 'no' );
		} elseif ( (int) $cur < $next ) {
			update_option( $name, $next, 'no' );
		}
		wp_cache_delete( $name, 'options' );
	}

	public static function allocate_number( $opt_prefix, $opt_next, $default_prefix, $opt_format = '', $opt_reset = '' ) {
		global $wpdb;

		self::migrate_paid_settings();

		$prefix = get_option( $opt_prefix, $default_prefix );
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			$prefix = $default_prefix;
		}

		$template = $opt_format ? (string) get_option( $opt_format, '{PREFIX}{SEQ}' ) : '{PREFIX}{SEQ}';
		if ( '' === trim( $template ) ) {
			$template = '{PREFIX}{SEQ}';
		}
		$reset = $opt_reset ? (string) get_option( $opt_reset, 'none' ) : 'none';

		$ts     = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time(); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$period = self::period_key( $reset, $ts );

		$local_opt   = '' !== $period ? $opt_next . '_' . $period : $opt_next;
		$shared_opt  = self::shared_counter_option( $opt_next, $period );
		$sibling_opt = self::sibling_counter_option( $opt_next, $period );
		$counter_opt = $shared_opt;

		$meta_key = ( OBLT_Plugin::OPT_CREDIT_NEXT === $opt_next ) ? OBLT_Plugin::META_CREDIT_NUMBER : OBLT_Plugin::META_INVOICE_NUMBER;
		$sibling  = ( OBLT_Plugin::OPT_CREDIT_NEXT === $opt_next ) ? '_ob_credit_note_number' : '_ob_invoice_number';
		$marker_key = self::highwater_marker_key( $opt_next, $period );
		$counter_row = null;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$counter_row = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic document counter; must read the live row, never a cache.
				$wpdb->prepare(
					"SELECT option_value FROM %i WHERE option_name = %s",
					$wpdb->options,
					$counter_opt
				)
			);
		}
		$counter_missing = ( null === $counter_row || false === $counter_row || '' === (string) $counter_row );
		$marker_missing  = ( false === get_option( $marker_key, false ) );
		$seed            = max(
			1,
			self::read_counter_value( $shared_opt ),
			self::read_counter_value( $local_opt ),
			self::read_counter_value( $sibling_opt )
		);
		if ( $counter_missing || $marker_missing ) {
			$high = self::high_water_seq( array( $meta_key, $sibling ), $template, $prefix, $ts );
			$seed = max( $seed, $high + 1 );
			if ( $seed < 1 ) {
				$seed = 1;
			}
			update_option( $marker_key, $high, 'no' );
		}

		$is_sqlite = self::db_is_sqlite();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ins = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$wpdb->options,
				$counter_opt,
				(string) $seed
			)
		);
		if ( false === $ins || ( isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) ) {
			return new WP_Error( 'oblt_alloc_db', __( 'OrderBay Lite could not seed the document counter.', 'orderbay-lite' ) );
		}
		$bump = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET option_value = %d WHERE option_name = %s AND option_value + 0 < %d",
				$wpdb->options,
				$seed,
				$counter_opt,
				$seed
			)
		);
		if ( false === $bump || ( isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) ) {
			return new WP_Error( 'oblt_alloc_db', __( 'OrderBay Lite could not seed the document counter.', 'orderbay-lite' ) );
		}
		if ( $is_sqlite ) {
			$next = self::sqlite_cas_increment( $wpdb, $counter_opt, $seed );
		} else {
			$upd = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s",
					$wpdb->options,
					$counter_opt
				)
			);
			if ( false === $upd || ( isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) && '' !== $wpdb->last_error ) ) {
				return new WP_Error( 'oblt_alloc_db', __( 'OrderBay Lite could not allocate a document number.', 'orderbay-lite' ) );
			}
			$next = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		$seq = (int) $next - 1;
		if ( $seq < 1 ) {
			return new WP_Error( 'oblt_alloc_invalid', __( 'OrderBay Lite could not allocate a document number. The document was not saved.', 'orderbay-lite' ) );
		}

		wp_cache_delete( $counter_opt, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( $local_opt !== $counter_opt ) {
			self::mirror_counter_value( $local_opt, (int) $next );
		}
		if ( $sibling_opt && $sibling_opt !== $counter_opt ) {
			self::mirror_counter_value( $sibling_opt, (int) $next );
		}

		return self::format_number( $template, $seq, $ts, $prefix );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function order_screen_invoice_meta( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$inv = $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER );
		$cn  = $order->get_meta( OBLT_Plugin::META_CREDIT_NUMBER );
		echo '<div class="oblt-invoice-meta" style="clear:both;padding:8px 0;">';
		echo '<p><strong>' . esc_html__( 'Invoice #', 'orderbay-lite' ) . ':</strong> ';
		echo $inv ? esc_html( $inv ) : '<em>' . esc_html__( 'Not assigned yet (assigned on first print)', 'orderbay-lite' ) . '</em>';
		echo '</p>';
		if ( $cn ) {
			echo '<p><strong>' . esc_html__( 'Credit note #', 'orderbay-lite' ) . ':</strong> ' . esc_html( $cn ) . '</p>';
		}
		$has_refunds = $order->get_total_refunded() > 0 || count( $order->get_refunds() ) > 0;
		if ( $has_refunds || $cn ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=oblt_print_credit_note&order_id=' . $order->get_id() ),
				'oblt_print_credit_note_' . $order->get_id()
			);
			echo '<p><a class="button" target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'Print credit note', 'orderbay-lite' ) . '</a></p>';
		}
		echo '</div>';
	}

	public function order_actions( $actions ) {
		$actions['oblt_issue_credit_note'] = __( 'OrderBay Lite: issue credit note number', 'orderbay-lite' );
		$actions['oblt_print_credit_note'] = __( 'OrderBay Lite: print credit note', 'orderbay-lite' );
		return $actions;
	}

	public function action_issue_credit( $order ) {
		self::ensure_credit_number( $order );
	}

	public function action_print_credit( $order ) {
		// Number may be issued on print if refunds exist.
		if ( $order->get_total_refunded() > 0 || $order->get_meta( OBLT_Plugin::META_CREDIT_NUMBER ) ) {
			self::ensure_credit_number( $order );
		}
	}

	public function handle_issue_credit() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'oblt_issue_credit_note_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay-lite' ) );
		}
		self::ensure_credit_number( $order );
		// HPOS-safe edit URL (post.php?post= is invalid for HPOS-stored orders).
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	public function handle_print_credit() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay-lite' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'oblt_print_credit_note_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay-lite' ) );
		}
		$has_refunds = $order->get_total_refunded() > 0 || count( $order->get_refunds() ) > 0;
		$has_number  = (bool) $order->get_meta( OBLT_Plugin::META_CREDIT_NUMBER );
		if ( ! $has_refunds && ! $has_number ) {
			wp_die( esc_html__( 'No refunds and no credit note number on this order.', 'orderbay-lite' ) );
		}
		if ( $has_refunds || $has_number ) {
			$cn = self::ensure_credit_number( $order );
			if ( '' === (string) $cn ) {
				wp_die( esc_html__( 'A credit note number could not be allocated. The document was not rendered.', 'orderbay-lite' ) );
			}
		}
		$settings = OBLT_Plugin::get_doc_settings();
		$orders   = array( $order );
		include OBLT_Documents::locate_template( 'credit-note.php' );
		exit;
	}

	/* ─── Customer My Account ─────────────────────────────────────── */

	public function add_endpoints() {
		// Query-arg based; no rewrite required.
	}

	/**
	 * Whether current user may view invoice for order (owner only).
	 *
	 * @param WC_Order $order Order.
	 * @param int|null $user_id User ID.
	 * @return bool
	 */
	public static function customer_can_view_invoice( $order, $user_id = null ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return false;
		}
		// Staff can always view via admin routes; this is customer path only.
		if ( user_can( $user_id, 'edit_shop_orders' ) ) {
			return true;
		}
		// Ownership is the order's own customer_id only. The billing email is
		// typed by the buyer and never verified, so it must not grant access:
		// a guest order (customer_id 0) is not reachable from My Account.
		$customer_id = (int) $order->get_customer_id();
		return $customer_id > 0 && $customer_id === (int) $user_id;
	}

	/**
	 * @param array    $actions Actions.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function account_orders_action( $actions, $order ) {
		if ( ! $order || ! is_user_logged_in() ) {
			return $actions;
		}
		if ( ! self::customer_can_view_invoice( $order ) ) {
			return $actions;
		}
		$actions['oblt_invoice'] = array(
			'url'  => self::customer_invoice_url( $order->get_id() ),
			'name' => __( 'Invoice', 'orderbay-lite' ),
		);
		return $actions;
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function account_view_order_link( $order ) {
		if ( ! is_user_logged_in() || ! $order || ! self::customer_can_view_invoice( $order ) ) {
			return;
		}
		echo '<p class="oblt-account-invoice"><a class="button" target="_blank" href="' . esc_url( self::customer_invoice_url( $order->get_id() ) ) . '">' . esc_html__( 'Download / print invoice', 'orderbay-lite' ) . '</a></p>';
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function customer_invoice_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'oblt_customer_invoice' => 1,
					'order_id'              => absint( $order_id ),
				),
				( wc_get_page_permalink( 'myaccount' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) )
			),
			'oblt_customer_invoice_' . absint( $order_id )
		);
	}

	public function handle_customer_invoice() {
		if ( empty( $_GET['oblt_customer_invoice'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		if ( ! $order_id || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'oblt_customer_invoice_' . $order_id ) ) { // phpcs:ignore
			wp_die( esc_html__( 'Invalid invoice request.', 'orderbay-lite' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::customer_can_view_invoice( $order ) ) {
			wp_die( esc_html__( 'You cannot view this invoice.', 'orderbay-lite' ), 403 );
		}
		$number = self::ensure_invoice_number( $order );
		if ( '' === (string) $number ) {
			wp_die( esc_html__( 'Your invoice could not be prepared because a document number could not be allocated. Please try again or contact the store.', 'orderbay-lite' ), 500 );
		}
		$settings = OBLT_Plugin::get_doc_settings();
		$orders   = array( $order );
		include OBLT_Documents::locate_template( 'invoice.php' );
		exit;
	}
}

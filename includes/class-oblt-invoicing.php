<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequential invoice / credit note numbers + customer My Account invoice.
 */
class OBLT_Invoicing {

	private static $instance = null;

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
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$existing = $order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER );
		if ( $existing ) {
			return (string) $existing;
		}
		$number = self::allocate_number( OBLT_Plugin::OPT_INVOICE_PREFIX, OBLT_Plugin::OPT_INVOICE_NEXT, 'INV-', OBLT_Plugin::OPT_INVOICE_FORMAT, OBLT_Plugin::OPT_INVOICE_RESET );
		$order->update_meta_data( OBLT_Plugin::META_INVOICE_NUMBER, $number );
		/* translators: %s: invoice number. */
		$order->add_order_note( sprintf( __( 'OrderBay Lite invoice number assigned: %s', 'orderbay-lite' ), $number ), false, true );
		$order->save();
		return $number;
	}

	/**
	 * Issue credit note number (optional even without refunds if staff forces).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function ensure_credit_number( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$existing = $order->get_meta( OBLT_Plugin::META_CREDIT_NUMBER );
		if ( $existing ) {
			return (string) $existing;
		}
		$number = self::allocate_number( OBLT_Plugin::OPT_CREDIT_PREFIX, OBLT_Plugin::OPT_CREDIT_NEXT, 'CN-', OBLT_Plugin::OPT_CREDIT_FORMAT, OBLT_Plugin::OPT_CREDIT_RESET );
		$order->update_meta_data( OBLT_Plugin::META_CREDIT_NUMBER, $number );
		/* translators: %s: credit note number. */
		$order->add_order_note( sprintf( __( 'OrderBay Lite credit note number issued: %s', 'orderbay-lite' ), $number ), false, true );
		$order->save();
		return $number;
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
	 * Allocate the next gapless sequence number for a document type.
	 *
	 * Race-free: the previous read-modify-write on an option let two concurrent prints
	 * read the same value and both return it (duplicate invoice numbers — a legal problem
	 * for tax invoices). Here the counter is seeded if missing and then incremented
	 * atomically in the database. MySQL: LAST_INSERT_ID() (per-connection). SQLite: INSERT OR IGNORE
	 * plus an integer UPDATE (Playground / reviewer installs). Every caller gets a distinct number.
	 *
	 * @param string $opt_prefix     Option key for the prefix.
	 * @param string $opt_next       Option key for the next integer.
	 * @param string $default_prefix Fallback prefix.
	 * @param string $opt_format     Optional option key for the format template ('' → '{PREFIX}{SEQ}').
	 * @param string $opt_reset      Optional option key for the reset period ('' → 'none').
	 * @return string
	 */
	public static function allocate_number( $opt_prefix, $opt_next, $default_prefix, $opt_format = '', $opt_reset = '' ) {
		global $wpdb;

		$prefix = get_option( $opt_prefix, $default_prefix );
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			$prefix = $default_prefix;
		}

		$template = $opt_format ? (string) get_option( $opt_format, '{PREFIX}{SEQ}' ) : '{PREFIX}{SEQ}';
		if ( '' === trim( $template ) ) {
			$template = '{PREFIX}{SEQ}';
		}
		$reset = $opt_reset ? (string) get_option( $opt_reset, 'none' ) : 'none';

		// Store-local time drives both the counter bucket and the date tokens, so the year
		// printed in the number always matches the bucket it was drawn from (no cross-year gaps).
		$ts     = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time(); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$period = self::period_key( $reset, $ts );

		// Period-scoped counter row when a reset is configured (e.g. oblt_invoice_next_2026);
		// the base row is used unchanged when reset = none.
		$counter_opt = '' !== $period ? $opt_next . '_' . $period : $opt_next;

		// Seed + increment. Both branches use MySQL dialect: the SQLite drop-in
		// (Playground / reviewer) translates MySQL, and rejects SQLite-native SQL
		// (INSERT OR IGNORE, CAST AS INTEGER). MySQL: LAST_INSERT_ID() is per-connection
		// and race-free. SQLite translator: option_value + 1 then SELECT (single-process).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic gapless invoice numbers require a single SQL increment; cache is dropped below.
		$is_sqlite = self::db_is_sqlite();
		if ( $is_sqlite ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'no')",
					$wpdb->options,
					$counter_opt
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET option_value = option_value + 1 WHERE option_name = %s",
					$wpdb->options,
					$counter_opt
				)
			);
			$next = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM %i WHERE option_name = %s",
					$wpdb->options,
					$counter_opt
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'no')",
					$wpdb->options,
					$counter_opt
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s",
					$wpdb->options,
					$counter_opt
				)
			);
			$next = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$seq  = $next - 1;
		if ( $seq < 1 ) {
			$seq = 1;
		}

		// The direct writes bypass the options cache — drop the stale entries.
		wp_cache_delete( $counter_opt, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

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
			self::ensure_credit_number( $order );
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
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id && $customer_id === (int) $user_id ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( $user && $user->user_email && strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) ) {
			// Only match billing email if order has no customer_id (guest later linked) or matches.
			if ( ! $customer_id || $customer_id === (int) $user_id ) {
				return true;
			}
		}
		return false;
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
		self::ensure_invoice_number( $order );
		$settings = OBLT_Plugin::get_doc_settings();
		$orders   = array( $order );
		include OBLT_Documents::locate_template( 'invoice.php' );
		exit;
	}
}

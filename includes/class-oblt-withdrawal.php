<?php
/**
 * EU withdrawal button (Directive (EU) 2023/2673, in force since 19 June 2026).
 *
 * A "Withdraw from contract here" link (site footer, My Account orders) leads to a page
 * with the [orderbay_lite_withdrawal] shortcode. The customer names the order and, if they want, the items,
 * then confirms on a second step ("Confirm withdrawal here"). The store and the customer
 * each get an email; the customer's states what was submitted and when.
 *
 * Signed-in customers confirm straight away for their own orders. Guests prove the order
 * is theirs by following a link sent to the order's billing email, so nobody can withdraw
 * someone else's order by guessing an order number.
 *
 * This is a tool, not legal advice: the store decides the withdrawal period and handles
 * refunds itself.
 *
 * @package OrderBay_Lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OBLT_Withdrawal {

	const OPT          = 'oblt_withdrawal';
	const META_RECORD  = '_oblt_withdrawal';
	const META_PENDING = '_oblt_withdrawal_pending';
	const LINK_TTL     = 7 * DAY_IN_SECONDS;

	/** @var OBLT_Withdrawal|null */
	private static $instance = null;

	/** @var string Message shown above the form after a POST. */
	private $notice = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_shortcode( 'orderbay_lite_withdrawal', array( $this, 'shortcode' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'wp_footer', array( $this, 'footer_link' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_orders_action' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'view_order_link' ), 30 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_panel' ), 40 );
	}

	/* ─── Settings ─────────────────────────────────────────────────────── */

	public static function defaults() {
		return array(
			'enabled'     => '0',
			'days'        => 14,
			'page_id'     => 0,
			'footer_link' => '1',
			'notify'      => '',
		);
	}

	public static function get_settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public function register_settings() {
		register_setting(
			'oblt_documents',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array(
			'enabled'     => empty( $input['enabled'] ) ? '0' : '1',
			'days'        => max( 14, min( 365, isset( $input['days'] ) ? absint( $input['days'] ) : 14 ) ),
			'page_id'     => isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0,
			'footer_link' => empty( $input['footer_link'] ) ? '0' : '1',
			'notify'      => isset( $input['notify'] ) ? sanitize_email( $input['notify'] ) : '',
		);
		// Turning the feature on without a page creates one, so the link always lands somewhere.
		if ( '1' === $out['enabled'] && ( ! $out['page_id'] || 'publish' !== get_post_status( $out['page_id'] ) ) ) {
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Withdraw from contract', 'orderbay-lite' ),
					'post_content' => '<!-- wp:shortcode -->[orderbay_lite_withdrawal]<!-- /wp:shortcode -->',
				)
			);
			$out['page_id'] = is_wp_error( $page_id ) ? 0 : (int) $page_id;
		}
		return $out;
	}

	/**
	 * Settings rows, printed inside the documents settings form.
	 */
	public static function render_settings_fields() {
		$s    = self::get_settings();
		$name = self::OPT;
		echo '<h2>' . esc_html__( 'EU withdrawal button', 'orderbay-lite' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Since 19 June 2026, shops selling online to consumers in the EU must offer an easy way to withdraw from a purchase: a clearly labelled link, a confirmation step, and an emailed acknowledgement. This is a tool, not legal advice.', 'orderbay-lite' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Withdrawal button', 'orderbay-lite' ) . '</th><td><label><input type="hidden" name="' . esc_attr( $name ) . '[enabled]" value="0" /><input type="checkbox" name="' . esc_attr( $name ) . '[enabled]" value="1" ' . checked( '1', $s['enabled'], false ) . ' /> ' . esc_html__( 'Let customers withdraw from orders online', 'orderbay-lite' ) . '</label></td></tr>';
		echo '<tr><th><label for="oblt_wd_days">' . esc_html__( 'Withdrawal period (days)', 'orderbay-lite' ) . '</label></th><td><input type="number" min="14" max="365" id="oblt_wd_days" class="small-text" name="' . esc_attr( $name ) . '[days]" value="' . esc_attr( (string) $s['days'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Counted from the day the order was completed. Orders not yet completed can always be withdrawn. The legal minimum is 14 days.', 'orderbay-lite' ) . '</p></td></tr>';
		echo '<tr><th><label for="oblt_wd_page">' . esc_html__( 'Withdrawal page', 'orderbay-lite' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( $name ) . '[page_id]',
				'id'                => 'oblt_wd_page',
				'selected'          => (int) $s['page_id'],
				'show_option_none'  => esc_html__( 'Create one for me', 'orderbay-lite' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'The page must contain the [orderbay_lite_withdrawal] shortcode.', 'orderbay-lite' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Footer link', 'orderbay-lite' ) . '</th><td><label><input type="hidden" name="' . esc_attr( $name ) . '[footer_link]" value="0" /><input type="checkbox" name="' . esc_attr( $name ) . '[footer_link]" value="1" ' . checked( '1', $s['footer_link'], false ) . ' /> ' . esc_html__( 'Show "Withdraw from contract here" at the bottom of every page', 'orderbay-lite' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The link must stay easy to find. If your theme hides footer output, add the page to your footer menu instead.', 'orderbay-lite' ) . '</p></td></tr>';
		echo '<tr><th><label for="oblt_wd_notify">' . esc_html__( 'Send withdrawals to', 'orderbay-lite' ) . '</label></th><td><input type="email" id="oblt_wd_notify" class="regular-text" name="' . esc_attr( $name ) . '[notify]" value="' . esc_attr( $s['notify'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" /></td></tr>';
		echo '</table>';
	}

	/* ─── Pure helpers (unit-tested) ───────────────────────────────────── */

	/**
	 * Whether an order can still be withdrawn from.
	 *
	 * @param string   $status        Order status without wc- prefix.
	 * @param int|null $completed_ts  Completion time (Unix), null if never completed.
	 * @param int      $days          Withdrawal period in days.
	 * @param int      $now           Current Unix time.
	 * @return string 'ok', 'closed_status' or 'expired'.
	 */
	public static function eligibility( $status, $completed_ts, $days, $now ) {
		if ( in_array( (string) $status, array( 'cancelled', 'refunded', 'failed', 'draft', 'checkout-draft', 'trash' ), true ) ) {
			return 'closed_status';
		}
		if ( $completed_ts && $now > (int) $completed_ts + max( 14, (int) $days ) * DAY_IN_SECONDS ) {
			return 'expired';
		}
		return 'ok';
	}

	/**
	 * Keep only item ids that belong to the order. Empty selection means the whole order.
	 *
	 * @param mixed $raw      Submitted item ids.
	 * @param int[] $item_ids The order's line item ids.
	 * @return int[]
	 */
	public static function sanitize_items( $raw, array $item_ids ) {
		$raw = is_array( $raw ) ? array_map( 'absint', $raw ) : array();
		return array_values( array_intersect( array_map( 'intval', $item_ids ), $raw ) );
	}

	/**
	 * Constant-time, case-insensitive email match.
	 *
	 * @param string $a Email.
	 * @param string $b Email.
	 * @return bool
	 */
	public static function emails_match( $a, $b ) {
		$a = strtolower( trim( (string) $a ) );
		$b = strtolower( trim( (string) $b ) );
		return '' !== $a && '' !== $b && hash_equals( $a, $b );
	}

	/* ─── Links ────────────────────────────────────────────────────────── */

	public static function enabled() {
		$s = self::get_settings();
		return '1' === $s['enabled'] && $s['page_id'] && 'publish' === get_post_status( (int) $s['page_id'] );
	}

	public static function page_url( $args = array() ) {
		$s   = self::get_settings();
		$url = get_permalink( (int) $s['page_id'] );
		return $url ? add_query_arg( $args, $url ) : '';
	}

	public function footer_link() {
		$s = self::get_settings();
		if ( ! self::enabled() || '1' !== $s['footer_link'] || is_page( (int) $s['page_id'] ) ) {
			return;
		}
		echo '<p class="oblt-withdrawal-link" style="text-align:center;margin:1em 0;"><a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Withdraw from contract here', 'orderbay-lite' ) . '</a></p>';
	}

	/**
	 * @param array    $actions Order actions.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public function my_orders_action( $actions, $order ) {
		if ( self::enabled() && $order instanceof WC_Order && ! $order->get_meta( self::META_RECORD ) && 'ok' === $this->order_eligibility( $order ) ) {
			$actions['oblt_withdraw'] = array(
				'url'  => self::page_url( array( 'order' => $order->get_order_number() ) ),
				'name' => __( 'Withdraw', 'orderbay-lite' ),
			);
		}
		return $actions;
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function view_order_link( $order ) {
		if ( ! self::enabled() || ! $order instanceof WC_Order || ! is_account_page() ) {
			return;
		}
		$record = $order->get_meta( self::META_RECORD );
		if ( is_array( $record ) && ! empty( $record['at'] ) ) {
			/* translators: %s: date and time the withdrawal was received */
			echo '<p>' . esc_html( sprintf( __( 'Withdrawal received on %s.', 'orderbay-lite' ), self::format_time( (int) $record['at'] ) ) ) . '</p>';
			return;
		}
		if ( 'ok' === $this->order_eligibility( $order ) ) {
			echo '<p><a class="button" href="' . esc_url( self::page_url( array( 'order' => $order->get_order_number() ) ) ) . '">' . esc_html__( 'Withdraw from contract here', 'orderbay-lite' ) . '</a></p>';
		}
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function admin_order_panel( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$record = $order->get_meta( self::META_RECORD );
		if ( ! is_array( $record ) || empty( $record['at'] ) ) {
			return;
		}
		echo '<div class="form-field form-field-wide" style="margin-top:12px;padding:8px 10px;border-left:4px solid #d63638;background:#fcf0f1;">';
		/* translators: %s: date and time */
		echo '<strong>' . esc_html( sprintf( __( 'Customer withdrew on %s', 'orderbay-lite' ), self::format_time( (int) $record['at'] ) ) ) . '</strong><br />';
		echo esc_html( self::items_label( $order, isset( $record['items'] ) ? (array) $record['items'] : array() ) );
		if ( ! empty( $record['reason'] ) ) {
			echo '<br />' . esc_html__( 'Reason:', 'orderbay-lite' ) . ' ' . esc_html( (string) $record['reason'] );
		}
		echo '</div>';
	}

	/* ─── Front end ────────────────────────────────────────────────────── */

	/**
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function order_eligibility( $order ) {
		$s         = self::get_settings();
		$completed = $order->get_date_completed();
		return self::eligibility( $order->get_status(), $completed ? $completed->getTimestamp() : null, (int) $s['days'], time() );
	}

	/**
	 * Find an order by the number the customer sees (supports sequential-number plugins).
	 *
	 * @param string $number Order number.
	 * @return WC_Order|null
	 */
	private static function find_order( $number ) {
		$number = trim( ltrim( (string) $number, '#' ) );
		if ( '' === $number ) {
			return null;
		}
		$order_id = (int) apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own order-number lookup.
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return null;
		}
		return (string) $order->get_order_number() === $number ? $order : null;
	}

	private static function format_time( $ts ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', (int) $ts );
	}

	/**
	 * @param WC_Order $order    Order.
	 * @param int[]    $item_ids Selected items; empty = whole order.
	 * @return string
	 */
	private static function items_label( $order, array $item_ids ) {
		if ( ! $item_ids ) {
			return __( 'Withdrawal from the whole order.', 'orderbay-lite' );
		}
		$names = array();
		foreach ( $order->get_items() as $id => $item ) {
			if ( in_array( (int) $id, $item_ids, true ) ) {
				$names[] = $item->get_quantity() . ' × ' . $item->get_name();
			}
		}
		/* translators: %s: list of items */
		return sprintf( __( 'Withdrawal from: %s', 'orderbay-lite' ), implode( ', ', $names ) );
	}

	private static function rate_limited() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'oblt_wd_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Handle both form steps before output, so we can redirect.
	 */
	public function handle_post() {
		if ( empty( $_POST['oblt_wd_step'] ) || ! self::enabled() ) {
			return;
		}
		$step = sanitize_key( wp_unslash( $_POST['oblt_wd_step'] ) );
		if ( ! isset( $_POST['_oblt_wd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_oblt_wd_nonce'] ) ), 'oblt_withdrawal_' . $step ) ) {
			$this->notice = __( 'Your session expired. Please try again.', 'orderbay-lite' );
			return;
		}
		if ( 'details' === $step ) {
			$this->handle_details();
		} elseif ( 'confirm' === $step ) {
			$this->handle_confirm();
		}
	}

	private function handle_details() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		$number = isset( $_POST['oblt_wd_order'] ) ? sanitize_text_field( wp_unslash( $_POST['oblt_wd_order'] ) ) : '';
		$email  = isset( $_POST['oblt_wd_email'] ) ? sanitize_email( wp_unslash( $_POST['oblt_wd_email'] ) ) : '';
		$name   = isset( $_POST['oblt_wd_name'] ) ? sanitize_text_field( wp_unslash( $_POST['oblt_wd_name'] ) ) : '';
		$reason = isset( $_POST['oblt_wd_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['oblt_wd_reason'] ) ) : '';
		$raw    = isset( $_POST['oblt_wd_items'] ) ? wp_unslash( $_POST['oblt_wd_items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() in sanitize_items().
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $number || '' === $name || ( ! is_user_logged_in() && '' === $email ) ) {
			$this->notice = __( 'Please enter your name, order number and email address.', 'orderbay-lite' );
			return;
		}
		if ( self::rate_limited() ) {
			$this->notice = __( 'Too many attempts. Please wait an hour or contact us.', 'orderbay-lite' );
			return;
		}

		$order = self::find_order( $number );
		$own   = $order && is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
		$match = $order && ( $own || self::emails_match( $email, $order->get_billing_email() ) );

		if ( $match ) {
			if ( $order->get_meta( self::META_RECORD ) ) {
				$this->notice = __( 'We have already received a withdrawal for this order.', 'orderbay-lite' );
				return;
			}
			$elig = $this->order_eligibility( $order );
			if ( 'ok' !== $elig ) {
				$this->notice = 'expired' === $elig
					? __( 'The withdrawal period for this order has ended. Please contact us if you think this is wrong.', 'orderbay-lite' )
					: __( 'This order is already cancelled or refunded, so there is nothing to withdraw from.', 'orderbay-lite' );
				return;
			}
			$key = wp_generate_password( 32, false, false );
			$order->update_meta_data(
				self::META_PENDING,
				array(
					'key'     => wp_hash_password( $key ),
					'expires' => time() + self::LINK_TTL,
					'name'    => $name,
					'reason'  => $reason,
					'items'   => self::sanitize_items( $raw, array_keys( $order->get_items() ) ),
				)
			);
			$order->save();
			$confirm_url = self::page_url(
				array(
					'oblt_wd' => $order->get_id(),
					'key'     => $key,
				)
			);
			if ( $own ) {
				wp_safe_redirect( $confirm_url );
				exit;
			}
			$this->email(
				$order->get_billing_email(),
				/* translators: %s: order number */
				sprintf( __( 'Confirm your withdrawal from order %s', 'orderbay-lite' ), $order->get_order_number() ),
				'<p>' . esc_html__( 'You asked to withdraw from your order. To finish, open this link and press "Confirm withdrawal here":', 'orderbay-lite' ) . '</p><p><a href="' . esc_url( $confirm_url ) . '">' . esc_html( $confirm_url ) . '</a></p><p>' . esc_html__( 'The link works for 7 days. If you did not ask for this, ignore this email.', 'orderbay-lite' ) . '</p>'
			);
		}
		// Same answer whether or not the details matched, so order numbers can't be probed.
		wp_safe_redirect( self::page_url( array( 'oblt_wd_sent' => 1 ) ) );
		exit;
	}

	/**
	 * Load a pending withdrawal from the link's order id and key.
	 *
	 * @param int    $order_id Order id.
	 * @param string $key      Key from the link.
	 * @return array|null array( order, pending ) or null.
	 */
	private static function pending_from_link( $order_id, $key ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || '' === $key ) {
			return null;
		}
		$p = $order->get_meta( self::META_PENDING );
		if ( ! is_array( $p ) || empty( $p['key'] ) || (int) ( $p['expires'] ?? 0 ) < time() || ! wp_check_password( $key, $p['key'] ) ) {
			return null;
		}
		return array( $order, $p );
	}

	private function handle_confirm() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		$order_id = isset( $_POST['oblt_wd'] ) ? absint( $_POST['oblt_wd'] ) : 0;
		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$found = self::pending_from_link( $order_id, $key );
		if ( ! $found ) {
			$this->notice = __( 'This confirmation link has expired or was already used. Please start again.', 'orderbay-lite' );
			return;
		}
		list( $order, $p ) = $found;
		if ( $order->get_meta( self::META_RECORD ) ) {
			wp_safe_redirect( self::page_url( array( 'oblt_wd_done' => $order->get_id() ) ) );
			exit;
		}

		$record = array(
			'at'     => time(),
			'name'   => (string) $p['name'],
			'email'  => (string) $order->get_billing_email(),
			'items'  => array_map( 'intval', (array) $p['items'] ),
			'reason' => (string) $p['reason'],
		);
		$order->update_meta_data( self::META_RECORD, $record );
		$order->delete_meta_data( self::META_PENDING );
		$order->add_order_note( __( 'Customer withdrew from the contract online.', 'orderbay-lite' ) . ' ' . self::items_label( $order, $record['items'] ) . ( '' !== $record['reason'] ? ' ' . __( 'Reason:', 'orderbay-lite' ) . ' ' . $record['reason'] : '' ) );
		$order->save();

		do_action( 'oblt_withdrawal_received', $order, $record );

		$summary = self::summary_html( $order, $record );
		$this->email(
			$order->get_billing_email(),
			/* translators: %s: order number */
			sprintf( __( 'We received your withdrawal from order %s', 'orderbay-lite' ), $order->get_order_number() ),
			'<p>' . esc_html__( 'This confirms we received your withdrawal. Please keep this email.', 'orderbay-lite' ) . '</p>' . $summary
		);
		$s  = self::get_settings();
		$to = $s['notify'] ? $s['notify'] : (string) get_option( 'admin_email' );
		$this->email(
			$to,
			/* translators: %s: order number */
			sprintf( __( 'Withdrawal received for order %s', 'orderbay-lite' ), $order->get_order_number() ),
			$summary . '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open the order', 'orderbay-lite' ) . '</a></p>'
		);

		set_transient( 'oblt_wd_done_' . $order->get_id(), wp_hash( $key ), HOUR_IN_SECONDS );
		wp_safe_redirect(
			self::page_url(
				array(
					'oblt_wd_done' => $order->get_id(),
					'key'          => $key,
				)
			)
		);
		exit;
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Withdrawal record.
	 * @return string HTML.
	 */
	private static function summary_html( $order, array $record ) {
		$rows = array(
			__( 'Order', 'orderbay-lite' )      => $order->get_order_number(),
			__( 'Name', 'orderbay-lite' )       => $record['name'],
			__( 'Email', 'orderbay-lite' )      => $record['email'],
			__( 'Withdrawal', 'orderbay-lite' ) => self::items_label( $order, (array) $record['items'] ),
			__( 'Received', 'orderbay-lite' )   => self::format_time( (int) $record['at'] ),
		);
		if ( '' !== (string) $record['reason'] ) {
			$rows[ __( 'Reason', 'orderbay-lite' ) ] = $record['reason'];
		}
		$html = '<table cellspacing="0" cellpadding="6" style="border-collapse:collapse;">';
		foreach ( $rows as $k => $v ) {
			$html .= '<tr><th style="text-align:left;border:1px solid #ddd;">' . esc_html( $k ) . '</th><td style="border:1px solid #ddd;">' . esc_html( (string) $v ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $html    Body HTML (already escaped).
	 */
	private function email( $to, $subject, $html ) {
		if ( ! is_email( $to ) ) {
			return;
		}
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$mailer = WC()->mailer();
			$mailer->send( $to, $subject, $mailer->wrap_message( $subject, $html ), array( 'Content-Type: text/html; charset=UTF-8' ) );
			return;
		}
		wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * [orderbay_lite_withdrawal] — the withdrawal form, confirmation step and receipt.
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( ! self::enabled() || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views; links carry their own key.
		$done = isset( $_GET['oblt_wd_done'] ) ? absint( $_GET['oblt_wd_done'] ) : 0;
		$id   = isset( $_GET['oblt_wd'] ) ? absint( $_GET['oblt_wd'] ) : 0;
		$key  = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$sent = ! empty( $_GET['oblt_wd_sent'] );
		$pre  = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		ob_start();
		echo '<div class="oblt-withdrawal">';
		if ( $this->notice ) {
			echo '<p class="woocommerce-error" role="alert">' . esc_html( $this->notice ) . '</p>';
		}

		if ( $done ) {
			$order  = wc_get_order( $done );
			$record = $order ? $order->get_meta( self::META_RECORD ) : null;
			$proof  = get_transient( 'oblt_wd_done_' . $done );
			if ( $order && is_array( $record ) && $proof && hash_equals( (string) $proof, wp_hash( $key ) ) ) {
				echo '<p class="woocommerce-message">' . esc_html__( 'Your withdrawal has been received. We have emailed you a confirmation.', 'orderbay-lite' ) . '</p>';
				echo wp_kses_post( self::summary_html( $order, $record ) );
			} else {
				echo '<p>' . esc_html__( 'Your withdrawal has been received. Check your email for the confirmation.', 'orderbay-lite' ) . '</p>';
			}
		} elseif ( $id ) {
			$found = self::pending_from_link( $id, $key );
			if ( ! $found ) {
				echo '<p class="woocommerce-error">' . esc_html__( 'This confirmation link has expired or was already used. Please start again below.', 'orderbay-lite' ) . '</p>';
				$this->details_form( $pre );
			} else {
				list( $order, $p ) = $found;
				echo '<h3>' . esc_html__( 'Check and confirm your withdrawal', 'orderbay-lite' ) . '</h3>';
				echo wp_kses_post(
					self::summary_html(
						$order,
						array(
							'at'     => time(),
							'name'   => $p['name'],
							'email'  => $order->get_billing_email(),
							'items'  => (array) $p['items'],
							'reason' => (string) $p['reason'],
						)
					)
				);
				echo '<form method="post" style="margin-top:1em;">';
				wp_nonce_field( 'oblt_withdrawal_confirm', '_oblt_wd_nonce' );
				echo '<input type="hidden" name="oblt_wd_step" value="confirm" /><input type="hidden" name="oblt_wd" value="' . esc_attr( (string) $order->get_id() ) . '" /><input type="hidden" name="key" value="' . esc_attr( $key ) . '" />';
				echo '<p><button type="submit" class="button alt">' . esc_html__( 'Confirm withdrawal here', 'orderbay-lite' ) . '</button></p>';
				echo '</form>';
			}
		} elseif ( $sent ) {
			echo '<p class="woocommerce-message">' . esc_html__( 'Thank you. If the details match an order, we have sent a confirmation link to the order\'s email address. Open it to confirm your withdrawal.', 'orderbay-lite' ) . '</p>';
		} else {
			$this->details_form( $pre );
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Step one: which order, who, and optionally which items and why.
	 *
	 * @param string $prefill Order number to prefill.
	 */
	private function details_form( $prefill ) {
		$user  = wp_get_current_user();
		$order = ( $prefill && is_user_logged_in() ) ? self::find_order( $prefill ) : null;
		if ( $order && (int) $order->get_customer_id() !== get_current_user_id() ) {
			$order = null;
		}
		$name = $order ? trim( $order->get_formatted_billing_full_name() ) : ( $user && $user->exists() ? $user->display_name : '' );

		echo '<p>' . esc_html__( 'Use this form to withdraw from a purchase. Choosing items is optional; leave them all unticked to withdraw from the whole order.', 'orderbay-lite' ) . '</p>';
		echo '<form method="post" class="oblt-withdrawal-form">';
		wp_nonce_field( 'oblt_withdrawal_details', '_oblt_wd_nonce' );
		echo '<input type="hidden" name="oblt_wd_step" value="details" />';
		echo '<p><label>' . esc_html__( 'Your name', 'orderbay-lite' ) . '<br /><input type="text" name="oblt_wd_name" required autocomplete="name" value="' . esc_attr( $name ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Order number', 'orderbay-lite' ) . '<br /><input type="text" name="oblt_wd_order" required value="' . esc_attr( $prefill ) . '" /></label></p>';
		if ( ! $order ) {
			echo '<p><label>' . esc_html__( 'Email address used for the order', 'orderbay-lite' ) . '<br /><input type="email" name="oblt_wd_email" autocomplete="email" ' . ( is_user_logged_in() ? '' : 'required ' ) . 'value="' . esc_attr( $user && $user->exists() ? $user->user_email : '' ) . '" /></label></p>';
		}
		if ( $order ) {
			echo '<fieldset><legend>' . esc_html__( 'Items (optional)', 'orderbay-lite' ) . '</legend>';
			foreach ( $order->get_items() as $item_id => $item ) {
				echo '<label style="display:block;"><input type="checkbox" name="oblt_wd_items[]" value="' . esc_attr( (string) $item_id ) . '" /> ' . esc_html( $item->get_quantity() . ' × ' . $item->get_name() ) . '</label>';
			}
			echo '</fieldset>';
		}
		echo '<p><label>' . esc_html__( 'Reason (optional)', 'orderbay-lite' ) . '<br /><textarea name="oblt_wd_reason" rows="3"></textarea></label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Withdraw from contract', 'orderbay-lite' ) . '</button></p>';
		echo '</form>';
	}
}

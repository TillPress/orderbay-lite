<?php
/**
 * WordPress.org review notice for OrderBay Lite.
 *
 * @package OrderBayLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks for a wordpress.org review once the store has really used Lite.
 *
 * Shown to administrators only when at least 10 real (non-demo) orders have an
 * OrderBay Lite invoice number and 14 days have passed since install. Every
 * merchant sees the same choices (review, get help, later, dismiss): there is
 * no rating question and no routing of unhappy users away from the review
 * page, which wordpress.org forbids. "Later" can be used once and hides the
 * notice for 30 days; after that only "Dismiss" remains, and dismissal is
 * permanent. No outbound HTTP.
 */
class OBLT_Review_Nudge {

	const MIN_INVOICES = 10;
	const MIN_DAYS     = 14;
	const SNOOZE_DAYS  = 30;

	const OPTION_INSTALLED_AT = 'oblt_installed_at';
	const OPTION_DISMISSED    = 'oblt_review_nudge_dismissed';
	const OPTION_SNOOZED      = 'oblt_review_nudge_snoozed_until';

	const REVIEW_URL = 'https://wordpress.org/support/plugin/orderbay-lite/reviews/#new-post';
	const ACTION     = 'oblt_review_nudge';

	/**
	 * Singleton instance.
	 *
	 * @var OBLT_Review_Nudge|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return OBLT_Review_Nudge
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire admin hooks and record install time if missing.
	 */
	private function __construct() {
		self::maybe_store_install_time();
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Store the install timestamp once if it is missing.
	 */
	public static function maybe_store_install_time() {
		if ( false === get_option( self::OPTION_INSTALLED_AT, false ) ) {
			add_option( self::OPTION_INSTALLED_AT, time(), '', false );
		}
	}

	/**
	 * Pure threshold check, kept free of WordPress calls for the test suite.
	 *
	 * @param int  $invoice_count Real orders with a Lite invoice number.
	 * @param int  $installed_at  Unix timestamp of first install.
	 * @param int  $now           Current unix timestamp.
	 * @param bool $dismissed     Whether the notice was dismissed for good.
	 * @param int  $snoozed_until Unix timestamp the "later" snooze ends (0 = never snoozed).
	 * @return bool
	 */
	public static function should_show( $invoice_count, $installed_at, $now, $dismissed, $snoozed_until = 0 ) {
		if ( $dismissed ) {
			return false;
		}
		if ( (int) $invoice_count < self::MIN_INVOICES ) {
			return false;
		}
		$installed_at = (int) $installed_at;
		if ( $installed_at <= 0 || (int) $now - $installed_at < self::MIN_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		if ( (int) $snoozed_until > (int) $now ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the one allowed "later" snooze is still available.
	 *
	 * @param int $snoozed_until Stored snooze end (0 = never snoozed).
	 * @return bool
	 */
	public static function can_snooze( $snoozed_until ) {
		return (int) $snoozed_until <= 0;
	}

	/**
	 * Real orders with a Lite invoice number, counted up to the threshold only.
	 *
	 * @return int
	 */
	public function invoice_count() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$cached = get_transient( 'oblt_review_nudge_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$ids   = wc_get_orders(
			array(
				'limit'        => self::MIN_INVOICES + 10,
				'return'       => 'ids',
				'type'         => 'shop_order',
				'meta_key'     => OBLT_Plugin::META_INVOICE_NUMBER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);
		$count = 0;
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order && ! $order->get_meta( '_oblt_demo' ) ) {
				++$count;
			}
		}
		set_transient( 'oblt_review_nudge_count', $count, $count >= self::MIN_INVOICES ? WEEK_IN_SECONDS : DAY_IN_SECONDS );
		return $count;
	}

	/**
	 * Handle "later" and "dismiss" links (GET + nonce).
	 */
	public function maybe_handle_action() {
		if ( ! isset( $_GET[ self::ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::ACTION );
		$what = sanitize_key( wp_unslash( $_GET[ self::ACTION ] ) );
		if ( 'later' === $what && self::can_snooze( (int) get_option( self::OPTION_SNOOZED, 0 ) ) ) {
			update_option( self::OPTION_SNOOZED, time() + self::SNOOZE_DAYS * DAY_IN_SECONDS, false );
		} else {
			update_option( self::OPTION_DISMISSED, 1, false );
		}
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}
		wp_safe_redirect( remove_query_arg( array( self::ACTION, '_wpnonce' ), $redirect ) );
		exit;
	}

	/**
	 * Render the notice on the dashboard, plugins, orders and Lite screens.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ok     = array( 'dashboard', 'plugins', 'edit-shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_orderbay-lite', 'woocommerce_page_oblt-help' );
		if ( ! $screen || ! in_array( $screen->id, $ok, true ) ) {
			return;
		}
		$dismissed = (bool) get_option( self::OPTION_DISMISSED, 0 );
		$snoozed   = (int) get_option( self::OPTION_SNOOZED, 0 );
		$installed = (int) get_option( self::OPTION_INSTALLED_AT, 0 );
		if ( $dismissed || $installed <= 0 || time() - $installed < self::MIN_DAYS * DAY_IN_SECONDS || $snoozed > time() ) {
			return;
		}
		$count = $this->invoice_count();
		if ( ! self::should_show( $count, $installed, time(), $dismissed, $snoozed ) ) {
			return;
		}

		$help_url    = OBLT_Hub::page_url();
		$later_url   = wp_nonce_url( add_query_arg( self::ACTION, 'later' ), self::ACTION );
		$dismiss_url = wp_nonce_url( add_query_arg( self::ACTION, 'dismiss' ), self::ACTION );

		echo '<div class="notice notice-info oblt-review-nudge"><p>';
		echo esc_html__( 'OrderBay Lite has numbered and sent your invoices for a while now. If it has been useful, a short review on WordPress.org helps other store owners find it. If something is not working, tell us and we will help.', 'orderbay-lite' );
		echo '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Leave a review', 'orderbay-lite' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $help_url ) . '">' . esc_html__( 'Get help', 'orderbay-lite' ) . '</a> ';
		if ( self::can_snooze( $snoozed ) ) {
			echo '<a href="' . esc_url( $later_url ) . '">' . esc_html__( 'Maybe later', 'orderbay-lite' ) . '</a> &middot; ';
		}
		echo '<a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'orderbay-lite' ) . '</a>';
		echo '</p></div>';
	}
}

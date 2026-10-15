<?php
/**
 * Help & demo page for OrderBay Lite: support ticket composer, demo data, and the
 * optional offer for the paid plugin.
 *
 * The only outbound request is the support ticket (OBLT_Support), sent to
 * tillpress.com when an administrator ticks the consent box and presses Send.
 *
 * @package OrderBayLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help & demo admin page.
 */
class OBLT_Hub {

	const PAGE         = 'oblt-help';
	const SUPPORT_MAIL = 'info@tillpress.com';
	const SUPPORT_URL  = 'https://tillpress.com/support/';
	const DOCS_URL     = 'https://tillpress.com/orderbay-lite/';
	const FORUM_URL    = 'https://wordpress.org/support/plugin/orderbay-lite/';
	const PRO_URL      = 'https://tillpress.com/orderbay/';
	const OFFER_CODE   = 'LITE20';
	const OFFER_META   = 'oblt_offer_dismissed';

	/**
	 * Singleton instance.
	 *
	 * @var OBLT_Hub|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return OBLT_Hub
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 56 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_oblt_demo_create', array( $this, 'handle_demo_create' ) );
		add_action( 'admin_post_oblt_demo_remove', array( $this, 'handle_demo_remove' ) );
		add_action( 'admin_post_oblt_offer_dismiss', array( $this, 'handle_offer_dismiss' ) );
		add_filter( 'plugin_action_links_' . OBLT_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Capability required for the page and its actions.
	 *
	 * @return string
	 */
	public static function capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Register the submenu page.
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'OrderBay Lite help & demo', 'orderbay-lite' ),
			__( 'OrderBay Lite help', 'orderbay-lite' ),
			self::capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Admin URL of this page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Plugins-screen link.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Help & demo', 'orderbay-lite' ) . '</a>';
		return $links;
	}

	/**
	 * Enqueue page assets only on this screen.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function assets( $hook ) {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'oblt-hub', OBLT_PLUGIN_URL . 'assets/hub.css', array(), OBLT_VERSION );
		OBLT_Support::enqueue();
	}

	/**
	 * Whether the offer box should show (pure).
	 *
	 * @param bool $dismissed Whether this user dismissed it.
	 * @return bool
	 */
	public static function should_show_offer( $dismissed ) {
		return ! $dismissed;
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
	 * Full system report text for support tickets.
	 *
	 * @return string
	 */
	public static function system_report() {
		return self::format_report( array_merge( self::environment_lines(), OBLT_Demo::report_lines() ) );
	}

	/**
	 * Redirect back to this page with a status flag.
	 *
	 * @param string $state Status key.
	 */
	private function back( $state ) {
		wp_safe_redirect( self::page_url( array( 'oblt_hub' => $state ) ) );
		exit;
	}

	/**
	 * Guard for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'orderbay-lite' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Create demo data.
	 */
	public function handle_demo_create() {
		$this->guard( 'oblt_demo_create' );
		$result = OBLT_Demo::create();
		$this->back( is_wp_error( $result ) ? 'failed' : 'created' );
	}

	/**
	 * Remove demo data.
	 */
	public function handle_demo_remove() {
		$this->guard( 'oblt_demo_remove' );
		OBLT_Demo::remove();
		$this->back( 'removed' );
	}

	/**
	 * Hide the offer box for the current user.
	 */
	public function handle_offer_dismiss() {
		$this->guard( 'oblt_offer_dismiss' );
		update_user_meta( get_current_user_id(), self::OFFER_META, 1 );
		$this->back( 'offer_hidden' );
	}

	/**
	 * One-button form posting to admin-post.php.
	 *
	 * @param string $action Action name (also the nonce action).
	 * @param string $label  Button label.
	 * @param string $class  Button class.
	 */
	private function action_button( $action, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oblt-hub-inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Status notice after an action.
	 */
	private function render_status() {
		$state = isset( $_GET['oblt_hub'] ) ? sanitize_key( wp_unslash( $_GET['oblt_hub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$map   = array(
			'created'      => array( 'success', __( 'Demo data created.', 'orderbay-lite' ) ),
			'removed'      => array( 'success', __( 'Demo data removed.', 'orderbay-lite' ) ),
			'failed'       => array( 'error', __( 'Demo data could not be created. Check that WooCommerce is active, then try again.', 'orderbay-lite' ) ),
			'offer_hidden' => array( 'success', __( 'Offer hidden. It will not show again for your account.', 'orderbay-lite' ) ),
		);
		if ( isset( $map[ $state ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $state ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $state ][1] ) . '</p></div>';
		}
	}

	/**
	 * Render the page.
	 */
	public function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'orderbay-lite' ) );
		}
		echo '<div class="wrap oblt-hub">';
		echo '<h1>' . esc_html__( 'OrderBay Lite help & demo', 'orderbay-lite' ) . '</h1>';
		$this->render_status();
		echo '<div class="oblt-hub-grid">';

		// Demo.
		echo '<section class="oblt-hub-card"><h2>' . esc_html__( 'Try it with demo data', 'orderbay-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Creates one completed demo order with an invoice number from a separate DEMO- sequence, so you can open the invoice, the UBL and CII e-invoice XML, and a credit note without touching your real invoice numbers.', 'orderbay-lite' ) . '</p>';
		if ( OBLT_Demo::exists() ) {
			echo '<ul class="oblt-hub-list">';
			foreach ( OBLT_Demo::links() as $link ) {
				echo '<li><a href="' . esc_url( $link['url'] ) . '"' . ( ! empty( $link['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $link['label'] ) . '</a></li>';
			}
			echo '</ul>';
			OBLT_Demo::render_extra();
			$this->action_button( 'oblt_demo_remove', __( 'Remove demo data', 'orderbay-lite' ) );
		} else {
			$this->action_button( 'oblt_demo_create', __( 'Create demo data', 'orderbay-lite' ), 'button button-primary' );
		}
		echo '<p class="description">' . esc_html__( 'Demo data is labelled "Demo", is never emailed or texted, and is deleted with one click here (and when you uninstall the plugin).', 'orderbay-lite' ) . '</p>';
		echo '</section>';

		// Support.
		echo '<section class="oblt-hub-card"><h2>' . esc_html__( 'Get support', 'orderbay-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Send a ticket straight to the Tillpress team from here. You get a ticket number right away and the reply comes by email. Nothing is sent until you tick the consent box and press Send.', 'orderbay-lite' ) . '</p>';
		OBLT_Support::instance()->render_form();
		echo '<p>' . esc_html__( 'Other ways to reach us:', 'orderbay-lite' ) . ' ';
		echo '<a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'orderbay-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( self::FORUM_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress.org support forum', 'orderbay-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support form on tillpress.com', 'orderbay-lite' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( 'mailto:' . self::SUPPORT_MAIL ) . '">' . esc_html( self::SUPPORT_MAIL ) . '</a></p>';
		echo '</section>';

		// Offer.
		if ( self::should_show_offer( (bool) get_user_meta( get_current_user_id(), self::OFFER_META, true ) ) ) {
			echo '<section class="oblt-hub-card oblt-hub-offer"><h2>' . esc_html__( 'OrderBay: 20% off for OrderBay Lite users', 'orderbay-lite' ) . '</h2>';
			echo '<p>' . esc_html__( 'Adds packing slips, delivery notes, shipping labels, proforma invoices, bulk printing, barcodes/QR, bundled PDF, and a fulfillment desk.', 'orderbay-lite' ) . '</p>';
			echo '<p>' . esc_html__( 'Price: $79/year (or $10 for one month). Enter this code at checkout on tillpress.com for 20% off a yearly license:', 'orderbay-lite' ) . ' <code class="oblt-hub-code">' . esc_html( self::OFFER_CODE ) . '</code></p>';
			echo '<p class="oblt-hub-actions"><a class="button button-primary" href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See OrderBay', 'orderbay-lite' ) . '</a> ';
			$this->action_button( 'oblt_offer_dismiss', __( 'Hide this offer', 'orderbay-lite' ), 'button-link' );
			echo '</p>';
			echo '<p class="description">' . esc_html__( 'OrderBay Lite is complete and is never locked. The paid plugin is a separate download; this code is optional.', 'orderbay-lite' ) . '</p>';
			echo '</section>';
		}

		echo '</div></div>';
	}
}

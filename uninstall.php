<?php
/**
 * Uninstall OrderBay Lite.
 *
 * Removes branding, demo data and per-user offer dismissal.
 * Does not touch the paid OrderBay plugin's ob_ options.
 *
 * Numbering settings and counters (invoice / credit prefix, format, reset,
 * next, including period-scoped rows such as oblt_invoice_next_2026) are
 * retained on a normal uninstall so reinstall cannot reissue INV-1 while
 * order meta still holds INV-1.
 *
 * Assigned invoice / credit note numbers stored in order meta are kept —
 * they are business records a store may be legally required to retain.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$oblt_options = array(
	'oblt_documents_settings',
);

foreach ( $oblt_options as $oblt_key ) {
	delete_option( $oblt_key );
}

// Numbering is retained: prefix, format, reset, next counters, and
// period-scoped next rows. There is no LIKE-sweep of those rows.

// Help & demo page: demo data (only objects carrying the demo marker) and the per-user offer dismissal.
$oblt_demo = get_option( 'oblt_demo_ids', array() );
if ( is_array( $oblt_demo ) ) {
	foreach ( (array) ( $oblt_demo['orders'] ?? array() ) as $oblt_id ) {
		$oblt_order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $oblt_id ) ) : null;
		if ( $oblt_order && $oblt_order->get_meta( '_oblt_demo' ) ) {
			foreach ( $oblt_order->get_refunds() as $oblt_refund ) {
				$oblt_refund->delete( true );
			}
			$oblt_order->delete( true );
		}
	}
}
delete_option( 'oblt_demo_ids' );
delete_metadata( 'user', 0, 'oblt_offer_dismissed', '', true );

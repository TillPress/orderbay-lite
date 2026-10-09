<?php
/**
 * Uninstall OrderBay Lite.
 *
 * Removes all oblt_ options, including the period-scoped counter rows
 * (e.g. oblt_invoice_next_2026) created by yearly/monthly resets.
 * Does not touch the paid OrderBay plugin's ob_ options.
 *
 * Assigned invoice / credit note numbers stored in order meta are kept —
 * they are business records a store may be legally required to retain.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$oblt_options = array(
	'oblt_documents_settings',
	'oblt_invoice_prefix',
	'oblt_invoice_next',
	'oblt_invoice_format',
	'oblt_invoice_reset',
	'oblt_credit_prefix',
	'oblt_credit_next',
	'oblt_credit_format',
	'oblt_credit_reset',
);

foreach ( $oblt_options as $oblt_key ) {
	delete_option( $oblt_key );
}

global $wpdb;
if ( isset( $wpdb ) ) {
	// Period-scoped counter rows (oblt_invoice_next_2026, oblt_credit_next_202605, ...)
	// are created directly in the options table by the atomic allocator.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'oblt_invoice_next_' ) . '%',
			$wpdb->esc_like( 'oblt_credit_next_' ) . '%'
		)
	);
}

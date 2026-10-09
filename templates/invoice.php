<?php
/**
 * Invoice print sheet (0.2.0).
 *
 * @var WC_Order[] $orders
 * @var array      $settings
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$oblt_paper = ( isset( $settings['paper'] ) && 'a4' === $settings['paper'] ) ? 'A4' : 'letter';
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html__( 'Invoice', 'orderbay-lite' ); ?></title>
	<style>
		@page { size: <?php echo esc_attr( $oblt_paper ); ?>; margin: 12mm; }
		* { box-sizing: border-box; }
		body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 12.5px; color: #111; margin: 16px; line-height: 1.4; }
		.sheet { page-break-after: always; max-width: 190mm; margin: 0 auto 28px; }
		.sheet:last-child { page-break-after: auto; }
		.header { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 16px; align-items: flex-start; }
		.logo { max-height: 56px; max-width: 200px; }
		h1 { font-size: 20px; margin: 0 0 4px; }
		.meta { color: #555; margin-bottom: 4px; }
		.cols { display: flex; gap: 24px; margin-bottom: 16px; }
		.cols > div { flex: 1; white-space: pre-line; }
		.cols strong { display: block; margin-bottom: 4px; }
		table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
		table.items th, table.items td { border-bottom: 1px solid #ddd; padding: 7px 6px; text-align: left; vertical-align: top; }
		table.items th { border-bottom: 2px solid #222; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
		table.items .num { text-align: right; white-space: nowrap; }
		.totals { margin-top: 12px; margin-left: auto; width: 260px; }
		.totals .row { display: flex; justify-content: space-between; padding: 3px 0; }
		.totals .row.grand { font-weight: 700; font-size: 14px; border-top: 2px solid #222; margin-top: 6px; padding-top: 8px; }
		.notes { margin-top: 16px; padding: 8px 10px; background: #f6f7f7; border: 1px solid #e0e0e0; }
		.footer { margin-top: 20px; color: #666; font-size: 11px; border-top: 1px solid #eee; padding-top: 8px; }
		@media print {
			.no-print, #wpadminbar, .woocommerce-store-notice { display: none !important; }
			body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
			.sheet { page-break-after: always; break-after: page; }
			.sheet:last-child { page-break-after: auto; break-after: auto; }
		}
	</style>
</head>
<body>
<p class="no-print">
	<button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'orderbay-lite' ); ?></button>
		<span><?php
		/* translators: 1: document count, 2: paper size. */
		echo esc_html( sprintf( __( '%1$d document(s) · paper: %2$s', 'orderbay-lite' ), count( $orders ), $oblt_paper ) );
		?></span>
</p>
<?php foreach ( $orders as $oblt_order ) : ?>
	<div class="sheet">
		<div class="header">
			<div>
				<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
					<img class="logo" src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" />
				<?php endif; ?>
				<?php $oblt_inv_no = $oblt_order->get_meta( OBLT_Plugin::META_INVOICE_NUMBER ); ?>
				<h1><?php
				if ( $oblt_inv_no ) {
					/* translators: 1: invoice number, 2: order number. */
					echo esc_html( sprintf( __( 'Invoice %1$s — Order #%2$s', 'orderbay-lite' ), $oblt_inv_no, $oblt_order->get_order_number() ) );
				} else {
					/* translators: %s: order number. */
					echo esc_html( sprintf( __( 'Invoice — Order #%s', 'orderbay-lite' ), $oblt_order->get_order_number() ) );
				}
				?></h1>
				<?php if ( $oblt_inv_no ) : ?><div class="meta"><strong><?php esc_html_e( 'Invoice number', 'orderbay-lite' ); ?>:</strong> <?php echo esc_html( $oblt_inv_no ); ?></div><?php endif; ?>
				<div class="meta">
					<?php echo esc_html( $oblt_order->get_date_created() ? $oblt_order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '' ); ?>
					· <?php echo esc_html( wc_get_order_status_name( $oblt_order->get_status() ) ); ?>
					· <?php echo esc_html( $oblt_order->get_currency() ); ?>
				</div>
			</div>
			<div class="from" style="white-space:pre-line;text-align:right;"><strong><?php esc_html_e( 'From', 'orderbay-lite' ); ?></strong><br /><?php echo esc_html( $settings['from_lines'] ); ?>
				<?php if ( ! empty( $settings['tax_id'] ) ) : ?><br /><strong><?php esc_html_e( 'VAT / Tax ID', 'orderbay-lite' ); ?>:</strong> <?php echo esc_html( $settings['tax_id'] ); ?><?php endif; ?>
			</div>
		</div>
		<div class="cols">
			<div><strong><?php esc_html_e( 'Bill to', 'orderbay-lite' ); ?></strong><?php echo wp_kses_post( $oblt_order->get_formatted_billing_address() ? $oblt_order->get_formatted_billing_address() : esc_html( $oblt_order->get_formatted_billing_full_name() ) ); ?>
				<?php if ( $oblt_order->get_billing_email() ) : ?><br /><?php echo esc_html( $oblt_order->get_billing_email() ); ?><?php endif; ?>
				<?php if ( $oblt_order->get_billing_phone() ) : ?><br /><?php echo esc_html( $oblt_order->get_billing_phone() ); ?><?php endif; ?>
			</div>
			<div><strong><?php esc_html_e( 'Ship to', 'orderbay-lite' ); ?></strong><?php
				$oblt_ship = $oblt_order->get_formatted_shipping_address();
				echo wp_kses_post( $oblt_ship ? $oblt_ship : '—' );
			?></div>
		</div>
		<table class="items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'orderbay-lite' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'orderbay-lite' ); ?></th>
					<th class="num"><?php esc_html_e( 'Qty', 'orderbay-lite' ); ?></th>
					<th class="num"><?php esc_html_e( 'Total', 'orderbay-lite' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $oblt_order->get_items() as $oblt_item ) : ?>
				<?php
				$oblt_product = $oblt_item->get_product();
				$oblt_sku     = $oblt_product ? $oblt_product->get_sku() : '';
				?>
				<tr>
					<td><?php echo esc_html( $oblt_item->get_name() ); ?></td>
					<td><?php echo esc_html( $oblt_sku ? $oblt_sku : '—' ); ?></td>
					<td class="num"><?php echo esc_html( (string) $oblt_item->get_quantity() ); ?></td>
					<td class="num"><?php echo wp_kses_post( $oblt_order->get_formatted_line_subtotal( $oblt_item ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="totals">
			<div class="row"><span><?php esc_html_e( 'Subtotal', 'orderbay-lite' ); ?></span><span><?php echo wp_kses_post( $oblt_order->get_subtotal_to_display() ); ?></span></div>
			<?php if ( (float) $oblt_order->get_shipping_total() > 0 ) : ?>
				<div class="row"><span><?php esc_html_e( 'Shipping', 'orderbay-lite' ); ?></span><span><?php echo wp_kses_post( wc_price( $oblt_order->get_shipping_total(), array( 'currency' => $oblt_order->get_currency() ) ) ); ?></span></div>
			<?php endif; ?>
			<?php
			$oblt_tax_rows = OBLT_Documents::normalize_tax_rows( $oblt_order->get_tax_totals() );
			if ( $oblt_tax_rows ) :
				foreach ( $oblt_tax_rows as $oblt_tr ) :
					?>
					<div class="row"><span><?php echo esc_html( $oblt_tr['label'] ); ?></span><span><?php echo wp_kses_post( wc_price( $oblt_tr['amount'], array( 'currency' => $oblt_order->get_currency() ) ) ); ?></span></div>
					<?php
				endforeach;
				$oblt_incl = ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) ? __( 'Prices include tax', 'orderbay-lite' ) : __( 'Prices exclude tax', 'orderbay-lite' );
				?>
				<div class="row"><span class="tax-basis"><small><?php echo esc_html( $oblt_incl ); ?></small></span><span></span></div>
			<?php elseif ( (float) $oblt_order->get_total_tax() > 0 ) : ?>
				<div class="row"><span><?php esc_html_e( 'Tax', 'orderbay-lite' ); ?></span><span><?php echo wp_kses_post( wc_price( $oblt_order->get_total_tax(), array( 'currency' => $oblt_order->get_currency() ) ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( (float) $oblt_order->get_discount_total() > 0 ) : ?>
				<div class="row"><span><?php esc_html_e( 'Discount', 'orderbay-lite' ); ?></span><span>−<?php echo wp_kses_post( wc_price( $oblt_order->get_discount_total(), array( 'currency' => $oblt_order->get_currency() ) ) ); ?></span></div>
			<?php endif; ?>
			<?php foreach ( $oblt_order->get_fees() as $oblt_fee_item ) : ?>
				<div class="row"><span><?php echo esc_html( $oblt_fee_item->get_name() ); ?></span><span><?php echo wp_kses_post( wc_price( (float) $oblt_fee_item->get_total(), array( 'currency' => $oblt_order->get_currency() ) ) ); ?></span></div>
			<?php endforeach; ?>
			<div class="row grand"><span><?php esc_html_e( 'Total', 'orderbay-lite' ); ?></span><span><?php echo wp_kses_post( $oblt_order->get_formatted_order_total() ); ?></span></div>
		</div>
		<?php if ( $oblt_order->get_customer_note() ) : ?>
			<div class="notes"><strong><?php esc_html_e( 'Customer note', 'orderbay-lite' ); ?>:</strong> <?php echo esc_html( $oblt_order->get_customer_note() ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $settings['footer_text'] ) || ! empty( $settings['tax_id'] ) ) : ?>
			<div class="footer">
				<?php if ( ! empty( $settings['footer_text'] ) ) : ?><?php echo esc_html( $settings['footer_text'] ); ?><?php endif; ?>
				<?php if ( ! empty( $settings['tax_id'] ) ) : ?><?php echo $settings['footer_text'] ? '<br />' : ''; ?><strong><?php esc_html_e( 'VAT / Tax ID', 'orderbay-lite' ); ?>:</strong> <?php echo esc_html( $settings['tax_id'] ); ?><?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
</body>
</html>

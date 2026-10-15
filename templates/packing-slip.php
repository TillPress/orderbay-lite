<?php
/**
 * Packing slip print sheet: what is in the box and where it goes, no prices.
 *
 * Override by copying to yourtheme/orderbay-lite/packing-slip.php.
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
	<title><?php echo esc_html__( 'Packing slip', 'orderbay-lite' ); ?></title>
	<?php OBLT_Documents::print_document_assets( 'packing-slip', $oblt_paper ); ?>
</head>
<body>
<p class="no-print">
	<button type="button" class="oblt-print-btn"><?php esc_html_e( 'Print / Save as PDF', 'orderbay-lite' ); ?></button>
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
				<h1><?php
				/* translators: %s: order number. */
				echo esc_html( sprintf( __( 'Packing slip — Order #%s', 'orderbay-lite' ), $oblt_order->get_order_number() ) );
				?></h1>
				<div class="meta">
					<?php echo esc_html( $oblt_order->get_date_created() ? $oblt_order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ); ?>
					<?php if ( $oblt_order->get_shipping_method() ) : ?>
						· <?php echo esc_html( $oblt_order->get_shipping_method() ); ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="from" style="white-space:pre-line;text-align:right;"><strong><?php esc_html_e( 'From', 'orderbay-lite' ); ?></strong><br /><?php echo esc_html( $settings['from_lines'] ); ?></div>
		</div>
		<div class="cols">
			<div><strong><?php esc_html_e( 'Ship to', 'orderbay-lite' ); ?></strong><?php
				$oblt_ship = $oblt_order->get_formatted_shipping_address();
				if ( ! $oblt_ship ) {
					$oblt_ship = $oblt_order->get_formatted_billing_address();
				}
				echo wp_kses_post( $oblt_ship ? $oblt_ship : esc_html( $oblt_order->get_formatted_billing_full_name() ) );
			?>
				<?php if ( $oblt_order->get_shipping_phone() || $oblt_order->get_billing_phone() ) : ?><br /><?php echo esc_html( $oblt_order->get_shipping_phone() ? $oblt_order->get_shipping_phone() : $oblt_order->get_billing_phone() ); ?><?php endif; ?>
			</div>
			<div><strong><?php esc_html_e( 'Customer', 'orderbay-lite' ); ?></strong><br /><?php echo esc_html( $oblt_order->get_formatted_billing_full_name() ); ?>
				<?php if ( $oblt_order->get_billing_email() ) : ?><br /><?php echo esc_html( $oblt_order->get_billing_email() ); ?><?php endif; ?>
			</div>
		</div>
		<table class="items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Item', 'orderbay-lite' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'orderbay-lite' ); ?></th>
					<th class="num"><?php esc_html_e( 'Qty', 'orderbay-lite' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $oblt_order->get_items() as $oblt_item_id => $oblt_item ) : ?>
				<?php
				$oblt_qty = (int) $oblt_item->get_quantity() + (int) $oblt_order->get_qty_refunded_for_item( $oblt_item_id );
				if ( $oblt_qty <= 0 ) {
					continue;
				}
				$oblt_product = $oblt_item->get_product();
				$oblt_sku     = $oblt_product ? $oblt_product->get_sku() : '';
				$oblt_meta    = wc_display_item_meta( $oblt_item, array( 'echo' => false, 'separator' => '<br />' ) );
				?>
				<tr>
					<td><?php echo esc_html( $oblt_item->get_name() ); ?>
						<?php if ( $oblt_meta ) : ?><div class="item-meta"><?php echo wp_kses_post( $oblt_meta ); ?></div><?php endif; ?>
					</td>
					<td><?php echo esc_html( $oblt_sku ? $oblt_sku : '—' ); ?></td>
					<td class="num"><?php echo esc_html( (string) $oblt_qty ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $oblt_order->get_customer_note() ) : ?>
			<div class="notes"><strong><?php esc_html_e( 'Customer note', 'orderbay-lite' ); ?>:</strong> <?php echo esc_html( $oblt_order->get_customer_note() ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $settings['footer_text'] ) ) : ?>
			<div class="footer"><?php echo esc_html( $settings['footer_text'] ); ?></div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
<?php OBLT_Documents::print_document_script(); ?>
</body>
</html>

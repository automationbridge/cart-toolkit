<?php
/**
 * Page: Logs (everything Cart Toolkit did, filterable by source).
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

$ctk_source  = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ctk_source  = isset( CTK_Log::SOURCES[ $ctk_source ] ) ? $ctk_source : '';
$ctk_entries = CTK_Log::all( $ctk_source );
$ctk_order   = function ( $order_id ) {
	return $order_id ? '<a href="' . esc_url( admin_url( 'admin.php?page=fluent-cart#/orders/' . (int) $order_id . '/view' ) ) . '">#' . (int) $order_id . '</a>' : '';
};
?>

<nav class="ctk-subnav" aria-label="Log source">
	<a class="ctk-subnav-item<?php echo '' === $ctk_source ? ' is-active' : ''; ?>" href="<?php echo esc_url( CTK_Admin::page_url( 'logs' ) ); ?>">All</a>
	<?php foreach ( CTK_Log::SOURCES as $ctk_key => $ctk_label ) : ?>
		<a class="ctk-subnav-item<?php echo $ctk_key === $ctk_source ? ' is-active' : ''; ?>" href="<?php echo esc_url( CTK_Admin::page_url( 'logs', array( 'source' => $ctk_key ) ) ); ?>"><?php echo esc_html( $ctk_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( ! $ctk_entries ) : ?>
	<div class="ctk-empty"><p>Nothing logged yet.</p></div>
<?php else : ?>
	<div class="ctk-table-scroll">
		<table class="widefat striped">
			<thead><tr><th>Time</th><th>Source</th><th>What happened</th><th>Order</th></tr></thead>
			<tbody>
				<?php foreach ( $ctk_entries as $ctk_entry ) : ?>
					<tr>
						<td><?php echo esc_html( $ctk_entry['time'] ); ?></td>
						<td><?php echo wp_kses_post( CTK_Admin::pill( $ctk_entry['ok'], CTK_Log::SOURCES[ $ctk_entry['source'] ] ?? $ctk_entry['source'] ) ); ?></td>
						<td><strong><?php echo esc_html( $ctk_entry['action'] ); ?></strong><br /><?php echo esc_html( $ctk_entry['details'] ); ?></td>
						<td>
							<?php echo wp_kses_post( $ctk_order( $ctk_entry['order_id'] ) ); ?>
							<?php if ( 'handoff' === $ctk_entry['source'] && ! $ctk_entry['ok'] && $ctk_entry['order_id'] && class_exists( 'CTK_Handoff' ) ) : ?>
								<br /><a href="<?php echo esc_url( CTK_Handoff::resend_url( $ctk_entry['order_id'] ) ); ?>">Send again</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php CTK_Admin::form_open( 'ctk_log_clear' ); ?>
		<p><button type="submit" class="button">Clear the log</button></p>
	</form>
<?php endif; ?>

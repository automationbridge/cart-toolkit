<?php
/**
 * Page: Overview (what's set up, the CRM connection, recent activity).
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

$ctk_ready     = CTK_Settings::fluentcart_ready();
$ctk_settings  = CTK_Settings::get();
$ctk_connector = CTK_Connector::active();
$ctk_forms     = $ctk_ready ? CTK_Forms::all() : array();
$ctk_active    = array_filter( $ctk_forms, function ( $form ) {
	return $form['active'];
} );
$ctk_checkout  = $ctk_ready ? ( new \FluentCart\Api\StoreSettings() )->getCheckoutPageId() : 0;

$ctk_checks = array(
	array(
		'label'  => 'FluentCart',
		'ok'     => $ctk_ready,
		'detail' => defined( 'FLUENTCART_VERSION' ) ? 'Version ' . FLUENTCART_VERSION . ( $ctk_ready ? '.' : '. Cart Toolkit needs 2.3.2 or newer.' ) : 'Not active. Install and activate the free FluentCart plugin.',
	),
	array(
		'label'  => 'CRM connection',
		'ok'     => (bool) $ctk_connector,
		'detail' => $ctk_connector ? 'Using ' . $ctk_connector->label() . '.' : 'None. Answers are saved on orders but not sent anywhere until a CRM is connected below.',
	),
	array(
		'label'  => 'FluentCart checkout page',
		'ok'     => (bool) $ctk_checkout,
		'detail' => $ctk_checkout ? 'Set. Used only by forms that have no page of their own.' : 'Not set in FluentCart › Settings › Store. Forms with a page of their own work without it.',
	),
);
?>

<div class="ctk-callout<?php echo $ctk_ready && $ctk_connector ? '' : ' is-warn'; ?>">
	<strong>
		<?php
		echo esc_html( sprintf(
			'%1$d form%2$s, %3$d switched on. %4$d field%5$s in the library. %6$d form-only price%7$s.',
			count( $ctk_forms ),
			1 === count( $ctk_forms ) ? '' : 's',
			count( $ctk_active ),
			count( CTK_Fields::all() ),
			1 === count( CTK_Fields::all() ) ? '' : 's',
			count( CTK_Form_Only::ids() ),
			1 === count( CTK_Form_Only::ids() ) ? '' : 's'
		) );
		?>
	</strong>
</div>

<?php CTK_Admin::render_checks_table( $ctk_checks ); ?>

<?php CTK_Admin::form_open( 'ctk_save_settings', 'ctk-settings' ); ?>
	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>CRM</h3>
				<p>Where the field library comes from and where answers are sent after payment.</p>
			</div>
		</div>
		<div class="ctk-setting-body">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ctk-connector">Send answers to</label></th>
					<td>
						<select id="ctk-connector" name="connector">
							<option value="auto" <?php selected( $ctk_settings['connector'], 'auto' ); ?>>Whichever is active (WP Fusion first)</option>
							<?php foreach ( CTK_Connector::all() as $ctk_id => $ctk_c ) : ?>
								<option value="<?php echo esc_attr( $ctk_id ); ?>" <?php selected( $ctk_settings['connector'], $ctk_id ); ?>>
									<?php echo esc_html( $ctk_c->label() . ( $ctk_c->is_available() ? '' : ' (not active)' ) ); ?>
								</option>
							<?php endforeach; ?>
							<option value="none" <?php selected( $ctk_settings['connector'], 'none' ); ?>>Nowhere for now</option>
						</select>
						<p class="description">FluentCRM is used directly on this site. WP Fusion reaches whichever CRM it is connected to, such as Groundhogg on another site.</p>
					</td>
				</tr>
			</table>
		</div>
	</section>

	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>Connect orders to an existing login</h3>
				<p>When someone checks out without logging in, using an email that already has an account here, the order is connected to that account. FluentCart leaves this out by itself, and its FluentCommunity access then can't remove a member after a cancel or refund. Nobody is logged in by this.</p>
			</div>
			<?php CTK_Admin::render_switch( 'link_existing', (bool) $ctk_settings['link_existing'], 'Connect orders to an existing login' ); ?>
		</div>
	</section>

	<?php CTK_Admin::render_savebar( 'Save changes', 'Applies to the next order.' ); ?>
</form>

<div class="card">
	<h2>Putting a form on a page</h2>
	<ol>
		<li>Create the form on <a href="<?php echo esc_url( CTK_Admin::page_url( 'forms' ) ); ?>">Forms</a>, choose its prices and fields, and switch it on.</li>
		<li>Add <code>[cart_toolkit_form id="the-form-id"]</code> to any page, in a Shortcode block. One form per page.</li>
		<li>Choose that page in the form's settings, so the form's own link opens it.</li>
	</ol>
	<p class="description">A free signup uses FluentCart's checkout too, with no payment box for a $0 one-time price. Which name and address fields it asks for is set in FluentCart › Settings › Checkout fields.</p>
</div>

<?php $ctk_recent = array_slice( CTK_Log::all(), 0, 8 ); ?>
<?php if ( $ctk_recent ) : ?>
	<div class="card">
		<h2>Recent activity</h2>
		<table class="widefat striped">
			<tbody>
				<?php foreach ( $ctk_recent as $ctk_entry ) : ?>
					<tr>
						<td><?php echo esc_html( $ctk_entry['time'] ); ?></td>
						<td><?php echo wp_kses_post( CTK_Admin::pill( $ctk_entry['ok'], CTK_Log::SOURCES[ $ctk_entry['source'] ] ?? $ctk_entry['source'] ) ); ?></td>
						<td><strong><?php echo esc_html( $ctk_entry['action'] ); ?></strong> <?php echo esc_html( $ctk_entry['details'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( CTK_Admin::page_url( 'logs' ) ); ?>">All logs</a></p>
	</div>
<?php endif; ?>

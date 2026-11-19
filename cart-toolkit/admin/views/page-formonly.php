<?php
/**
 * Page: Form-only prices (which prices can only be bought through a form).
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

$ctk_products = CTK_Forms::products();
$ctk_ids      = CTK_Form_Only::ids();
?>

<div class="card">
	<h2>What form-only means</h2>
	<ul class="ul-disc">
		<li><strong>On a form that sells it:</strong> shown and bought as normal.</li>
		<li><strong>A FluentCart buy link for it:</strong> sent to the form that sells it, or told "This option is no longer available" if no switched-on form does.</li>
		<li><strong>Any other way into the checkout:</strong> refused by the server before an order exists.</li>
		<li><strong>People already paying for it:</strong> keep renewing. Renewals don't go through the checkout.</li>
		<li><strong>FluentCart's own product page:</strong> set the product to Private in FluentCart, so the page isn't public. Forms can still sell a private product's prices.</li>
	</ul>
</div>

<?php CTK_Admin::form_open( 'ctk_formonly_save', 'ctk-settings' ); ?>
	<?php if ( ! $ctk_products ) : ?>
		<p>FluentCart has no products yet.</p>
	<?php endif; ?>
	<?php foreach ( $ctk_products as $ctk_pid => $ctk_title ) : ?>
		<?php $ctk_variations = CTK_Forms::product_variations( $ctk_pid ); ?>
		<section class="ctk-setting">
			<div class="ctk-setting-head"><div><h3><?php echo esc_html( $ctk_title ); ?></h3></div></div>
			<div class="ctk-setting-body">
				<div class="ctk-table-scroll">
					<table class="widefat striped">
						<thead><tr><th>Form-only</th><th>Price</th><th>Amount</th><th>Sold on</th></tr></thead>
						<tbody>
							<?php foreach ( $ctk_variations as $ctk_v ) : ?>
								<?php
								$ctk_selling = array_map( function ( $form ) {
									return $form['name'] . ( $form['active'] ? '' : ' (off)' );
								}, CTK_Forms::forms_selling( $ctk_v->id ) );
								?>
								<tr>
									<td><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $ctk_v->id ); ?>" <?php checked( in_array( (int) $ctk_v->id, $ctk_ids, true ) ); ?> aria-label="<?php echo esc_attr( $ctk_v->variation_title . ' is form-only' ); ?>" /></td>
									<td><strong><?php echo esc_html( $ctk_v->variation_title ); ?></strong> <span class="description">#<?php echo esc_html( $ctk_v->id ); ?></span></td>
									<td><?php echo esc_html( CTK_Forms::price_label( $ctk_v ) ); ?></td>
									<td>
										<?php if ( $ctk_selling ) : ?>
											<?php echo esc_html( implode( ', ', $ctk_selling ) ); ?>
										<?php else : ?>
											<span class="description">No form: can't be bought new if form-only</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</section>
	<?php endforeach; ?>
	<?php if ( $ctk_products ) : ?>
		<?php CTK_Admin::render_savebar( 'Save form-only prices', 'Applies to the next checkout.' ); ?>
	<?php endif; ?>
</form>

<?php
/**
 * One form's editor. Included by page-forms.php with $ctk_form and $ctk_products set.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

$ctk_variations = CTK_Forms::product_variations( $ctk_form['product_id'] );
$ctk_library    = CTK_Fields::all();
$ctk_is_free    = 'free' === $ctk_form['kind'];
$ctk_shortcode  = '[cart_toolkit_form id="' . $ctk_form['id'] . '"]';
$ctk_page       = $ctk_form['page_id'] ? get_post( $ctk_form['page_id'] ) : null;
$ctk_page_ok    = $ctk_page && has_shortcode( $ctk_page->post_content, CTK_Checkout::SHORTCODE );

// The form's rows, then three blank rows to add more.
$ctk_rows = $ctk_form['fields'];
for ( $ctk_i = 0; $ctk_i < 3; $ctk_i++ ) {
	$ctk_rows[] = array( 'key' => '', 'mode' => 'ask', 'required' => false, 'value' => '', 'param' => '', 'source' => 'user_id' );
}
?>

<p><a href="<?php echo esc_url( CTK_Admin::page_url( 'forms' ) ); ?>">&larr; All forms</a></p>

<?php CTK_Admin::form_open( 'ctk_form_save', 'ctk-settings' ); ?>
	<input type="hidden" name="id" value="<?php echo esc_attr( $ctk_form['id'] ); ?>" />

	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3><?php echo esc_html( $ctk_form['name'] ); ?></h3>
				<p>
					<?php echo esc_html( CTK_Forms::KINDS[ $ctk_form['kind'] ] ); ?> for
					<strong><?php echo esc_html( $ctk_products[ $ctk_form['product_id'] ] ?? 'Product #' . $ctk_form['product_id'] ); ?></strong>.
					Switched off, the form shows "This form is closed" and its link stops working.
				</p>
			</div>
			<?php CTK_Admin::render_switch( 'active', $ctk_form['active'], 'Form is on' ); ?>
		</div>
		<div class="ctk-setting-body">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ctk-name">Name</label></th>
					<td><input type="text" class="regular-text" id="ctk-name" name="name" value="<?php echo esc_attr( $ctk_form['name'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">Shortcode</th>
					<td><code><?php echo esc_html( $ctk_shortcode ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">Form's link</th>
					<td>
						<code style="word-break:break-all"><?php echo esc_html( CTK_Forms::link( $ctk_form ) ); ?></code>
						<p class="description">Opens the form's page with a fresh cart. Add <code>&amp;option=&lt;price id&gt;</code> to start on a particular price, and any tracking parameters you like.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ctk-page">Page</label></th>
					<td>
						<?php
						wp_dropdown_pages( array(
							'name'              => 'page_id',
							'id'                => 'ctk-page',
							'selected'          => (int) $ctk_form['page_id'],
							'show_option_none'  => 'None: use FluentCart\'s checkout page',
							'option_none_value' => '0',
							'post_status'       => array( 'publish', 'private', 'draft' ),
						) );
						?>
						<?php if ( $ctk_page && ! $ctk_page_ok ) : ?>
							<p class="description ctk-error-text">This page doesn't contain <code><?php echo esc_html( $ctk_shortcode ); ?></code> yet. Add it, or the link opens a page without the form.</p>
						<?php elseif ( $ctk_page_ok ) : ?>
							<p class="description">The page contains the shortcode. <a href="<?php echo esc_url( get_permalink( $ctk_page ) ); ?>" target="_blank" rel="noopener">Open it</a></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
	</section>

	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>Prices on this form</h3>
				<p>
					<?php if ( $ctk_is_free ) : ?>
						A free signup can only sell $0 prices. Paid prices can't be ticked.
					<?php else : ?>
						Buyers choose between the ticked prices at the top of the form. The default is the one already chosen when the form opens.
					<?php endif; ?>
				</p>
			</div>
		</div>
		<div class="ctk-setting-body">
			<?php if ( ! count( $ctk_variations ) ) : ?>
				<p>This product has no prices.</p>
			<?php else : ?>
				<div class="ctk-table-scroll">
					<table class="widefat striped">
						<thead><tr><th>On form</th><th>Default</th><th>Price</th><th>Amount</th><th>Line under the name</th><th>Status</th></tr></thead>
						<tbody>
							<?php foreach ( $ctk_variations as $ctk_v ) : ?>
								<?php
								$ctk_on       = CTK_Forms::sells( $ctk_form, $ctk_v->id );
								$ctk_disabled = $ctk_is_free && ! CTK_Forms::is_free_price( $ctk_v );
								$ctk_notes    = array();
								if ( 'active' !== $ctk_v->item_status ) {
									$ctk_notes[] = 'Inactive in FluentCart, so it can\'t be bought';
								}
								if ( CTK_Form_Only::is_form_only( $ctk_v->id ) ) {
									$ctk_notes[] = 'Form-only';
								}
								?>
								<tr>
									<td><input type="checkbox" name="variation_ids[]" value="<?php echo esc_attr( $ctk_v->id ); ?>" <?php checked( $ctk_on ); ?> <?php disabled( $ctk_disabled ); ?> aria-label="<?php echo esc_attr( 'Sell ' . $ctk_v->variation_title ); ?>" /></td>
									<td><input type="radio" name="default_variation" value="<?php echo esc_attr( $ctk_v->id ); ?>" <?php checked( (int) $ctk_form['default_variation'], (int) $ctk_v->id ); ?> <?php disabled( $ctk_disabled ); ?> aria-label="<?php echo esc_attr( $ctk_v->variation_title . ' is the default' ); ?>" /></td>
									<td><strong><?php echo esc_html( $ctk_v->variation_title ); ?></strong> <span class="description">#<?php echo esc_html( $ctk_v->id ); ?></span></td>
									<td><?php echo esc_html( CTK_Forms::price_label( $ctk_v ) ); ?></td>
									<td><input type="text" name="price_notes[<?php echo esc_attr( $ctk_v->id ); ?>]" value="<?php echo esc_attr( $ctk_form['price_notes'][ $ctk_v->id ] ?? '' ); ?>" placeholder="e.g. 2 months free!" maxlength="80" aria-label="<?php echo esc_attr( 'Line under ' . $ctk_v->variation_title ); ?>" <?php disabled( $ctk_disabled ); ?> /></td>
									<td><?php echo esc_html( implode( '. ', $ctk_notes ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>Fields</h3>
				<p>Each row is a field from the <a href="<?php echo esc_url( CTK_Admin::page_url( 'fields' ) ); ?>">field library</a>. Asked fields appear inside the checkout under the heading below. The other kinds are filled in without the buyer seeing them, and everything is sent to the CRM once the order is paid.</p>
			</div>
		</div>
		<div class="ctk-setting-body">
			<?php if ( ! $ctk_library ) : ?>
				<p>The field library is empty. <a href="<?php echo esc_url( CTK_Admin::page_url( 'fields' ) ); ?>">Add fields from your CRM</a> first.</p>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ctk-heading">Heading above asked fields</label></th>
						<td><input type="text" class="regular-text" id="ctk-heading" name="fields_heading" value="<?php echo esc_attr( $ctk_form['fields_heading'] ); ?>" /></td>
					</tr>
				</table>
				<div class="ctk-table-scroll">
					<table class="widefat striped ctk-field-rows">
						<thead><tr><th>Field</th><th>How it's filled</th><th>Required</th><th>Details</th></tr></thead>
						<tbody>
							<?php foreach ( $ctk_rows as $ctk_n => $ctk_row ) : ?>
								<?php $ctk_name = 'fields[' . $ctk_n . ']'; ?>
								<tr data-mode="<?php echo esc_attr( $ctk_row['mode'] ); ?>">
									<td>
										<select name="<?php echo esc_attr( $ctk_name ); ?>[key]" aria-label="Field">
											<option value="">— <?php echo '' === $ctk_row['key'] ? 'Add a field' : 'Remove'; ?> —</option>
											<?php foreach ( $ctk_library as $ctk_key => $ctk_def ) : ?>
												<option value="<?php echo esc_attr( $ctk_key ); ?>" <?php selected( $ctk_row['key'], $ctk_key ); ?>><?php echo esc_html( $ctk_def['label'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<select name="<?php echo esc_attr( $ctk_name ); ?>[mode]" aria-label="How it's filled" onchange="this.closest('tr').setAttribute('data-mode', this.value)">
											<?php foreach ( CTK_Fields::MODES as $ctk_mode => $ctk_label ) : ?>
												<option value="<?php echo esc_attr( $ctk_mode ); ?>" <?php selected( $ctk_row['mode'], $ctk_mode ); ?>><?php echo esc_html( $ctk_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="checkbox" name="<?php echo esc_attr( $ctk_name ); ?>[required]" value="1" <?php checked( ! empty( $ctk_row['required'] ) ); ?> aria-label="Required" class="ctk-for-ask" /></td>
									<td>
										<input type="text" name="<?php echo esc_attr( $ctk_name ); ?>[value]" value="<?php echo esc_attr( $ctk_row['value'] ); ?>" placeholder="Value to set" class="ctk-for-set" aria-label="Value to set" />
										<input type="text" name="<?php echo esc_attr( $ctk_name ); ?>[param]" value="<?php echo esc_attr( $ctk_row['param'] ); ?>" placeholder="Link parameter, e.g. utm_source" class="ctk-for-link" aria-label="Link parameter" />
										<select name="<?php echo esc_attr( $ctk_name ); ?>[source]" class="ctk-for-order" aria-label="Value from the order">
											<?php foreach ( CTK_Fields::ORDER_SOURCES as $ctk_src => $ctk_label ) : ?>
												<option value="<?php echo esc_attr( $ctk_src ); ?>" <?php selected( $ctk_row['source'] ?? 'user_id', $ctk_src ); ?>><?php echo esc_html( $ctk_label ); ?></option>
											<?php endforeach; ?>
										</select>
										<span class="description ctk-for-ask">Shown on the form.</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description">Save to get three more blank rows.</p>
			<?php endif; ?>
		</div>
	</section>

	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>Look</h3>
				<p>The form is a card that sits inside your page. Design the page around it (headline, benefits, testimonials) with your page builder.</p>
			</div>
		</div>
		<div class="ctk-setting-body">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Style</th>
					<td>
						<fieldset>
							<?php foreach ( CTK_Forms::STYLES as $ctk_style => $ctk_label ) : ?>
								<label class="ctk-block-choice"><input type="radio" name="style" value="<?php echo esc_attr( $ctk_style ); ?>" <?php checked( $ctk_form['style'], $ctk_style ); ?> /> <?php echo esc_html( $ctk_label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ctk-accent">Accent colour</label></th>
					<td>
						<input type="color" id="ctk-accent" name="accent" value="<?php echo esc_attr( $ctk_form['accent'] ); ?>" />
						<p class="description">The button, the chosen price and the active fields.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ctk-plan-heading">Heading above the prices</label></th>
					<td><input type="text" class="regular-text" id="ctk-plan-heading" name="plan_heading" value="<?php echo esc_attr( $ctk_form['plan_heading'] ); ?>" /></td>
				</tr>
			</table>
		</div>
	</section>

	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>Checkout</h3>
			</div>
		</div>
		<div class="ctk-setting-body">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ctk-button">Button text</label></th>
					<td>
						<input type="text" class="regular-text" id="ctk-button" name="button_text" value="<?php echo esc_attr( $ctk_form['button_text'] ); ?>" />
						<p class="description">Write <code>{price}</code> to show the chosen price, for example "Join Today – {price}". Leave blank for FluentCart's own "Place order".</p>
					</td>
				</tr>
				<?php if ( ! $ctk_is_free ) : ?>
					<tr>
						<th scope="row">Coupons</th>
						<td>
							<label><input type="checkbox" name="coupons" value="1" <?php checked( $ctk_form['coupons'] ); ?> /> Show the coupon field on this form</label>
							<p class="description">The coupon field is part of the order summary, so it needs the "Card with order summary" style.</p>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
	</section>

	<?php CTK_Admin::render_savebar( 'Save form', $ctk_form['active'] ? 'The form is on. Changes apply to new visitors.' : 'The form is off until you switch it on above.' ); ?>
</form>

<?php CTK_Admin::form_open( 'ctk_form_delete' ); ?>
	<input type="hidden" name="id" value="<?php echo esc_attr( $ctk_form['id'] ); ?>" />
	<p>
		<button type="submit" class="button button-link-delete" onclick="return window.confirm('Delete this form? Orders keep their saved answers.');">Delete this form</button>
	</p>
</form>


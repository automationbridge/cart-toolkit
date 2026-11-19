<?php
/**
 * Page: Forms (the list, a new form, and one form's editor with ?edit=<id>).
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

$ctk_products = CTK_Forms::products();
$ctk_edit_id  = isset( $_GET['edit'] ) ? sanitize_title( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ctk_form     = '' !== $ctk_edit_id ? CTK_Forms::get( $ctk_edit_id ) : null;

if ( $ctk_form ) {
	include CTK_DIR . 'admin/views/partials/form-editor.php';
	return;
}

$ctk_forms = CTK_Forms::all();
?>

<?php if ( $ctk_forms ) : ?>
	<div class="ctk-table-scroll">
		<table class="widefat striped">
			<thead>
				<tr><th>Form</th><th>Kind</th><th>Product and prices</th><th>Page</th><th>Status</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $ctk_forms as $ctk_f ) : ?>
					<?php $ctk_vars = CTK_Forms::variations( $ctk_f ); ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( CTK_Admin::page_url( 'forms', array( 'edit' => $ctk_f['id'] ) ) ); ?>"><?php echo esc_html( $ctk_f['name'] ); ?></a></strong><br />
							<code><?php echo esc_html( $ctk_f['id'] ); ?></code>
						</td>
						<td><?php echo esc_html( CTK_Forms::KINDS[ $ctk_f['kind'] ] ?? $ctk_f['kind'] ); ?></td>
						<td>
							<?php echo esc_html( $ctk_products[ $ctk_f['product_id'] ] ?? 'Product #' . $ctk_f['product_id'] . ' (missing)' ); ?><br />
							<span class="description">
								<?php
								echo esc_html( $ctk_vars ? implode( ', ', array_map( function ( $v ) {
									return $v->variation_title;
								}, $ctk_vars ) ) : 'No prices chosen yet' );
								?>
							</span>
						</td>
						<td>
							<?php if ( $ctk_f['page_id'] && get_post( $ctk_f['page_id'] ) ) : ?>
								<a href="<?php echo esc_url( get_permalink( $ctk_f['page_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $ctk_f['page_id'] ) ); ?></a>
							<?php else : ?>
								<span class="description">None</span>
							<?php endif; ?>
						</td>
						<td><?php echo wp_kses_post( CTK_Admin::pill( $ctk_f['active'], $ctk_f['active'] ? 'On' : 'Off' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php else : ?>
	<div class="ctk-empty"><p>No forms yet. Create the first one below.</p></div>
<?php endif; ?>

<?php CTK_Admin::form_open( 'ctk_form_create', 'ctk-settings' ); ?>
	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>New form</h3>
				<p>Choose the product first. Its prices and the form's fields come on the next screen. A new form starts switched off.</p>
			</div>
		</div>
		<div class="ctk-setting-body">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ctk-new-name">Name</label></th>
					<td><input type="text" class="regular-text" id="ctk-new-name" name="name" placeholder="Community, public" required /></td>
				</tr>
				<tr>
					<th scope="row">Kind</th>
					<td>
						<?php foreach ( CTK_Forms::KINDS as $ctk_kind => $ctk_label ) : ?>
							<label style="margin-right:16px"><input type="radio" name="kind" value="<?php echo esc_attr( $ctk_kind ); ?>" <?php checked( 'paid', $ctk_kind ); ?> /> <?php echo esc_html( $ctk_label ); ?></label>
						<?php endforeach; ?>
						<p class="description">A free signup sells only $0 prices and has no payment box.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ctk-new-product">Product</label></th>
					<td>
						<select id="ctk-new-product" name="product_id" required>
							<option value="">Choose a product…</option>
							<?php foreach ( $ctk_products as $ctk_pid => $ctk_title ) : ?>
								<option value="<?php echo esc_attr( $ctk_pid ); ?>"><?php echo esc_html( $ctk_title ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! $ctk_products ) : ?>
							<p class="description">FluentCart has no products yet.</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
	</section>
	<?php CTK_Admin::render_savebar( 'Create form' ); ?>
</form>

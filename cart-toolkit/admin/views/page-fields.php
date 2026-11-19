<?php
/**
 * Page: Field library (the fields forms can use, and the CRM's fields to add).
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

$ctk_library   = CTK_Fields::all();
$ctk_connector = CTK_Connector::active();
$ctk_crm       = $ctk_connector ? $ctk_connector->get_fields() : array();

// CRM fields not yet in the library, grouped the way the CRM groups them.
$ctk_groups = array();
foreach ( $ctk_crm as $ctk_field ) {
	if ( ! isset( $ctk_library[ $ctk_field['key'] ] ) ) {
		$ctk_groups[ $ctk_field['group'] ][] = $ctk_field;
	}
}
ksort( $ctk_groups );

// Which forms use each library field.
$ctk_used = array();
foreach ( CTK_Forms::all() as $ctk_form ) {
	foreach ( $ctk_form['fields'] as $ctk_row ) {
		$ctk_used[ $ctk_row['key'] ][] = $ctk_form['name'];
	}
}
?>

<section class="ctk-setting">
	<div class="ctk-setting-head">
		<div>
			<h3>In the library</h3>
			<p>Open a field to change the label buyers see, its input type, or its choices. Its CRM key never changes.</p>
		</div>
	</div>
	<div class="ctk-setting-body">
		<?php if ( ! $ctk_library ) : ?>
			<p>Nothing yet. Add fields from your CRM below.</p>
		<?php else : ?>
			<?php foreach ( $ctk_library as $ctk_key => $ctk_def ) : ?>
				<details class="ctk-field-edit card">
					<summary>
						<strong><?php echo esc_html( $ctk_def['label'] ); ?></strong>
						<span class="description">
							<?php echo esc_html( CTK_Connector::TYPES[ $ctk_def['type'] ] ?? $ctk_def['type'] ); ?> ·
							<code><?php echo esc_html( $ctk_key ); ?></code> ·
							<?php echo esc_html( isset( $ctk_used[ $ctk_key ] ) ? 'On ' . implode( ', ', array_unique( $ctk_used[ $ctk_key ] ) ) : 'Not on any form' ); ?>
						</span>
					</summary>
					<?php CTK_Admin::form_open( 'ctk_field_save' ); ?>
						<input type="hidden" name="key" value="<?php echo esc_attr( $ctk_key ); ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="ctk-l-<?php echo esc_attr( md5( $ctk_key ) ); ?>">Label</label></th>
								<td><input type="text" class="regular-text" id="ctk-l-<?php echo esc_attr( md5( $ctk_key ) ); ?>" name="label" value="<?php echo esc_attr( $ctk_def['label'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="ctk-t-<?php echo esc_attr( md5( $ctk_key ) ); ?>">Input type</label></th>
								<td>
									<select id="ctk-t-<?php echo esc_attr( md5( $ctk_key ) ); ?>" name="type">
										<?php foreach ( CTK_Connector::TYPES as $ctk_type => $ctk_label ) : ?>
											<option value="<?php echo esc_attr( $ctk_type ); ?>" <?php selected( $ctk_def['type'], $ctk_type ); ?>><?php echo esc_html( $ctk_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ctk-o-<?php echo esc_attr( md5( $ctk_key ) ); ?>">Choices</label></th>
								<td>
									<textarea id="ctk-o-<?php echo esc_attr( md5( $ctk_key ) ); ?>" name="options" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", (array) $ctk_def['options'] ) ); ?></textarea>
									<p class="description">One per line. Used by dropdown, choice and checkbox fields. They should match the CRM field's own choices.</p>
								</td>
							</tr>
						</table>
						<p><button type="submit" class="button button-primary">Save field</button></p>
					</form>
					<?php CTK_Admin::form_open( 'ctk_field_remove' ); ?>
						<input type="hidden" name="key" value="<?php echo esc_attr( $ctk_key ); ?>" />
						<p><button type="submit" class="button button-link-delete">Remove from library</button></p>
					</form>
				</details>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</section>

<?php CTK_Admin::form_open( 'ctk_fields_add', 'ctk-settings' ); ?>
	<section class="ctk-setting">
		<div class="ctk-setting-head">
			<div>
				<h3>Add from <?php echo esc_html( $ctk_connector ? $ctk_connector->label() : 'your CRM' ); ?></h3>
				<p>
					<?php if ( ! $ctk_connector ) : ?>
						No CRM is connected. Choose one on the <a href="<?php echo esc_url( CTK_Admin::page_url( 'overview' ) ); ?>">Overview</a>.
					<?php elseif ( 'wpfusion' === $ctk_connector->id() ) : ?>
						These are the CRM fields WP Fusion has loaded. If one is missing, refresh WP Fusion's fields first (WP Fusion › Setup › Refresh available tags and fields). WP Fusion doesn't know a field's type, so set it after adding.
					<?php else : ?>
						FluentCRM's custom contact fields, with their types and choices.
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php if ( $ctk_groups ) : ?>
			<div class="ctk-setting-body">
				<?php foreach ( $ctk_groups as $ctk_group => $ctk_fields ) : ?>
					<h4><?php echo esc_html( $ctk_group ); ?></h4>
					<?php foreach ( $ctk_fields as $ctk_field ) : ?>
						<label style="display:block;margin:4px 0">
							<input type="checkbox" name="keys[]" value="<?php echo esc_attr( $ctk_field['key'] ); ?>" />
							<?php echo esc_html( $ctk_field['label'] ); ?>
							<span class="description"><?php echo esc_html( ( CTK_Connector::TYPES[ $ctk_field['type'] ] ?? $ctk_field['type'] ) . ' · ' . $ctk_field['key'] ); ?></span>
						</label>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		<?php elseif ( $ctk_connector ) : ?>
			<div class="ctk-setting-body"><p>Every field from the CRM is already in the library.</p></div>
		<?php endif; ?>
	</section>
	<?php if ( $ctk_groups ) : ?>
		<?php CTK_Admin::render_savebar( 'Add ticked fields' ); ?>
	<?php endif; ?>
</form>

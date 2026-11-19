<?php
/**
 * Removes Cart Toolkit's own settings. Answers saved on FluentCart orders stay
 * with the orders. The GitHub updater's options are shared by every plugin that
 * carries it, so they stay too.
 *
 * @package Cart_Toolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'ctk_settings', 'ctk_fields', 'ctk_forms', 'ctk_form_only', 'ctk_log' ) as $ctk_option ) {
	delete_option( $ctk_option );
}

wp_clear_scheduled_hook( 'ctk_handoff_retry' );

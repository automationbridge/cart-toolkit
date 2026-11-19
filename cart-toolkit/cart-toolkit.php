<?php
/**
 * Plugin Name: Cart Toolkit
 * Plugin URI:  https://automationbridge.com
 * Description: Add-on for the free FluentCart plugin: checkout forms that sell chosen prices with their own CRM fields, a free signup on the same engine, form-only prices, and a hand-off of every answer to the CRM.
 * Version:     0.1.0
 * Update URI:  https://github.com/automationbridge/cart-toolkit
 * Author:      Chris L. Davis
 * Author URI:  https://automationbridge.com
 * License:     GPL-2.0-or-later
 * Text Domain: cart-toolkit
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'CTK_VERSION', '0.1.0' );
define( 'CTK_FILE', __FILE__ );
define( 'CTK_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTK_URL', plugin_dir_url( __FILE__ ) );

/* ── Option keys ── */
define( 'CTK_OPT_SETTINGS', 'ctk_settings' );
define( 'CTK_OPT_FIELDS', 'ctk_fields' );
define( 'CTK_OPT_FORMS', 'ctk_forms' );
define( 'CTK_OPT_FORM_ONLY', 'ctk_form_only' );
define( 'CTK_OPT_LOG', 'ctk_log' );

/* ── Order meta keys ── */
define( 'CTK_META_FORM', '_ctk_form' );
define( 'CTK_META_ANSWERS', '_ctk_answers' );
define( 'CTK_META_HANDOFF', '_ctk_handoff' );

/* ── The cart group Cart Toolkit's form carts live in ── */
define( 'CTK_CART_GROUP', 'ctk_form' );

require_once CTK_DIR . 'includes/class-ctk-log.php';
require_once CTK_DIR . 'includes/class-ctk-settings.php';
require_once CTK_DIR . 'includes/connectors/class-ctk-connector.php';
require_once CTK_DIR . 'includes/connectors/class-ctk-connector-fluentcrm.php';
require_once CTK_DIR . 'includes/connectors/class-ctk-connector-wpfusion.php';
require_once CTK_DIR . 'includes/class-ctk-fields.php';
require_once CTK_DIR . 'includes/class-ctk-forms.php';
require_once CTK_DIR . 'includes/class-ctk-form-only.php';
require_once CTK_DIR . 'includes/class-ctk-checkout.php';
require_once CTK_DIR . 'includes/class-ctk-handoff.php';

// Updates from GitHub. The same drop-in every plugin uses.
require_once CTK_DIR . 'includes/updater/class-ab-github-updater.php';

require_once CTK_DIR . 'admin/class-ctk-admin.php';

AB_GitHub_Updater::register( array(
	'file'       => __FILE__,
	'repo'       => 'automationbridge/cart-toolkit',
	'asset'      => 'cart-toolkit.zip',
	'own_screen' => false,
) );

add_action( 'plugins_loaded', 'ctk_boot', 20 );

/**
 * Everything that touches FluentCart waits until FluentCart has loaded.
 * The admin shell boots regardless, so its Overview can say FluentCart is missing.
 */
function ctk_boot() {
	CTK_Admin::init();

	if ( ! CTK_Settings::fluentcart_ready() ) {
		return;
	}

	CTK_Form_Only::init();
	CTK_Checkout::init();
	CTK_Handoff::init();
}

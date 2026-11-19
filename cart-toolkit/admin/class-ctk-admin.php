<?php
/**
 * Admin shell — Cart Toolkit.
 *
 * A top-level menu with a sidebar of pages (CTK_Admin::PAGES), one view file per
 * page in admin/views/page-{slug}.php, and every form handler, each following
 * nonce → capability → sanitize → save → redirect with a notice.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

class CTK_Admin {

	const PAGE_SLUG  = 'cart-toolkit';
	const CAPABILITY = 'manage_options';

	const PAGES = array(
		'overview' => array( 'label' => 'Overview', 'desc' => '' ),
		'forms'    => array( 'label' => 'Forms', 'desc' => 'Paid checkout forms and free signups. Each sells chosen prices of one product, with its own fields and its own link.' ),
		'fields'   => array( 'label' => 'Field library', 'desc' => 'The CRM fields forms can use. A form can only ask for a field that is here, so every answer has a place in the CRM.' ),
		'formonly' => array( 'label' => 'Form-only prices', 'desc' => 'Prices that can only be bought through a form that sells them.' ),
		'logs'     => array( 'label' => 'Logs', 'desc' => 'Everything Cart Toolkit did, newest first.' ),
		'updates'  => array( 'label' => 'GitHub Updates', 'desc' => 'Updates this plugin from its GitHub repo.' ),
	);

	/** Notice key => [type, message]. */
	const NOTICES = array(
		'settings_saved' => array( 'success', 'Settings saved.' ),
		'fields_added'   => array( 'success', 'Fields added to the library.' ),
		'field_saved'    => array( 'success', 'Field saved.' ),
		'field_removed'  => array( 'success', 'Field removed from the library. Forms that used it no longer show it.' ),
		'form_created'   => array( 'success', 'Form created. Now choose its prices and fields.' ),
		'form_saved'     => array( 'success', 'Form saved.' ),
		'form_deleted'   => array( 'success', 'Form deleted.' ),
		'form_missing'   => array( 'error', 'That form no longer exists.' ),
		'name_needed'    => array( 'error', 'Give the form a name and choose a product.' ),
		'not_free'       => array( 'error', 'A free signup can only sell $0 prices. Paid prices were left off.' ),
		'formonly_saved' => array( 'success', 'Form-only prices saved.' ),
		'log_cleared'    => array( 'success', 'Log cleared.' ),
		'resent'         => array( 'success', 'Sent to the CRM again.' ),
		'resend_failed'  => array( 'error', 'The CRM send failed again. The log has the reason.' ),
	);

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		foreach ( array(
			'ctk_save_settings',
			'ctk_fields_add',
			'ctk_field_save',
			'ctk_field_remove',
			'ctk_form_create',
			'ctk_form_save',
			'ctk_form_delete',
			'ctk_formonly_save',
			'ctk_log_clear',
		) as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'handle_' . substr( $action, 4 ) ) );
		}
	}

	/* ── Menu, assets, URLs ── */

	private static function menu_slug( $tab ) {
		return 'overview' === $tab ? self::PAGE_SLUG : self::PAGE_SLUG . '-' . $tab;
	}

	public static function register_menu() {
		add_menu_page( 'Cart Toolkit', 'Cart Toolkit', self::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ), 'dashicons-cart', 56 );
		foreach ( self::PAGES as $tab => $def ) {
			add_submenu_page( self::PAGE_SLUG, $def['label'] . ' — Cart Toolkit', $def['label'], self::CAPABILITY, self::menu_slug( $tab ), array( __CLASS__, 'render_page' ) );
		}
	}

	public static function current_tab() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( array_keys( self::PAGES ) as $tab ) {
			if ( self::menu_slug( $tab ) === $page ) {
				return $tab;
			}
		}
		return 'overview';
	}

	private static function is_toolkit_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::PAGE_SLUG === $page || 0 === strpos( $page, self::PAGE_SLUG . '-' );
	}

	public static function enqueue_assets() {
		if ( self::is_toolkit_screen() ) {
			wp_enqueue_style( 'ctk-admin', CTK_URL . 'admin/css/ctk-admin.css', array(), CTK_VERSION );
		}
	}

	public static function page_url( $tab = 'overview', array $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::menu_slug( $tab ) ), $extra ), admin_url( 'admin.php' ) );
	}

	/** The form tag every handler's form opens with. */
	public static function form_open( $action, $extra_class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $extra_class ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
	}

	/* ── Shared view pieces ── */

	public static function render_switch( $name, $checked, $label ) {
		printf(
			'<label class="ctk-switch"><input type="checkbox" name="%s" value="1"%s /><span class="screen-reader-text">%s</span></label>',
			esc_attr( $name ),
			$checked ? ' checked="checked"' : '',
			esc_html( $label )
		);
	}

	public static function render_savebar( $label = 'Save changes', $note = '' ) {
		printf(
			'<div class="ctk-savebar"><span>%s</span><button type="submit" class="button button-primary">%s</button></div>',
			esc_html( $note ),
			esc_html( $label )
		);
	}

	/** @param array[] $checks { label, ok, detail } */
	public static function render_checks_table( array $checks ) {
		echo '<table class="widefat striped"><thead><tr><th>Needed</th><th>State</th><th>Details</th></tr></thead><tbody>';
		foreach ( $checks as $check ) {
			printf(
				'<tr><td>%s</td><td><span class="ctk-pill %s">%s</span></td><td>%s</td></tr>',
				esc_html( $check['label'] ),
				$check['ok'] ? 'is-on' : 'is-off',
				$check['ok'] ? 'Ready' : 'Needs attention',
				esc_html( $check['detail'] )
			);
		}
		echo '</tbody></table>';
	}

	/** One pill: on (green) or off (grey). */
	public static function pill( $on, $label ) {
		return '<span class="ctk-pill ' . ( $on ? 'is-on' : 'is-off' ) . '">' . esc_html( $label ) . '</span>';
	}

	/* ── Page ── */

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Unauthorized.' );
		}
		$tab = self::current_tab();
		$def = self::PAGES[ $tab ];
		?>
		<div class="wrap ctk-admin">
			<header class="ctk-top">
				<span class="ctk-mark" aria-hidden="true">CT</span>
				<h1 class="ctk-brand">Cart Toolkit</h1>
				<span class="ctk-version">v<?php echo esc_html( CTK_VERSION ); ?></span>
			</header>
			<hr class="wp-header-end" />

			<div class="ctk-body">
				<nav class="ctk-side" aria-label="Cart Toolkit pages">
					<div class="ctk-side-group">
						<?php foreach ( self::PAGES as $key => $item ) : ?>
							<a href="<?php echo esc_url( self::page_url( $key ) ); ?>"
								class="ctk-side-item<?php echo $key === $tab ? ' is-active' : ''; ?>"
								<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
								<span><?php echo esc_html( $item['label'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</nav>

				<main class="ctk-content">
					<div class="ctk-page-head">
						<h2 class="ctk-page-title"><?php echo esc_html( $def['label'] ); ?></h2>
						<?php if ( $def['desc'] ) : ?>
							<p class="ctk-lede"><?php echo esc_html( $def['desc'] ); ?></p>
						<?php endif; ?>
					</div>

					<?php self::render_notice(); ?>

					<div class="ctk-view ctk-view-<?php echo esc_attr( $tab ); ?>">
						<?php
						if ( ! in_array( $tab, array( 'overview', 'logs', 'updates' ), true ) && ! CTK_Settings::fluentcart_ready() ) {
							echo '<div class="notice notice-warning inline"><p>FluentCart 2.3.2 or newer must be active for this page.</p></div>';
						} else {
							include CTK_DIR . 'admin/views/page-' . $tab . '.php';
						}
						?>
					</div>
				</main>
			</div>
		</div>
		<?php
	}

	private static function render_notice() {
		$key = isset( $_GET['ctk_notice'] ) ? sanitize_key( $_GET['ctk_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::NOTICES[ $key ] ) ) {
			list( $type, $message ) = self::NOTICES[ $key ];
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/* ── Handlers ── */

	/** Nonce and capability check every handler starts with. */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( $action );
	}

	private static function back( $tab, $notice, array $extra = array() ) {
		wp_safe_redirect( self::page_url( $tab, array_merge( $extra, array( 'ctk_notice' => $notice ) ) ) );
		exit;
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- checked in guard(), sanitized by each caller.
	}

	public static function handle_save_settings() {
		self::guard( 'ctk_save_settings' );
		CTK_Settings::apply( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- checked in guard(), sanitized by apply().
		self::back( 'overview', 'settings_saved' );
	}

	public static function handle_fields_add() {
		self::guard( 'ctk_fields_add' );
		$connector = CTK_Connector::active();
		$keys      = array_map( 'sanitize_text_field', (array) self::post( 'keys', array() ) );
		if ( $connector && $keys ) {
			$added = CTK_Fields::add_from_connector( $keys, $connector );
			CTK_Log::add( 'admin', 'Fields added', true, $added . ' field(s) from ' . $connector->label() );
		}
		self::back( 'fields', 'fields_added' );
	}

	public static function handle_field_save() {
		self::guard( 'ctk_field_save' );
		CTK_Fields::update(
			sanitize_text_field( self::post( 'key' ) ),
			sanitize_text_field( self::post( 'label' ) ),
			sanitize_key( self::post( 'type', 'text' ) ),
			array_map( 'sanitize_text_field', explode( "\n", (string) self::post( 'options' ) ) )
		);
		self::back( 'fields', 'field_saved' );
	}

	public static function handle_field_remove() {
		self::guard( 'ctk_field_remove' );
		CTK_Fields::remove( sanitize_text_field( self::post( 'key' ) ) );
		self::back( 'fields', 'field_removed' );
	}

	public static function handle_form_create() {
		self::guard( 'ctk_form_create' );
		$form = CTK_Forms::create( self::post( 'name' ), self::post( 'product_id' ), self::post( 'kind' ) );
		if ( is_wp_error( $form ) ) {
			self::back( 'forms', 'name_needed' );
		}
		CTK_Log::add( 'admin', 'Form created', true, $form['name'] );
		self::back( 'forms', 'form_created', array( 'edit' => $form['id'] ) );
	}

	public static function handle_form_save() {
		self::guard( 'ctk_form_save' );
		$form = CTK_Forms::get( sanitize_title( self::post( 'id' ) ) );
		if ( ! $form ) {
			self::back( 'forms', 'form_missing' );
		}
		list( $form, $notice ) = CTK_Forms::apply( $form, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- checked in guard(), sanitized by apply().
		CTK_Log::add( 'admin', 'Form saved', true, $form['name'] . ( $form['active'] ? ' (on)' : ' (off)' ) );
		self::back( 'forms', $notice, array( 'edit' => $form['id'] ) );
	}

	public static function handle_form_delete() {
		self::guard( 'ctk_form_delete' );
		$id   = sanitize_title( self::post( 'id' ) );
		$form = CTK_Forms::get( $id );
		CTK_Forms::delete( $id );
		CTK_Log::add( 'admin', 'Form deleted', true, $form ? $form['name'] : $id );
		self::back( 'forms', 'form_deleted' );
	}

	public static function handle_formonly_save() {
		self::guard( 'ctk_formonly_save' );
		CTK_Form_Only::save( array_map( 'absint', (array) self::post( 'ids', array() ) ) );
		CTK_Log::add( 'admin', 'Form-only prices saved', true, count( CTK_Form_Only::ids() ) . ' price(s) form-only' );
		self::back( 'formonly', 'formonly_saved' );
	}

	public static function handle_log_clear() {
		self::guard( 'ctk_log_clear' );
		CTK_Log::clear();
		self::back( 'logs', 'log_cleared' );
	}
}

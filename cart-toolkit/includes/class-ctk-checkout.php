<?php
/**
 * The form engine: puts a form on a page and runs it through FluentCart's checkout.
 *
 * How it fits together:
 * - A page holding [cart_toolkit_form id="…"] gets a cart made for that form,
 *   in Cart Toolkit's own cart group, tagged with the form's id. The page then
 *   redirects to itself with ?fct_cart_hash=…, because FluentCart's checkout
 *   script only sends the cart reference when the address carries it.
 * - The form's own link (?fluent-cart=ctk_form&form=…) opens that page.
 * - FluentCart draws the checkout. Cart Toolkit adds the price picker and the
 *   form's fields inside it, styles it as the form's card, checks everything on
 *   the server before an order exists, and saves the answers to the order.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Hooks\Handlers\ShortCodes\Checkout\CheckoutPageHandler;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Templating\AssetLoader;

class CTK_Checkout {

	const SHORTCODE = 'cart_toolkit_form';

	/** @var array|null The form on the page being shown, and its cart. */
	private static $page_form = null;
	private static $page_cart = null;
	private static $page_error = '';

	/** @var array|null The form whose checkout is being drawn right now, and its cart. */
	private static $rendering = null;
	private static $rendering_cart = null;

	public static function init() {
		add_filter( 'fluent_cart/cart/hash_resolvable_cart_groups', array( __CLASS__, 'resolvable_groups' ) );
		add_action( 'fluent_cart_action_ctk_form', array( __CLASS__, 'handle_form_link' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_page' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );

		add_filter( 'fluent_cart/checkout/route_response', array( __CLASS__, 'switch_option' ), 10, 2 );
		add_action( 'fluent_cart/after_customer_details', array( __CLASS__, 'render_fields' ) );
		add_action( 'fluent_cart/render_custom_form_field', array( __CLASS__, 'render_custom_field' ) );
		add_filter( 'fluent_cart/checkout_page_order_button_text', array( __CLASS__, 'button_text' ) );
		add_filter( 'fluent_cart/checkout/localize_data', array( __CLASS__, 'script_button_text' ), 10, 2 );

		add_filter( 'fluent_cart/checkout/validate_data', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'fluent_cart/checkout/prepare_other_data', array( __CLASS__, 'save_answers' ) );
	}

	public static function resolvable_groups( $groups ) {
		$groups   = (array) $groups;
		$groups[] = CTK_CART_GROUP;
		return array_values( array_unique( $groups ) );
	}

	/* ── Which form a cart belongs to ── */

	/** The form a cart was made for, or null. */
	public static function form_of_cart( $cart ) {
		if ( ! $cart || CTK_CART_GROUP !== $cart->cart_group ) {
			return null;
		}
		$id = $cart->checkout_data['ctk_form'] ?? '';
		return '' !== $id ? CTK_Forms::get( $id ) : null;
	}

	/** The cart named in this request's address, if it was made for this form. */
	private static function cart_for( array $form ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a cart reference, not an action.
		if ( empty( $_GET['fct_cart_hash'] ) ) {
			return null;
		}
		$cart     = CartHelper::getCart();
		$its_form = self::form_of_cart( $cart );
		return $its_form && $its_form['id'] === $form['id'] ? $cart : null;
	}

	/* ── Making a form's cart ── */

	/**
	 * A new cart for a form, holding one of its prices.
	 *
	 * @return \FluentCart\App\Models\Cart|WP_Error
	 */
	public static function make_cart( array $form, $variation_id, array $link_values = array() ) {
		$variation = ProductVariation::query()
			->with( array( 'product', 'product_detail', 'media', 'shippingClass' ) )
			->find( (int) $variation_id );

		if ( ! $variation || ! $variation->product || ! CTK_Forms::sells( $form, $variation->id ) ) {
			return new WP_Error( 'ctk_no_price', __( 'This form has no price to sell right now.', 'cart-toolkit' ) );
		}
		$can = $variation->canPurchase( 1 );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$cart                = CartHelper::generateCartFromVariation( $variation, 1 );
		$cart->cart_data     = array_map( array( __CLASS__, 'unlinked' ), (array) $cart->cart_data );
		$cart->cart_group    = CTK_CART_GROUP;
		$cart->cart_hash     = md5( 'ctk_' . wp_generate_uuid4() . microtime() );
		$cart->checkout_data = array(
			'ctk_form'        => $form['id'],
			'ctk_link'        => $link_values,
			'disable_coupons' => ( $form['coupons'] && 'summary' === $form['style'] ) ? 'no' : 'yes',
		);
		$cart->save();

		return $cart;
	}

	/**
	 * A cart item without its link to FluentCart's product page. A form sells
	 * its prices only through itself, so the checkout's product name and image
	 * stay plain text instead of leading to the product page.
	 */
	public static function unlinked( $item ) {
		$item['view_url'] = '';
		return $item;
	}

	/** Values for the form's "From the link" fields, read from this request's address. */
	private static function link_values( array $form ) {
		$values = array();
		foreach ( $form['fields'] as $row ) {
			if ( 'link' !== $row['mode'] || '' === ( $row['param'] ?? '' ) ) {
				continue;
			}
			$def = CTK_Fields::get( $row['key'] );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tracking values from a public link.
			if ( $def && isset( $_GET[ $row['param'] ] ) ) {
				$values[ $row['key'] ] = CTK_Fields::sanitize( $def, $_GET[ $row['param'] ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		return $values;
	}

	/**
	 * ?fluent-cart=ctk_form&form=<id>[&option=<price>]: open the form's page, or,
	 * for a form with no page, make its cart and open FluentCart's checkout page.
	 */
	public static function handle_form_link( $request ) {
		$form = CTK_Forms::get( sanitize_title( $request['form'] ?? '' ) );
		if ( ! $form || ! $form['active'] ) {
			wp_die( esc_html__( 'This form is closed.', 'cart-toolkit' ), esc_html__( 'Not available', 'cart-toolkit' ), array( 'response' => 404 ) );
		}

		// Everything else on the link (utm_source and the like) travels on.
		$pass = array();
		foreach ( (array) $request as $key => $value ) {
			if ( ! in_array( $key, array( 'fluent-cart', 'form' ), true ) && is_scalar( $value ) ) {
				$pass[ sanitize_key( $key ) ] = rawurlencode( wp_unslash( (string) $value ) );
			}
		}

		$page = CTK_Forms::page_url( $form );
		if ( $page ) {
			nocache_headers();
			wp_safe_redirect( add_query_arg( $pass, $page ) );
			exit;
		}

		$cart = self::make_cart( $form, CTK_Forms::starting_variation( $form, absint( $request['option'] ?? 0 ) ), self::link_values( $form ) );
		if ( is_wp_error( $cart ) ) {
			wp_die( esc_html( $cart->get_error_message() ), esc_html__( 'Not available', 'cart-toolkit' ), array( 'response' => 409 ) );
		}
		unset( $pass['option'] );
		$pass['fct_cart_hash'] = $cart->cart_hash;
		nocache_headers();
		wp_safe_redirect( add_query_arg( $pass, ( new \FluentCart\Api\StoreSettings() )->getCheckoutPage() ) );
		exit;
	}

	/* ── The page a form sits on ── */

	/** The form id in a page's [cart_toolkit_form] shortcode, or ''. */
	private static function form_id_in( $content ) {
		if ( ! has_shortcode( (string) $content, self::SHORTCODE ) ) {
			return '';
		}
		preg_match_all( '/' . get_shortcode_regex( array( self::SHORTCODE ) ) . '/', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$atts = shortcode_parse_atts( $match[3] );
			if ( ! empty( $atts['id'] ) ) {
				return sanitize_title( $atts['id'] );
			}
		}
		return '';
	}

	/**
	 * On a page holding a form: find its cart, or make one and come back to this
	 * page with the cart in the address.
	 */
	public static function prepare_page() {
		if ( ! is_singular() ) {
			return;
		}
		$post    = get_queried_object();
		$form_id = $post instanceof WP_Post ? self::form_id_in( $post->post_content ) : '';
		if ( '' === $form_id ) {
			return;
		}
		$form = CTK_Forms::get( $form_id );
		if ( ! $form || ! $form['active'] ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		$cart = self::cart_for( $form );
		if ( $cart ) {
			self::$page_form = $form;
			self::$page_cart = $cart;
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which price to start on.
		$asked = isset( $_GET['option'] ) ? absint( $_GET['option'] ) : 0;
		$cart  = self::make_cart( $form, CTK_Forms::starting_variation( $form, $asked ), self::link_values( $form ) );
		if ( is_wp_error( $cart ) ) {
			self::$page_form  = $form;
			self::$page_error = $cart->get_error_message();
			CTK_Log::add( 'checkout', 'Form could not start', false, $form['name'] . ': ' . $cart->get_error_message() );
			return;
		}

		// Back to this page, keeping its other parameters (utm_source and the like).
		$keep = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- carried along unchanged.
		foreach ( $_GET as $key => $value ) {
			if ( ! in_array( $key, array( 'option', 'fct_cart_hash' ), true ) && is_scalar( $value ) ) {
				$keep[ sanitize_key( $key ) ] = rawurlencode( wp_unslash( (string) $value ) );
			}
		}
		$keep['fct_cart_hash'] = $cart->cart_hash;
		wp_safe_redirect( add_query_arg( $keep, get_permalink( $post ) ) );
		exit;
	}

	public static function enqueue() {
		if ( ! self::$page_cart ) {
			return;
		}
		AssetLoader::loadCheckoutAssets( self::$page_cart );

		wp_enqueue_style( 'ctk-form', CTK_URL . 'assets/ctk-form.css', array(), CTK_VERSION );
		wp_enqueue_script( 'ctk-form', CTK_URL . 'assets/ctk-form.js', array(), CTK_VERSION, true );
		wp_localize_script( 'ctk-form', 'CTK_FORM', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'fluentcart' ),
			'hash'  => self::$page_cart->cart_hash,
			'form'  => self::$page_form['id'],
			'error' => __( 'That option could not be selected. Please try again.', 'cart-toolkit' ),
		) );
	}

	/** [cart_toolkit_form id="…"] */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, self::SHORTCODE );
		$form = CTK_Forms::get( sanitize_title( $atts['id'] ) );

		if ( ! $form ) {
			return current_user_can( 'manage_options' )
				? '<p class="ctk-form-note">' . esc_html( sprintf( 'Cart Toolkit: no form with the id "%s".', $atts['id'] ) ) . '</p>'
				: '';
		}
		if ( ! $form['active'] ) {
			return '<p class="ctk-form-note">' . esc_html__( 'This form is closed.', 'cart-toolkit' ) . '</p>';
		}
		if ( self::$page_error && self::$page_form && self::$page_form['id'] === $form['id'] ) {
			return '<p class="ctk-form-note">' . esc_html( self::$page_error ) . '</p>';
		}

		$cart = ( self::$page_form && self::$page_form['id'] === $form['id'] ) ? self::$page_cart : null;
		if ( ! $cart ) {
			// The editor's preview, a feed, or a second form on the same page.
			return '<p class="ctk-form-note">' . esc_html( sprintf( 'The "%s" form appears here on the live page.', $form['name'] ) ) . '</p>';
		}

		ob_start();
		printf(
			'<div class="ctk-form ctk-form-%1$s ctk-style-%2$s" data-ctk-form="%3$s" style="--ctk-accent:%4$s">',
			esc_attr( $form['kind'] ),
			esc_attr( $form['style'] ),
			esc_attr( $form['id'] ),
			esc_attr( $form['accent'] )
		);
		self::$rendering      = $form;
		self::$rendering_cart = $cart;
		( new CheckoutPageHandler() )->renderCart( $cart, array() );
		self::$rendering      = null;
		self::$rendering_cart = null;
		echo '</div>';
		return ob_get_clean();
	}

	/** The price picker, after the buyer's details, when a form sells more than one price. */
	private static function render_picker( array $form, $cart ) {
		$variations = CTK_Forms::variations( $form );
		if ( count( $variations ) < 2 ) {
			return;
		}
		$in_cart = array_map( function ( $item ) {
			return (int) ( $item['object_id'] ?? 0 );
		}, (array) $cart->cart_data );
		?>
		<fieldset class="ctk-picker">
			<legend class="ctk-picker-legend"><?php echo esc_html( '' !== $form['plan_heading'] ? $form['plan_heading'] : __( 'Choose your plan', 'cart-toolkit' ) ); ?></legend>
			<div class="ctk-picker-options">
				<?php foreach ( $variations as $variation ) : ?>
					<label class="ctk-option">
						<input type="radio" name="ctk_option" value="<?php echo esc_attr( $variation->id ); ?>" data-ctk-option
							<?php checked( in_array( (int) $variation->id, $in_cart, true ) ); ?> />
						<span class="ctk-option-name">
							<span class="ctk-option-title"><?php echo esc_html( $variation->variation_title ); ?></span>
							<?php if ( ! empty( $form['price_notes'][ $variation->id ] ) ) : ?>
								<span class="ctk-option-note"><?php echo esc_html( $form['price_notes'][ $variation->id ] ); ?></span>
							<?php endif; ?>
						</span>
						<span class="ctk-option-price"><?php echo esc_html( CTK_Forms::price_label( $variation ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="ctk-picker-error" role="alert"></p>
		</fieldset>
		<?php
	}

	/**
	 * Checkout verb ctk_switch_option: swap the form's price in the cart. Runs
	 * under FluentCart's own nonce check for checkout routes.
	 */
	public static function switch_option( $result, $context ) {
		if ( 'ctk_switch_option' !== ( $context['action'] ?? '' ) ) {
			return $result;
		}

		$cart = CartHelper::getCart();
		$form = self::form_of_cart( $cart );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- FluentCart verified the nonce for this route.
		$wanted = isset( $_POST['option'] ) ? absint( $_POST['option'] ) : 0;

		if ( ! $form || ! $form['active'] || ! CTK_Forms::sells( $form, $wanted ) ) {
			return new WP_Error( 'ctk_not_on_form', __( 'That option is not available on this form.', 'cart-toolkit' ) );
		}

		$variation = ProductVariation::query()->with( array( 'product', 'product_detail', 'media', 'shippingClass' ) )->find( $wanted );
		$can       = $variation ? $variation->canPurchase( 1 ) : new WP_Error( 'ctk_gone', __( 'That option is no longer available.', 'cart-toolkit' ) );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		// Replace the form's own price; anything else in the cart (an order bump) stays.
		$items = array();
		foreach ( (array) $cart->cart_data as $item ) {
			if ( (int) ( $item['post_id'] ?? 0 ) !== (int) $form['product_id'] ) {
				$items[] = $item;
			}
		}
		$new = self::unlinked( CartHelper::generateCartItemFromVariation( $variation, 1 ) );
		array_unshift( $items, $new );

		$cart->cart_data = $items;
		$cart->save();
		if ( method_exists( $cart, 'reValidateCoupons' ) ) {
			$cart->reValidateCoupons();
		}
		do_action( 'fluent_cart/cart/cart_data_items_updated', array(
			'cart'       => $cart,
			'scope'      => 'item_added',
			'scope_data' => $new,
		) );

		return array( 'switched' => (int) $variation->id );
	}

	/** The form's button text, or '' for FluentCart's own. {price} becomes what the chosen price charges today. */
	private static function button_label( array $form, $cart ) {
		return '' === $form['button_text'] ? '' : str_replace( '{price}', self::form_amount( $form, $cart ), $form['button_text'] );
	}

	/** The button as the page is drawn. */
	public static function button_text( $text ) {
		$label = self::$rendering ? self::button_label( self::$rendering, self::$rendering_cart ) : '';
		return '' !== $label ? $label : $text;
	}

	/**
	 * The button text FluentCart's scripts put back once a payment box loads
	 * (Stripe resets it from fluentcart_checkout_vars.submit_button.text).
	 */
	public static function script_button_text( $data, $cart ) {
		$form  = self::form_of_cart( $cart );
		$label = $form ? self::button_label( $form, $cart ) : '';
		if ( '' !== $label && isset( $data['fluentcart_checkout_vars'] ) ) {
			// The script writes it as HTML.
			$data['fluentcart_checkout_vars']['submit_button']['text'] = esc_html( $label );
		}
		return $data;
	}

	/** What the form's own price in the cart charges today, as "$299.00". */
	private static function form_amount( array $form, $cart ) {
		foreach ( (array) ( $cart ? $cart->cart_data : array() ) as $item ) {
			if ( (int) ( $item['post_id'] ?? 0 ) === (int) $form['product_id'] ) {
				return CTK_Forms::amount_label( (int) ( $item['subtotal'] ?? $item['unit_price'] ?? 0 ) );
			}
		}
		return '';
	}

	/* ── The form's fields inside the checkout ── */

	/** The form's "Ask the buyer" rows whose field is still in the library. */
	private static function asked_fields( array $form ) {
		$rows = array();
		foreach ( $form['fields'] as $row ) {
			$def = CTK_Fields::get( $row['key'] );
			if ( 'ask' === $row['mode'] && $def ) {
				$rows[] = array( $row, $def );
			}
		}
		return $rows;
	}

	/** @param array $args ['cart' => Cart] */
	public static function render_fields( $args ) {
		$cart = $args['cart'] ?? null;
		$form = self::form_of_cart( $cart );
		if ( ! $form ) {
			return;
		}
		self::render_picker( $form, $cart );

		$fields = array();
		foreach ( self::asked_fields( $form ) as list( $row, $def ) ) {
			$fields[] = CTK_Fields::to_checkout_field( $row['key'], $def, ! empty( $row['required'] ) );
		}
		if ( ! $fields ) {
			return;
		}
		( new \FluentCart\App\Services\Renderer\FormFieldRenderer() )->renderField( array(
			'type'    => 'section',
			'id'      => 'ctk_form_fields',
			'heading' => esc_html( $form['fields_heading'] ),
			'fields'  => $fields,
		) );
	}

	/** Field types FluentCart doesn't draw itself: web address, date, choice, checkboxes. */
	public static function render_custom_field( $field ) {
		$type = $field['type'] ?? '';
		if ( 0 !== strpos( $type, 'ctk_' ) ) {
			return;
		}
		$id       = $field['id'];
		$name     = $field['name'];
		$required = 'yes' === ( $field['required'] ?? '' );
		$kind     = substr( $type, 4 );

		echo '<div class="fct_input_wrapper fct_input_wrapper_' . esc_attr( $kind ) . ' ctk-field-' . esc_attr( $kind ) . '" id="fct_wrapper_' . esc_attr( $id ) . '">';

		if ( in_array( $kind, array( 'url', 'date' ), true ) ) {
			echo '<label for="' . esc_attr( $id ) . '" class="fct_input_label">' . wp_kses_post( $field['label'] ) . '</label>';
			printf(
				'<input type="%1$s" class="fct-input fct-input-%1$s" id="%2$s" name="%3$s" value=""%4$s />',
				esc_attr( $kind ),
				esc_attr( $id ),
				esc_attr( $name ),
				$required ? ' required aria-required="true"' : ''
			);
		} else {
			// Radio buttons share one name; checkboxes each get their own, because
			// FluentCart's checkout keeps only the first value of a repeated name.
			echo '<fieldset class="ctk-choices"><legend class="fct_input_label">' . wp_kses_post( $field['label'] ) . '</legend>';
			foreach ( array_values( (array) $field['options'] ) as $i => $option ) {
				$input_name = 'radio' === $kind ? $name : $name . '[' . $i . ']';
				printf(
					'<label class="ctk-choice"><input type="%1$s" name="%2$s" value="%3$s"%4$s /> <span>%5$s</span></label>',
					'radio' === $kind ? 'radio' : 'checkbox',
					esc_attr( $input_name ),
					esc_attr( $option ),
					( 'radio' === $kind && $required && 0 === $i ) ? ' required' : '',
					esc_html( $option )
				);
			}
			echo '</fieldset>';
		}
		echo '</div>';
	}

	/**
	 * Read and clean the form's asked answers from submitted data.
	 *
	 * @return array{0: array, 1: array} [ key => value, errors key => message ]
	 */
	public static function read_answers( array $form, array $data ) {
		$submitted = isset( $data[ CTK_Fields::INPUT ] ) && is_array( $data[ CTK_Fields::INPUT ] ) ? $data[ CTK_Fields::INPUT ] : array();
		$values    = array();
		$errors    = array();
		foreach ( self::asked_fields( $form ) as list( $row, $def ) ) {
			$value = CTK_Fields::sanitize( $def, $submitted[ $row['key'] ] ?? '' );
			$error = CTK_Fields::validate( $def, $value, ! empty( $row['required'] ) );
			if ( $error ) {
				$errors[ $row['key'] ] = $error;
			}
			$values[ $row['key'] ] = $value;
		}
		return array( $values, $errors );
	}

	/* ── Server checks, before an order exists ── */

	/**
	 * @param array $errors  field => [rule => message]
	 * @param array $context ['data' => array, 'cart' => Cart]
	 */
	public static function validate( $errors, $context ) {
		$cart = $context['cart'] ?? null;
		if ( ! $cart || CTK_CART_GROUP !== $cart->cart_group ) {
			return $errors;
		}

		$form = self::form_of_cart( $cart );
		if ( ! $form || ! $form['active'] ) {
			$errors['ctk_form']['closed'] = __( 'This form is closed.', 'cart-toolkit' );
			return $errors;
		}

		// Every item of the form's product must be a price this form sells.
		$has_form_item = false;
		foreach ( (array) $cart->cart_data as $item ) {
			if ( (int) ( $item['post_id'] ?? 0 ) !== (int) $form['product_id'] ) {
				continue;
			}
			$has_form_item = true;
			$variation_id  = (int) ( $item['object_id'] ?? 0 );
			if ( ! CTK_Forms::sells( $form, $variation_id ) ) {
				$errors['ctk_option']['not_on_form'] = __( 'That option is not available on this form.', 'cart-toolkit' );
			} elseif ( 'free' === $form['kind'] ) {
				$variation = ProductVariation::find( $variation_id );
				if ( ! $variation || ! CTK_Forms::is_free_price( $variation ) ) {
					$errors['ctk_option']['not_free'] = __( 'This signup is no longer free. Please contact us.', 'cart-toolkit' );
				}
			}
		}
		if ( ! $has_form_item ) {
			$errors['ctk_option']['empty'] = __( 'Please choose an option.', 'cart-toolkit' );
		}

		list( , $field_errors ) = self::read_answers( $form, (array) ( $context['data'] ?? array() ) );
		foreach ( $field_errors as $key => $message ) {
			$errors[ CTK_Fields::input_id( $key ) ]['invalid'] = $message;
		}

		if ( $errors ) {
			CTK_Log::add( 'checkout', 'Checkout refused', true, $form['name'] . ': ' . implode( ' ', array_map( function ( $rules ) {
				return implode( ' ', (array) $rules );
			}, $errors ) ) );
		}
		return $errors;
	}

	/* ── Saving the answers to the order ── */

	/**
	 * @param array $args ['cart', 'order', 'prev_order', 'request_data', 'validated_data']
	 */
	public static function save_answers( $args ) {
		$cart  = $args['cart'] ?? null;
		$order = $args['order'] ?? null;
		$form  = self::form_of_cart( $cart );
		if ( ! $form || ! $order ) {
			return;
		}

		list( $asked ) = self::read_answers( $form, (array) ( $args['request_data'] ?? array() ) );
		$from_link     = (array) ( $cart->checkout_data['ctk_link'] ?? array() );

		$answers = array();
		foreach ( $form['fields'] as $row ) {
			$def = CTK_Fields::get( $row['key'] );
			if ( ! $def ) {
				continue;
			}
			if ( 'ask' === $row['mode'] && array_key_exists( $row['key'], $asked ) ) {
				$answers[ $row['key'] ] = array( 'label' => $def['label'], 'value' => $asked[ $row['key'] ], 'mode' => 'ask' );
			} elseif ( 'link' === $row['mode'] && array_key_exists( $row['key'], $from_link ) ) {
				$answers[ $row['key'] ] = array( 'label' => $def['label'], 'value' => $from_link[ $row['key'] ], 'mode' => 'link' );
			}
		}

		$order->updateMeta( CTK_META_FORM, array( 'id' => $form['id'], 'name' => $form['name'] ) );
		$order->updateMeta( CTK_META_ANSWERS, $answers );

		self::link_existing_login( $order );
	}

	/**
	 * Connect the order's customer to the WordPress account with the same email.
	 *
	 * FluentCart leaves that link out for guests, and its FluentCommunity access
	 * removes members only through a linked account, so without it a cancel or a
	 * refund would never take access away. Nobody is logged in by this.
	 */
	private static function link_existing_login( $order ) {
		if ( ! CTK_Settings::get( 'link_existing' ) ) {
			return;
		}
		$customer = $order->customer;
		if ( ! $customer || $customer->user_id || ! $customer->email ) {
			return;
		}
		$user = get_user_by( 'email', $customer->email );
		if ( ! $user ) {
			return;
		}
		$customer->user_id = $user->ID;
		$customer->save();
		CTK_Log::add( 'checkout', 'Connected to existing login', true, $customer->email . ' → user #' . $user->ID, (int) $order->id );
	}
}

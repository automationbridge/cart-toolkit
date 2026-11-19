<?php
/**
 * Form-only prices: a price that can only be bought through a form that sells it.
 *
 * Three doors are covered. A FluentCart buy link for the price is sent to the
 * form that sells it. Any cart that reaches the checkout with the price and
 * without such a form is refused by the server. Renewals never go through the
 * checkout, so current subscribers keep renewing.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

class CTK_Form_Only {

	public static function init() {
		// FluentCart's instant-checkout route runs on init at priority 99.
		add_action( 'init', array( __CLASS__, 'redirect_buy_link' ), 5 );
		add_filter( 'fluent_cart/checkout/validate_data', array( __CLASS__, 'validate' ), 20, 2 );
	}

	/** @return int[] */
	public static function ids() {
		$ids = get_option( CTK_OPT_FORM_ONLY, array() );
		return array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	public static function save( array $ids ) {
		update_option( CTK_OPT_FORM_ONLY, array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ) );
	}

	public static function is_form_only( $variation_id ) {
		return in_array( (int) $variation_id, self::ids(), true );
	}

	/**
	 * A buy link (?fluent-cart=instant_checkout&item_id=…) for a form-only price
	 * goes to the active form that sells it, or says the price is gone.
	 */
	public static function redirect_buy_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public link, read only.
		if ( 'instant_checkout' !== ( $_GET['fluent-cart'] ?? '' ) ) {
			return;
		}
		$variation_id = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;
		// phpcs:enable
		if ( ! $variation_id || ! self::is_form_only( $variation_id ) ) {
			return;
		}

		foreach ( CTK_Forms::forms_selling( $variation_id ) as $form ) {
			if ( $form['active'] ) {
				CTK_Log::add( 'formonly', 'Buy link sent to form', true, 'Price #' . $variation_id . ' → ' . $form['name'] );
				wp_safe_redirect( CTK_Forms::link( $form, $variation_id ) );
				exit;
			}
		}

		CTK_Log::add( 'formonly', 'Buy link refused', true, 'Price #' . $variation_id . ' is on no active form.' );
		wp_die(
			esc_html__( 'This option is no longer available.', 'cart-toolkit' ),
			esc_html__( 'Not available', 'cart-toolkit' ),
			array(
				'response'  => 410,
				'link_url'  => esc_url( home_url( '/' ) ),
				'link_text' => esc_html__( 'Back to the site', 'cart-toolkit' ),
			)
		);
	}

	/**
	 * Refuse a form-only price in any cart whose form doesn't sell it.
	 *
	 * @param array $errors  field => [rule => message]
	 * @param array $context ['data' => array, 'cart' => Cart]
	 */
	public static function validate( $errors, $context ) {
		$cart = $context['cart'] ?? null;
		if ( ! $cart || ! self::ids() ) {
			return $errors;
		}

		$form = CTK_Checkout::form_of_cart( $cart );
		foreach ( (array) $cart->cart_data as $item ) {
			$variation_id = (int) ( $item['object_id'] ?? 0 );
			if ( ! self::is_form_only( $variation_id ) ) {
				continue;
			}
			if ( $form && $form['active'] && CTK_Forms::sells( $form, $variation_id ) ) {
				continue;
			}
			$errors['ctk_form_only']['not_allowed'] = sprintf(
				/* translators: %s: the price's name */
				__( '%s can only be bought through its signup page.', 'cart-toolkit' ),
				(string) ( $item['title'] ?? __( 'This option', 'cart-toolkit' ) )
			);
			CTK_Log::add( 'formonly', 'Checkout refused', true, 'Price #' . $variation_id . ( $form ? ' is not on ' . $form['name'] : ' outside any form' ) );
		}
		return $errors;
	}
}

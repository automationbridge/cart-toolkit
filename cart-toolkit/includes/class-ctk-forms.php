<?php
/**
 * Forms: one list, two kinds.
 *
 * A paid form sells chosen prices of one product. A free signup sells one $0
 * price. Both run through FluentCart's own checkout, so a free signup is a real
 * FluentCart purchase with every after-purchase step that implies.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;

class CTK_Forms {

	const KINDS = array(
		'paid' => 'Paid checkout',
		'free' => 'Free signup',
	);

	/** How the form's checkout card looks. The page around it is the page's own design. */
	const STYLES = array(
		'card'    => 'Simple card: one column, the price on the button, no order summary',
		'summary' => 'Card with order summary: the form beside a summary of the order',
	);

	const DEFAULT_ACCENT = '#1e3a8a';

	const INTERVALS = array(
		'daily'       => 'day',
		'weekly'      => 'week',
		'monthly'     => 'month',
		'quarterly'   => '3 months',
		'half_yearly' => '6 months',
		'yearly'      => 'year',
	);

	const DEFAULTS = array(
		'id'                => '',
		'name'              => '',
		'kind'              => 'paid',
		'active'            => true,
		'product_id'        => 0,
		'variation_ids'     => array(),
		'default_variation' => 0,
		'page_id'           => 0,
		'coupons'           => false,
		'button_text'       => '',
		'fields_heading'    => 'A few questions',
		'fields'            => array(),
		'style'             => 'card',
		'accent'            => self::DEFAULT_ACCENT,
		'plan_heading'      => 'Choose your plan',
		'price_notes'       => array(),
		'thank_you_page'    => 0,
		'thank_you_url'     => '',
	);

	/** @return array id => form */
	public static function all() {
		$forms = get_option( CTK_OPT_FORMS, array() );
		$forms = is_array( $forms ) ? $forms : array();
		foreach ( $forms as $id => $form ) {
			$forms[ $id ] = array_merge( self::DEFAULTS, (array) $form, array( 'id' => (string) $id ) );
		}
		return $forms;
	}

	public static function get( $id ) {
		$all = self::all();
		return $all[ (string) $id ] ?? null;
	}

	/** Save a whole form. Its id is fixed once created. */
	public static function save( array $form ) {
		$all                 = get_option( CTK_OPT_FORMS, array() );
		$all                 = is_array( $all ) ? $all : array();
		$form                = array_merge( self::DEFAULTS, $form );
		$all[ $form['id'] ] = $form;
		update_option( CTK_OPT_FORMS, $all );
	}

	public static function delete( $id ) {
		$all = get_option( CTK_OPT_FORMS, array() );
		unset( $all[ (string) $id ] );
		update_option( CTK_OPT_FORMS, $all );
	}

	/**
	 * A new form, switched off until its prices are chosen. Saved and returned.
	 *
	 * @return array|WP_Error
	 */
	public static function create( $name, $product_id, $kind ) {
		$name       = sanitize_text_field( (string) $name );
		$product_id = absint( $product_id );
		if ( '' === $name || ! $product_id || ! isset( self::products()[ $product_id ] ) ) {
			return new WP_Error( 'name_needed', 'Give the form a name and choose a product.' );
		}
		$kind = 'free' === $kind ? 'free' : 'paid';
		$form = array_merge( self::DEFAULTS, array(
			'id'          => self::new_id( $name ),
			'name'        => $name,
			'kind'        => $kind,
			'product_id'  => $product_id,
			'active'      => false,
			'button_text' => 'free' === $kind ? 'Join free' : '',
		) );
		self::save( $form );
		return $form;
	}

	/**
	 * Apply edited settings to a form and save it. $input has the editor's keys,
	 * unslashed and unsanitized; a missing switch (active, coupons) means off.
	 * Only prices of the form's product are kept, and a free signup keeps only $0 ones.
	 *
	 * @return array [ saved form, notice key: 'form_saved' or 'not_free' ]
	 */
	public static function apply( array $form, array $input ) {
		$notice   = 'form_saved';
		$selected = array_map( 'absint', (array) ( $input['variation_ids'] ?? array() ) );

		$allowed = array();
		foreach ( self::product_variations( $form['product_id'] ) as $variation ) {
			if ( ! in_array( (int) $variation->id, $selected, true ) ) {
				continue;
			}
			if ( 'free' === $form['kind'] && ! self::is_free_price( $variation ) ) {
				$notice = 'not_free';
				continue;
			}
			$allowed[] = (int) $variation->id;
		}
		// Keep the order the prices were chosen in.
		$allowed = array_values( array_intersect( $selected, $allowed ) );

		$rows = array();
		foreach ( (array) ( $input['fields'] ?? array() ) as $row ) {
			$row = (array) $row;
			$key = sanitize_text_field( $row['key'] ?? '' );
			if ( '' === $key || ! CTK_Fields::get( $key ) ) {
				continue;
			}
			$mode   = sanitize_key( $row['mode'] ?? 'ask' );
			$rows[] = array(
				'key'      => $key,
				'mode'     => isset( CTK_Fields::MODES[ $mode ] ) ? $mode : 'ask',
				'required' => ! empty( $row['required'] ),
				'value'    => sanitize_text_field( $row['value'] ?? '' ),
				'param'    => sanitize_key( $row['param'] ?? '' ),
				'source'   => array_key_exists( $row['source'] ?? '', CTK_Fields::ORDER_SOURCES ) ? $row['source'] : 'user_id',
			);
		}

		// A short line under a price's name, for the prices on the form.
		$notes = array();
		foreach ( (array) ( $input['price_notes'] ?? array() ) as $id => $note ) {
			$note = sanitize_text_field( (string) $note );
			if ( '' !== $note && in_array( (int) $id, $allowed, true ) ) {
				$notes[ (int) $id ] = mb_substr( $note, 0, 80 );
			}
		}

		$style   = sanitize_key( $input['style'] ?? 'card' );
		$style   = isset( self::STYLES[ $style ] ) ? $style : 'card';
		$accent  = sanitize_hex_color( (string) ( $input['accent'] ?? '' ) );
		$default = absint( $input['default_variation'] ?? 0 );
		$form    = array_merge( $form, array(
			'name'              => sanitize_text_field( $input['name'] ?? $form['name'] ),
			'active'            => ! empty( $input['active'] ),
			'variation_ids'     => $allowed,
			'default_variation' => in_array( $default, $allowed, true ) ? $default : 0,
			'page_id'           => absint( $input['page_id'] ?? 0 ),
			// The coupon box lives in the order summary, which only that style shows.
			'coupons'           => 'paid' === $form['kind'] && 'summary' === $style && ! empty( $input['coupons'] ),
			'button_text'       => sanitize_text_field( $input['button_text'] ?? '' ),
			'fields_heading'    => sanitize_text_field( $input['fields_heading'] ?? '' ),
			'fields'            => $rows,
			'style'             => $style,
			'accent'            => $accent ? $accent : self::DEFAULT_ACCENT,
			'plan_heading'      => sanitize_text_field( $input['plan_heading'] ?? '' ),
			'price_notes'       => $notes,
			'thank_you_page'    => absint( $input['thank_you_page'] ?? 0 ),
			'thank_you_url'     => esc_url_raw( trim( (string) ( $input['thank_you_url'] ?? '' ) ), array( 'http', 'https' ) ),
		) );
		self::save( $form );
		return array( $form, $notice );
	}

	/** A new form's id: its name as a slug, made unique. */
	public static function new_id( $name ) {
		$base = sanitize_title( $name );
		$base = '' !== $base ? $base : 'form';
		$id   = $base;
		$n    = 2;
		while ( self::get( $id ) ) {
			$id = $base . '-' . $n++;
		}
		return $id;
	}

	/** The form's own link. It opens the form's page with a fresh cart. */
	public static function link( array $form, $variation_id = 0 ) {
		$args = array(
			'fluent-cart' => 'ctk_form',
			'form'        => $form['id'],
		);
		if ( $variation_id ) {
			$args['option'] = (int) $variation_id;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** The page the form is placed on, or '' when none is set. */
	public static function page_url( array $form ) {
		return $form['page_id'] ? (string) get_permalink( (int) $form['page_id'] ) : '';
	}

	/** Prices this form sells, in the form's order, that still exist. */
	public static function variations( array $form ) {
		$ids = array_map( 'intval', (array) $form['variation_ids'] );
		if ( ! $ids ) {
			return array();
		}
		$found = ProductVariation::query()->whereIn( 'id', $ids )->get()->keyBy( 'id' );
		$list  = array();
		foreach ( $ids as $id ) {
			if ( isset( $found[ $id ] ) ) {
				$list[] = $found[ $id ];
			}
		}
		return $list;
	}

	/** Whether a form sells this price. */
	public static function sells( array $form, $variation_id ) {
		return in_array( (int) $variation_id, array_map( 'intval', (array) $form['variation_ids'] ), true );
	}

	/** The price a new cart starts with: the one asked for, else the default, else the first. */
	public static function starting_variation( array $form, $asked = 0 ) {
		if ( $asked && self::sells( $form, $asked ) ) {
			return (int) $asked;
		}
		if ( $form['default_variation'] && self::sells( $form, $form['default_variation'] ) ) {
			return (int) $form['default_variation'];
		}
		$ids = array_map( 'intval', (array) $form['variation_ids'] );
		return $ids ? $ids[0] : 0;
	}

	/** Forms that sell a price, active ones first. */
	public static function forms_selling( $variation_id ) {
		$list = array_filter( self::all(), function ( $form ) use ( $variation_id ) {
			return self::sells( $form, $variation_id );
		} );
		uasort( $list, function ( $a, $b ) {
			return (int) $b['active'] - (int) $a['active'];
		} );
		return $list;
	}

	/* ── FluentCart products and prices ── */

	/** id => title, every product that isn't trashed. */
	public static function products() {
		$list = array();
		foreach ( Product::query()->whereIn( 'post_status', array( 'publish', 'private', 'draft' ) )->orderBy( 'post_title' )->get() as $product ) {
			$list[ (int) $product->ID ] = $product->post_title . ( 'publish' !== $product->post_status ? ' (' . $product->post_status . ')' : '' );
		}
		return $list;
	}

	/** A product's prices. */
	public static function product_variations( $product_id ) {
		return ProductVariation::query()->where( 'post_id', (int) $product_id )->orderBy( 'serial_index' )->orderBy( 'id' )->get();
	}

	/** "$999.00 / year", "$0.00", "$299.00 every 3 months". */
	public static function price_label( $variation ) {
		$price = Helper::toDecimal( (int) $variation->item_price );
		if ( 'subscription' !== $variation->payment_type ) {
			return $price;
		}
		$interval = $variation->other_info['repeat_interval'] ?? 'yearly';
		$word     = self::INTERVALS[ $interval ] ?? $interval;
		return false !== strpos( $word, ' ' ) ? $price . ' every ' . $word : $price . ' / ' . $word;
	}

	/** "$299.00": what a price charges today, as plain text. */
	public static function amount_label( $cents ) {
		return html_entity_decode( Helper::toDecimal( (int) $cents ), ENT_QUOTES, 'UTF-8' );
	}

	/** Whether a price costs nothing, now and on renewal. */
	public static function is_free_price( $variation ) {
		return 0 === (int) $variation->item_price
			&& ( 'subscription' !== $variation->payment_type || empty( $variation->other_info['manage_setup_fee'] ) || 'no' === $variation->other_info['manage_setup_fee'] );
	}
}

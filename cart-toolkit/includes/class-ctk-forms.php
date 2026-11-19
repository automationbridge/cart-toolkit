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

	/** Whether a price costs nothing, now and on renewal. */
	public static function is_free_price( $variation ) {
		return 0 === (int) $variation->item_price
			&& ( 'subscription' !== $variation->payment_type || empty( $variation->other_info['manage_setup_fee'] ) || 'no' === $variation->other_info['manage_setup_fee'] );
	}
}

<?php
/**
 * Field library: the CRM fields forms are allowed to use.
 *
 * Every entry is keyed by the CRM's own field key, so a form field is always a
 * CRM field and no answer is ever collected without somewhere to go.
 * This class also owns how a field is drawn, cleaned and checked, so paid forms
 * and the free signup share one implementation.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

class CTK_Fields {

	/** How a field on a form gets its value. */
	const MODES = array(
		'ask'   => 'Ask the buyer',
		'set'   => 'Set a fixed value',
		'link'  => 'From the link',
		'order' => 'From the order',
	);

	/** Values a field can take from the order ("From the order" mode). */
	const ORDER_SOURCES = array(
		'user_id'      => 'WordPress user ID',
		'price_name'   => 'Price name (for example Scale)',
		'product_name' => 'Product name',
		'order_id'     => 'Order number',
		'order_date'   => 'Order date',
		'order_total'  => 'Order total',
	);

	/** The name every answer is submitted under: ctk_f[<key>]. */
	const INPUT = 'ctk_f';

	/* ── Library storage ── */

	/** @return array key => { label, type, options[], group } */
	public static function all() {
		$fields = get_option( CTK_OPT_FIELDS, array() );
		return is_array( $fields ) ? $fields : array();
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Add CRM fields to the library, keeping any already there unchanged. */
	public static function add_from_connector( array $keys, CTK_Connector $connector ) {
		$all   = self::all();
		$added = 0;
		foreach ( $connector->get_fields() as $field ) {
			if ( in_array( $field['key'], $keys, true ) && ! isset( $all[ $field['key'] ] ) ) {
				$all[ $field['key'] ] = array(
					'label'   => $field['label'],
					'type'    => $field['type'],
					'options' => $field['options'],
					'group'   => $field['group'],
				);
				$added++;
			}
		}
		update_option( CTK_OPT_FIELDS, $all );
		return $added;
	}

	/** Change a library field's label, type and choices. */
	public static function update( $key, $label, $type, array $options ) {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return;
		}
		$all[ $key ]['label']   = '' !== $label ? $label : $key;
		$all[ $key ]['type']    = isset( CTK_Connector::TYPES[ $type ] ) ? $type : 'text';
		$all[ $key ]['options'] = array_values( array_filter( array_map( 'trim', $options ), 'strlen' ) );
		update_option( CTK_OPT_FIELDS, $all );
	}

	public static function remove( $key ) {
		$all = self::all();
		unset( $all[ $key ] );
		update_option( CTK_OPT_FIELDS, $all );
	}

	/* ── Drawing a field on the checkout ── */

	/**
	 * A FluentCart form-field array, drawn by FluentCart's own renderer so it
	 * matches the rest of the checkout. Types FluentCart doesn't draw get a ctk_
	 * type, which CTK_Checkout::render_custom_field() draws with the same classes.
	 */
	public static function to_checkout_field( $key, array $def, $required, $value = '' ) {
		$type  = $def['type'] ?? 'text';
		$label = esc_html( $def['label'] ?? $key ) . ( $required ? ' <span class="ctk-req" aria-hidden="true">*</span>' : '' );
		$field = array(
			'id'       => self::input_id( $key ),
			'name'     => self::INPUT . '[' . $key . ']',
			'label'    => $label,
			'required' => $required ? 'yes' : '',
			'value'    => $value,
		);

		switch ( $type ) {
			case 'text':
			case 'email':
			case 'tel':
			case 'number':
			case 'textarea':
				$field['type'] = $type;
				break;
			case 'checkbox':
				$field['type']           = 'checkbox';
				$field['checkbox_value'] = 'yes';
				break;
			case 'select':
				$field['type']    = 'select';
				$field['options'] = array_merge(
					array( array( 'value' => '', 'name' => 'Choose…' ) ),
					array_map( function ( $option ) {
						return array( 'value' => $option, 'name' => $option );
					}, $def['options'] ?? array() )
				);
				break;
			default:
				// url, date, radio, checkboxes.
				$field['type']     = 'ctk_' . $type;
				$field['ctk_key']  = $key;
				$field['options']  = $def['options'] ?? array();
				$field['raw_label'] = $def['label'] ?? $key;
		}
		return $field;
	}

	public static function input_id( $key ) {
		return 'ctk_f_' . preg_replace( '/[^a-z0-9_]/i', '_', (string) $key );
	}

	/* ── Cleaning and checking an answer ── */

	/**
	 * Clean a submitted value for a field.
	 *
	 * @return string|string[] An array only for checkboxes.
	 */
	public static function sanitize( array $def, $raw ) {
		$type    = $def['type'] ?? 'text';
		$options = $def['options'] ?? array();

		if ( 'checkboxes' === $type ) {
			$picked = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $raw ) );
			return array_values( array_intersect( $options, $picked ) );
		}
		if ( is_array( $raw ) ) {
			return '';
		}
		$raw = wp_unslash( (string) $raw );

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'email':
				return sanitize_email( $raw );
			case 'url':
				return esc_url_raw( trim( $raw ) );
			case 'number':
				// Kept as typed; validate() rejects anything that isn't a number.
				return sanitize_text_field( $raw );
			case 'checkbox':
				return 'yes' === $raw ? 'yes' : '';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * What's wrong with a cleaned value, or '' when it's fine.
	 */
	public static function validate( array $def, $value, $required ) {
		$label   = $def['label'] ?? 'This field';
		$type    = $def['type'] ?? 'text';
		$isEmpty = is_array( $value ) ? ! $value : '' === trim( (string) $value );

		if ( $isEmpty ) {
			return $required ? sprintf( '%s is required.', $label ) : '';
		}

		switch ( $type ) {
			case 'email':
				return is_email( $value ) ? '' : sprintf( '%s needs a valid email address.', $label );
			case 'number':
				return is_numeric( $value ) ? '' : sprintf( '%s needs a number.', $label );
			case 'url':
				return wp_http_validate_url( $value ) ? '' : sprintf( '%s needs a full web address, starting with https://', $label );
			case 'date':
				$d = DateTime::createFromFormat( 'Y-m-d', (string) $value );
				return ( $d && $d->format( 'Y-m-d' ) === $value ) ? '' : sprintf( '%s needs a date.', $label );
			case 'select':
			case 'radio':
				return in_array( $value, $def['options'] ?? array(), true ) ? '' : sprintf( '%s needs one of its choices.', $label );
		}
		return '';
	}

	/* ── Values taken from the order ── */

	/**
	 * @param string                          $source An ORDER_SOURCES key.
	 * @param \FluentCart\App\Models\Order    $order
	 */
	public static function value_from_order( $source, $order ) {
		$item = $order->order_items ? $order->order_items->first() : null;

		switch ( $source ) {
			case 'user_id':
				$customer = $order->customer ? $order->customer->fresh() : null;
				return $customer && $customer->user_id ? (string) $customer->user_id : '';
			case 'price_name':
				return $item ? (string) $item->title : '';
			case 'product_name':
				return $item ? (string) $item->post_title : '';
			case 'order_id':
				return (string) $order->id;
			case 'order_date':
				return $order->created_at ? gmdate( 'Y-m-d', strtotime( (string) $order->created_at ) ) : '';
			case 'order_total':
				return number_format( ( (int) $order->total_amount ) / 100, 2, '.', '' );
		}
		return '';
	}
}

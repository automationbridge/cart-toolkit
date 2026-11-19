<?php
/**
 * Setup API: everything the admin pages do, for an administrator signed in with
 * an application password. Each route calls the same class method its admin
 * handler calls, so a change made here passes the same checks.
 *
 * Namespace cart-toolkit/v1. Every route needs manage_options.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

use FluentCart\App\Models\Order;

class CTK_Rest {

	const NS = 'cart-toolkit/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$routes = array(
			'/state'                                => array( 'GET' => 'state' ),
			'/settings'                             => array( 'POST' => 'save_settings' ),
			'/fields'                               => array( 'POST' => 'add_fields' ),
			'/fields/(?P<key>[A-Za-z0-9_.\-]+)'     => array( 'POST' => 'save_field', 'DELETE' => 'remove_field' ),
			'/forms'                                => array( 'POST' => 'create_form' ),
			'/forms/(?P<id>[a-z0-9\-]+)'            => array( 'POST' => 'save_form', 'DELETE' => 'delete_form' ),
			'/form-only'                            => array( 'POST' => 'save_form_only' ),
			'/orders/(?P<id>\d+)'                   => array( 'GET' => 'order' ),
			'/orders/(?P<id>\d+)/resend'            => array( 'POST' => 'resend' ),
			'/log'                                  => array( 'GET' => 'log' ),
		);
		foreach ( $routes as $path => $methods ) {
			$endpoints = array();
			foreach ( $methods as $method => $callback ) {
				$endpoints[] = array(
					'methods'             => $method,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'allowed' ),
				);
			}
			register_rest_route( self::NS, $path, $endpoints );
		}
	}

	public static function allowed() {
		return current_user_can( CTK_Admin::CAPABILITY );
	}

	/* ── Reading ── */

	/** Everything the admin pages show, in one answer. */
	public static function state() {
		$connector = CTK_Connector::active();

		$products = array();
		foreach ( CTK_Forms::products() as $id => $title ) {
			$prices = array();
			foreach ( CTK_Forms::product_variations( $id ) as $variation ) {
				$prices[] = array(
					'id'        => (int) $variation->id,
					'title'     => $variation->variation_title,
					'price'     => CTK_Forms::price_label( $variation ),
					'free'      => CTK_Forms::is_free_price( $variation ),
					'status'    => $variation->item_status,
					'form_only' => CTK_Form_Only::is_form_only( $variation->id ),
				);
			}
			$products[] = array( 'id' => $id, 'title' => $title, 'prices' => $prices );
		}

		$forms = array();
		foreach ( CTK_Forms::all() as $form ) {
			$forms[] = array_merge( $form, array(
				'shortcode' => '[cart_toolkit_form id="' . $form['id'] . '"]',
				'link'      => CTK_Forms::link( $form ),
				'page_url'  => CTK_Forms::page_url( $form ),
			) );
		}

		return rest_ensure_response( array(
			'version'    => CTK_VERSION,
			'settings'   => CTK_Settings::get(),
			'connector'  => $connector ? $connector->id() : null,
			'crm_fields' => $connector ? $connector->get_fields() : array(),
			'library'    => CTK_Fields::all(),
			'forms'      => $forms,
			'products'   => $products,
			'form_only'  => CTK_Form_Only::ids(),
		) );
	}

	/** What Cart Toolkit saved on an order. */
	public static function order( WP_REST_Request $request ) {
		$order = Order::find( (int) $request['id'] );
		if ( ! $order ) {
			return self::missing( 'order' );
		}
		return rest_ensure_response( array(
			'id'      => (int) $order->id,
			'status'  => $order->status,
			'form'    => $order->getMeta( CTK_META_FORM, null ),
			'answers' => $order->getMeta( CTK_META_ANSWERS, null ),
			'handoff' => $order->getMeta( CTK_META_HANDOFF, null ),
		) );
	}

	public static function log( WP_REST_Request $request ) {
		$source = sanitize_key( (string) $request['source'] );
		return rest_ensure_response( CTK_Log::all( isset( CTK_Log::SOURCES[ $source ] ) ? $source : '' ) );
	}

	/* ── Changing ── */

	public static function save_settings( WP_REST_Request $request ) {
		CTK_Settings::apply( array_merge( CTK_Settings::get(), (array) $request->get_json_params() ) );
		return rest_ensure_response( CTK_Settings::get() );
	}

	/** Body: { keys: [crm field keys] }. */
	public static function add_fields( WP_REST_Request $request ) {
		$connector = CTK_Connector::active();
		if ( ! $connector ) {
			return new WP_Error( 'ctk_no_crm', 'No CRM is connected.', array( 'status' => 409 ) );
		}
		$keys  = array_map( 'sanitize_text_field', (array) $request['keys'] );
		$added = CTK_Fields::add_from_connector( $keys, $connector );
		CTK_Log::add( 'admin', 'Fields added', true, $added . ' field(s) from ' . $connector->label() . ' (API)' );
		return rest_ensure_response( array( 'added' => $added, 'library' => CTK_Fields::all() ) );
	}

	/** Body: any of { label, type, options[] }. */
	public static function save_field( WP_REST_Request $request ) {
		$key = (string) $request['key'];
		$def = CTK_Fields::get( $key );
		if ( ! $def ) {
			return self::missing( 'field' );
		}
		$options = $request->has_param( 'options' ) ? (array) $request['options'] : (array) $def['options'];
		CTK_Fields::update(
			$key,
			sanitize_text_field( $request->has_param( 'label' ) ? $request['label'] : $def['label'] ),
			sanitize_key( $request->has_param( 'type' ) ? $request['type'] : $def['type'] ),
			array_map( 'sanitize_text_field', $options )
		);
		return rest_ensure_response( CTK_Fields::get( $key ) );
	}

	public static function remove_field( WP_REST_Request $request ) {
		CTK_Fields::remove( (string) $request['key'] );
		return rest_ensure_response( array( 'removed' => true ) );
	}

	/** Body: { name, product_id, kind: paid|free }. */
	public static function create_form( WP_REST_Request $request ) {
		$form = CTK_Forms::create( $request['name'], $request['product_id'], $request['kind'] );
		if ( is_wp_error( $form ) ) {
			$form->add_data( array( 'status' => 400 ) );
			return $form;
		}
		CTK_Log::add( 'admin', 'Form created', true, $form['name'] . ' (API)' );
		return rest_ensure_response( $form );
	}

	/** Body: any of the form's settings. Ones left out keep their saved value. */
	public static function save_form( WP_REST_Request $request ) {
		$form = CTK_Forms::get( (string) $request['id'] );
		if ( ! $form ) {
			return self::missing( 'form' );
		}
		list( $form, $notice ) = CTK_Forms::apply( $form, array_merge( $form, (array) $request->get_json_params() ) );
		CTK_Log::add( 'admin', 'Form saved', true, $form['name'] . ( $form['active'] ? ' (on)' : ' (off)' ) . ' (API)' );
		return rest_ensure_response( array( 'form' => $form, 'notice' => CTK_Admin::NOTICES[ $notice ][1] ) );
	}

	public static function delete_form( WP_REST_Request $request ) {
		$form = CTK_Forms::get( (string) $request['id'] );
		if ( ! $form ) {
			return self::missing( 'form' );
		}
		CTK_Forms::delete( $form['id'] );
		CTK_Log::add( 'admin', 'Form deleted', true, $form['name'] . ' (API)' );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/** Body: { ids: [every form-only price id] }. Replaces the list. */
	public static function save_form_only( WP_REST_Request $request ) {
		CTK_Form_Only::save( array_map( 'absint', (array) $request['ids'] ) );
		CTK_Log::add( 'admin', 'Form-only prices saved', true, count( CTK_Form_Only::ids() ) . ' price(s) form-only (API)' );
		return rest_ensure_response( array( 'form_only' => CTK_Form_Only::ids() ) );
	}

	public static function resend( WP_REST_Request $request ) {
		$order = Order::find( (int) $request['id'] );
		if ( ! $order ) {
			return self::missing( 'order' );
		}
		return rest_ensure_response( CTK_Handoff::send( $order, 'resend' ) );
	}

	private static function missing( $what ) {
		return new WP_Error( 'ctk_missing', 'No such ' . $what . '.', array( 'status' => 404 ) );
	}
}

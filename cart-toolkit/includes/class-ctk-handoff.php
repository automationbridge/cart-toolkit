<?php
/**
 * Hand-off: after an order is paid, write its form values to the buyer's CRM contact.
 *
 * One sender for both form kinds. It runs on FluentCart's "order paid done"
 * step, after FluentCart's own integrations (priority 11), so by then the WP
 * user exists and a CRM integration has had its chance to create the contact.
 * Every send is recorded on the order and in the log, and can be resent.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

use FluentCart\App\Models\Order;

class CTK_Handoff {

	const RETRY_HOOK = 'ctk_handoff_retry';

	public static function init() {
		add_action( 'fluent_cart/order_paid_done', array( __CLASS__, 'on_paid' ), 20 );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'retry' ) );
		add_filter( 'fluent_cart/widgets/single_order_page', array( __CLASS__, 'order_widget' ), 10, 2 );
		add_action( 'admin_post_ctk_resend', array( __CLASS__, 'handle_resend' ) );
	}

	/** @param array $args ['order', 'transaction', 'customer', 'subscription'?] */
	public static function on_paid( $args ) {
		$order = $args['order'] ?? null;
		if ( $order instanceof Order ) {
			self::send( $order, 'paid' );
		}
	}

	public static function retry( $order_id ) {
		$order = Order::find( (int) $order_id );
		if ( $order ) {
			self::send( $order, 'retry' );
		}
	}

	/**
	 * Every value this order sends: the buyer's answers, values from the link,
	 * the form's fixed values, and values taken from the order.
	 *
	 * @return array CRM field key => value. Empty values are left out, so a blank never overwrites the CRM.
	 */
	public static function values_for( $order ) {
		$values = array();
		foreach ( (array) $order->getMeta( CTK_META_ANSWERS, array() ) as $key => $answer ) {
			$values[ $key ] = $answer['value'] ?? '';
		}

		$meta = $order->getMeta( CTK_META_FORM, array() );
		$form = ! empty( $meta['id'] ) ? CTK_Forms::get( $meta['id'] ) : null;
		if ( $form ) {
			foreach ( $form['fields'] as $row ) {
				if ( 'set' === $row['mode'] ) {
					$values[ $row['key'] ] = (string) ( $row['value'] ?? '' );
				} elseif ( 'order' === $row['mode'] ) {
					$values[ $row['key'] ] = CTK_Fields::value_from_order( $row['source'] ?? '', $order );
				}
			}
		}

		return array_filter( $values, function ( $value ) {
			return is_array( $value ) ? (bool) $value : '' !== trim( (string) $value );
		} );
	}

	/**
	 * Send one order's values to the CRM and record the result.
	 *
	 * @param string $trigger 'paid', 'retry' or 'resend'.
	 * @return array The record saved on the order.
	 */
	public static function send( $order, $trigger ) {
		$meta = $order->getMeta( CTK_META_FORM, array() );
		if ( empty( $meta['id'] ) ) {
			return array();
		}

		$customer  = $order->customer ? $order->customer->fresh() : null;
		$values    = self::values_for( $order );
		$connector = CTK_Connector::active();

		if ( ! $connector ) {
			$result = new WP_Error( 'ctk_no_crm', 'No CRM is connected. Choose one on Cart Toolkit › Overview.' );
		} elseif ( ! $customer || ! $customer->email ) {
			$result = new WP_Error( 'ctk_no_customer', 'The order has no customer email.' );
		} elseif ( ! $values ) {
			$result = true;
		} else {
			$result = $connector->send( $customer->email, (int) $customer->user_id, $values, array(
				'first_name' => (string) $customer->first_name,
				'last_name'  => (string) $customer->last_name,
			) );
		}

		$ok     = true === $result;
		$record = array(
			'status'    => $ok ? 'sent' : 'failed',
			'message'   => $ok ? ( $values ? 'Sent ' . count( $values ) . ' value(s).' : 'Nothing to send.' ) : $result->get_error_message(),
			'time'      => current_time( 'mysql' ),
			'trigger'   => $trigger,
			'connector' => $connector ? $connector->label() : '',
			'sent'      => $values,
		);
		$order->updateMeta( CTK_META_HANDOFF, $record );

		CTK_Log::add( 'handoff', $ok ? 'Sent to CRM' : 'CRM send failed', $ok,
			( $customer ? $customer->email . ': ' : '' ) . $record['message'] . ( $connector ? ' (' . $connector->label() . ')' : '' ),
			(int) $order->id );

		// The contact may not exist yet when this runs; try once more a little later.
		if ( ! $ok && 'paid' === $trigger && is_wp_error( $result ) && 'ctk_no_contact' === $result->get_error_code() ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::RETRY_HOOK, array( (int) $order->id ) );
		}

		return $record;
	}

	public static function resend_url( $order_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ctk_resend&order_id=' . (int) $order_id ), 'ctk_resend_' . (int) $order_id );
	}

	public static function handle_resend() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ctk_resend_' . $order_id ) ) {
			wp_die( 'Unauthorized.' );
		}
		$order  = Order::find( $order_id );
		$record = $order ? self::send( $order, 'resend' ) : array();
		$back   = wp_get_referer() ? wp_get_referer() : CTK_Admin::page_url( 'logs' );
		wp_safe_redirect( add_query_arg( 'ctk_notice', ( 'sent' === ( $record['status'] ?? '' ) ) ? 'resent' : 'resend_failed', $back ) );
		exit;
	}

	/**
	 * "Form answers" card in the sidebar of FluentCart's order screen.
	 *
	 * @param array $widgets
	 * @param array $data ['order_uuid', 'order_id', 'order' => Order]
	 */
	public static function order_widget( $widgets, $data ) {
		$order = $data['order'] ?? null;
		if ( ! $order instanceof Order ) {
			return $widgets;
		}
		$meta = $order->getMeta( CTK_META_FORM, array() );
		if ( empty( $meta['id'] ) ) {
			return $widgets;
		}

		$answers = (array) $order->getMeta( CTK_META_ANSWERS, array() );
		$handoff = (array) $order->getMeta( CTK_META_HANDOFF, array() );

		$html  = '<dl style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:6px 12px;margin:0">';
		$html .= '<dt style="color:#6b7280">Form</dt><dd style="margin:0;font-weight:600">' . esc_html( $meta['name'] ?? $meta['id'] ) . '</dd>';
		foreach ( $answers as $answer ) {
			$html .= '<dt style="color:#6b7280">' . esc_html( $answer['label'] ?? '' ) . '</dt>';
			$html .= '<dd style="margin:0;font-weight:600;word-break:break-word">' . esc_html( CTK_Connector::flatten( $answer['value'] ?? '' ) ) . '</dd>';
		}
		$html .= '</dl>';

		if ( $handoff ) {
			$sent  = 'sent' === ( $handoff['status'] ?? '' );
			$html .= '<p style="margin:12px 0 0;color:' . ( $sent ? '#157f3c' : '#b42318' ) . '">'
				. esc_html( ( $sent ? 'Sent to ' : 'Not sent to ' ) . ( $handoff['connector'] ?: 'the CRM' ) . ', ' . ( $handoff['time'] ?? '' ) . '. ' . ( $handoff['message'] ?? '' ) )
				. '</p>';
		} else {
			$html .= '<p style="margin:12px 0 0;color:#6b7280">Not sent to the CRM yet. It goes once the order is paid.</p>';
		}
		if ( current_user_can( 'manage_options' ) ) {
			$html .= '<p style="margin:8px 0 0"><a href="' . esc_url( self::resend_url( $order->id ) ) . '">Send to the CRM again</a></p>';
		}

		$widgets[] = array(
			'title'   => 'Form answers',
			'type'    => 'html',
			'content' => $html,
		);
		return $widgets;
	}
}

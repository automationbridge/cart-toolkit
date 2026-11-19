<?php
/**
 * One log for everything Cart Toolkit does: an option holding the newest entries.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

class CTK_Log {

	const MAX = 300;

	/** Sources: key => label shown on the row and in the filter. */
	const SOURCES = array(
		'checkout' => 'Checkout',
		'formonly' => 'Form-only prices',
		'handoff'  => 'CRM hand-off',
		'admin'    => 'Settings',
	);

	/**
	 * Add an entry, newest first.
	 *
	 * @param string $source   A SOURCES key.
	 * @param string $action   What happened, in a few words.
	 * @param bool   $ok       Whether it worked.
	 * @param string $details  One line of detail.
	 * @param int    $order_id FluentCart order it concerns, if any.
	 */
	public static function add( $source, $action, $ok, $details = '', $order_id = 0 ) {
		$log = self::all();
		array_unshift( $log, array(
			'time'     => current_time( 'mysql' ),
			'source'   => (string) $source,
			'action'   => (string) $action,
			'ok'       => (bool) $ok,
			'details'  => (string) $details,
			'order_id' => (int) $order_id,
		) );
		update_option( CTK_OPT_LOG, array_slice( $log, 0, self::MAX ), false );
	}

	/** Every entry, newest first, optionally one source only. */
	public static function all( $source = '' ) {
		$log = get_option( CTK_OPT_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		if ( '' === $source ) {
			return $log;
		}
		return array_values( array_filter( $log, function ( $entry ) use ( $source ) {
			return ( $entry['source'] ?? '' ) === $source;
		} ) );
	}

	public static function clear() {
		delete_option( CTK_OPT_LOG );
	}
}

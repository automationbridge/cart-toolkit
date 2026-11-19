<?php
/**
 * Site-wide settings and the checks the Overview shows.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

class CTK_Settings {

	const DEFAULTS = array(
		'connector'     => 'auto',
		'link_existing' => 1,
	);

	public static function get( $key = null ) {
		$saved    = get_option( CTK_OPT_SETTINGS, array() );
		$settings = array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
		return null === $key ? $settings : ( $settings[ $key ] ?? null );
	}

	/** Merge into the saved row, never replace it. */
	public static function update( array $changes ) {
		$saved = get_option( CTK_OPT_SETTINGS, array() );
		update_option( CTK_OPT_SETTINGS, array_merge( is_array( $saved ) ? $saved : array(), $changes ) );
	}

	/** Save the Overview's settings from raw input. A missing switch means off. */
	public static function apply( array $input ) {
		$connector = sanitize_key( $input['connector'] ?? 'auto' );
		$allowed   = array_merge( array( 'auto', 'none' ), array_keys( CTK_Connector::all() ) );
		self::update( array(
			'connector'     => in_array( $connector, $allowed, true ) ? $connector : 'auto',
			'link_existing' => empty( $input['link_existing'] ) ? 0 : 1,
		) );
	}

	/** FluentCart is loaded and new enough for the hooks Cart Toolkit uses. */
	public static function fluentcart_ready() {
		return defined( 'FLUENTCART_VERSION' )
			&& version_compare( FLUENTCART_VERSION, '2.3.2', '>=' )
			&& class_exists( '\FluentCart\App\Models\Cart' );
	}
}

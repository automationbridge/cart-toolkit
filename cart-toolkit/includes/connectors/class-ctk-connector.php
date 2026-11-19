<?php
/**
 * The CRM on the other end of the field library and the hand-off.
 *
 * A connector does two things: list the CRM's contact fields, and write values
 * to one contact. Forms never talk to a CRM directly, so moving from FluentCRM
 * on the test site to Groundhogg through WP Fusion on the members site changes
 * nothing but this setting.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

abstract class CTK_Connector {

	/** Input types a library field can have. */
	const TYPES = array(
		'text'       => 'Text',
		'textarea'   => 'Paragraph',
		'number'     => 'Number',
		'email'      => 'Email',
		'tel'        => 'Phone',
		'url'        => 'Web address',
		'date'       => 'Date',
		'select'     => 'Dropdown',
		'radio'      => 'Choice (one)',
		'checkboxes' => 'Checkboxes (several)',
		'checkbox'   => 'Single checkbox',
	);

	/** Types whose answers come from a list of choices. */
	const CHOICE_TYPES = array( 'select', 'radio', 'checkboxes' );

	abstract public function id();

	abstract public function label();

	/** Whether the CRM plugin is active on this site. */
	abstract public function is_available();

	/**
	 * The CRM's contact fields.
	 *
	 * @return array[] Each: key, label, type (a TYPES key), options (string[]), group.
	 */
	abstract public function get_fields();

	/**
	 * Write values to the contact with this email.
	 *
	 * @param string $email   The buyer's email.
	 * @param int    $user_id Their WordPress user, or 0.
	 * @param array  $values  CRM field key => value (string, or string[] for checkboxes).
	 * @param array  $contact first_name, last_name, for CRMs that create the contact here.
	 * @return true|WP_Error
	 */
	abstract public function send( $email, $user_id, array $values, array $contact = array() );

	/* ── Registry ── */

	/** @return CTK_Connector[] id => connector */
	public static function all() {
		static $all = null;
		if ( null === $all ) {
			$all = array();
			foreach ( array( new CTK_Connector_FluentCRM(), new CTK_Connector_WPFusion() ) as $connector ) {
				$all[ $connector->id() ] = $connector;
			}
		}
		return $all;
	}

	/**
	 * The connector in use: the one chosen in settings, or on "auto" the first
	 * one available (WP Fusion first, since a site running it syncs through it).
	 *
	 * @return CTK_Connector|null
	 */
	public static function active() {
		$all    = self::all();
		$chosen = CTK_Settings::get( 'connector' );

		if ( 'none' === $chosen ) {
			return null;
		}
		if ( isset( $all[ $chosen ] ) ) {
			return $all[ $chosen ]->is_available() ? $all[ $chosen ] : null;
		}
		foreach ( array( 'wpfusion', 'fluentcrm' ) as $id ) {
			if ( $all[ $id ]->is_available() ) {
				return $all[ $id ];
			}
		}
		return null;
	}

	/** Turn a field value into the plain text a CRM field stores. */
	public static function flatten( $value ) {
		return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
	}
}

<?php
/**
 * Any CRM WP Fusion is connected to (Groundhogg on the members site). Lists the
 * CRM fields WP Fusion has loaded, and writes values to the contact by CRM field
 * key, so a field doesn't have to be mapped in WP Fusion first.
 *
 * WP Fusion's list has names only, so a field's type and choices are set in the
 * field library when it is added.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

class CTK_Connector_WPFusion extends CTK_Connector {

	public function id() {
		return 'wpfusion';
	}

	public function label() {
		$crm = $this->is_available() && isset( wp_fusion()->crm->name ) ? (string) wp_fusion()->crm->name : '';
		return $crm ? 'WP Fusion (' . $crm . ')' : 'WP Fusion';
	}

	public function is_available() {
		return function_exists( 'wp_fusion' ) && function_exists( 'wpf_get_option' ) && ! empty( wp_fusion()->crm );
	}

	public function get_fields() {
		if ( ! $this->is_available() ) {
			return array();
		}

		// Either flat (key => label) or grouped (group => key => label), depending on the CRM.
		$raw    = (array) wpf_get_option( 'crm_fields', array() );
		$fields = array();
		foreach ( $raw as $group_or_key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $label ) {
					$fields[] = $this->field( $key, $label, (string) $group_or_key );
				}
			} else {
				$fields[] = $this->field( $group_or_key, $value, 'CRM fields' );
			}
		}
		return $fields;
	}

	private function field( $key, $label, $group ) {
		return array(
			'key'     => (string) $key,
			'label'   => '' !== (string) $label ? (string) $label : (string) $key,
			'type'    => 'text',
			'options' => array(),
			'group'   => $group,
		);
	}

	public function send( $email, $user_id, array $values, array $contact = array() ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'ctk_no_crm', 'WP Fusion is not active.' );
		}

		$contact_id = '';
		if ( $user_id && method_exists( wp_fusion()->user, 'get_contact_id' ) ) {
			$contact_id = wp_fusion()->user->get_contact_id( $user_id );
		}
		if ( ! $contact_id && method_exists( wp_fusion()->crm, 'get_contact_id' ) ) {
			$contact_id = wp_fusion()->crm->get_contact_id( $email );
		}
		if ( is_wp_error( $contact_id ) ) {
			return $contact_id;
		}
		if ( ! $contact_id ) {
			return new WP_Error( 'ctk_no_contact', 'WP Fusion has no CRM contact for ' . $email . ' yet.' );
		}

		$flat   = array_map( array( 'CTK_Connector', 'flatten' ), $values );
		$result = wp_fusion()->crm->update_contact( $contact_id, $flat, false );

		return is_wp_error( $result ) ? $result : true;
	}
}

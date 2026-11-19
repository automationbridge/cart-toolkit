<?php
/**
 * FluentCRM on the same site. Lists its custom contact fields with their types
 * and choices, and writes values straight to the contact.
 *
 * @package Cart_Toolkit
 */

defined( 'ABSPATH' ) || exit;

class CTK_Connector_FluentCRM extends CTK_Connector {

	/** FluentCRM field type => library type. */
	const TYPE_MAP = array(
		'text'          => 'text',
		'textarea'      => 'textarea',
		'number'        => 'number',
		'select-one'    => 'select',
		'select-multi'  => 'checkboxes',
		'radio'         => 'radio',
		'checkbox'      => 'checkboxes',
		'date'          => 'date',
		'date_time'     => 'date',
	);

	public function id() {
		return 'fluentcrm';
	}

	public function label() {
		return 'FluentCRM';
	}

	public function is_available() {
		return defined( 'FLUENTCRM' ) && function_exists( 'FluentCrmApi' ) && function_exists( 'fluentcrm_get_custom_contact_fields' );
	}

	public function get_fields() {
		if ( ! $this->is_available() ) {
			return array();
		}

		$fields = array();
		foreach ( (array) fluentcrm_get_custom_contact_fields() as $field ) {
			$key = isset( $field['slug'] ) ? (string) $field['slug'] : '';
			if ( '' === $key ) {
				continue;
			}
			$type     = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$fields[] = array(
				'key'     => $key,
				'label'   => isset( $field['label'] ) ? (string) $field['label'] : $key,
				'type'    => self::TYPE_MAP[ $type ] ?? 'text',
				'options' => array_values( array_map( 'strval', (array) ( $field['options'] ?? array() ) ) ),
				'group'   => isset( $field['group'] ) && '' !== $field['group'] ? (string) $field['group'] : 'Custom fields',
			);
		}
		return $fields;
	}

	public function send( $email, $user_id, array $values, array $contact = array() ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'ctk_no_crm', 'FluentCRM is not active.' );
		}

		$data = array_filter( array(
			'email'      => $email,
			'first_name' => $contact['first_name'] ?? '',
			'last_name'  => $contact['last_name'] ?? '',
		) );
		if ( $user_id ) {
			$data['user_id'] = (int) $user_id;
		}
		$data['custom_values'] = $values;

		try {
			$result = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ctk_crm_error', $e->getMessage() );
		}

		return $result ? true : new WP_Error( 'ctk_crm_refused', 'FluentCRM did not save the contact.' );
	}
}

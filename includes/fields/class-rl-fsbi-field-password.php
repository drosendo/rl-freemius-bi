<?php
/**
 * Masked secret field renderer for the RL Options Framework.
 *
 * Renders API keys and tokens as password inputs so stored secrets are never
 * exposed on screen. Leaving the input blank keeps the stored value.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes/fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RL_FSBI_Field_Password implements RL_Field_Interface, RL_Field_Processing_Interface {

	/**
	 * Field type identifier.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'password';
	}

	/**
	 * Render the masked input. The stored value is never echoed into the markup.
	 *
	 * @param array $field   Field definition array.
	 * @param mixed $value   Current stored value.
	 * @param array $context Rendering context.
	 */
	public function render( array $field, $value, array $context = [] ): void {
		$input_id    = (string) ( $context['input_id'] ?? '' );
		$field_name  = (string) ( $context['field_name'] ?? '' );
		$has_value   = '' !== trim( (string) $value );
		$placeholder = $has_value
			? __( '•••••••••••• saved', 'rl-freemius-bi' )
			: (string) ( $field['placeholder'] ?? '' );

		printf(
			'<input type="password" id="%1$s" name="%2$s" value="" placeholder="%3$s" class="regular-text" autocomplete="new-password" spellcheck="false" />',
			esc_attr( $input_id ),
			esc_attr( $field_name ),
			esc_attr( $placeholder )
		);

		if ( $has_value ) {
			echo '<p class="description">' . esc_html__( 'A value is stored. Leave this field blank to keep it, or type a new value to replace it.', 'rl-freemius-bi' ) . '</p>';
		}
	}

	/**
	 * Sanitize the submitted secret.
	 *
	 * @param array $field   Field definition array.
	 * @param mixed $value   Submitted value.
	 * @param array $context Processing context.
	 * @return string
	 */
	public function sanitize( array $field, $value, array $context = [] ) {
		return isset( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Required rules are enforced by the framework before this method runs.
	 *
	 * @param array  $field   Field definition array.
	 * @param mixed  $value   Submitted value.
	 * @param string $error   Error message reference.
	 * @param array  $context Processing context.
	 * @return bool
	 */
	public function validate( array $field, $value, string &$error, array $context = [] ): bool {
		return true;
	}

	/**
	 * Keep the stored secret when the field is submitted empty.
	 *
	 * @param array $field   Field definition array.
	 * @param mixed $value   Submitted value.
	 * @param array $context Processing context.
	 * @return mixed
	 */
	public function prepare_for_validation( array $field, $value, array $context = [] ) {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}

		$option_name = (string) ( $field['preserve_option'] ?? '' );
		$field_id    = (string) ( $field['id'] ?? '' );

		if ( '' === $option_name || '' === $field_id ) {
			return $value;
		}

		$stored = get_option( $option_name, array() );
		if ( is_array( $stored ) && isset( $stored[ $field_id ] ) && '' !== (string) $stored[ $field_id ] ) {
			return (string) $stored[ $field_id ];
		}

		return $value;
	}
}

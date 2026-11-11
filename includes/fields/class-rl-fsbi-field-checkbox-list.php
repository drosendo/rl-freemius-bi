<?php
/**
 * Checkbox List Field Renderer for RL Options Framework
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/includes/fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RL_FSBI_Field_Checkbox_List implements RL_Field_Interface, RL_Field_Processing_Interface {

	/**
	 * Field type identifier.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'checkbox_list';
	}

	/**
	 * Render field HTML with one plugin per row.
	 *
	 * @param array $field   Field definition array.
	 * @param mixed $value   Current field value (array of selected IDs).
	 * @param array $context Rendering context.
	 */
	public function render( array $field, $value, array $context = [] ): void {
		$field_name = (string) ( $context['field_name'] ?? '' );
		$options    = $field['options'] ?? [];
		$values     = is_array( $value ) ? array_map( 'strval', $value ) : [];

		if ( empty( $options ) ) {
			echo '<p class="description rl-fsbi-no-plugins">' . esc_html__( 'No plugins discovered yet. Save valid API credentials in API Configuration to populate plugins.', 'rl-freemius-bi' ) . '</p>';
			return;
		}

		echo '<div class="rl-fsbi-checkbox-list-wrap">';
		// Hidden input ensures that if all checkboxes are unchecked, the form still submits an empty array
		echo '<input type="hidden" name="' . esc_attr( $field_name ) . '[]" value="" />';

		echo '<div class="rl-fsbi-checkbox-list-actions">';
		echo '<button type="button" class="button button-secondary button-small rl-fsbi-check-all">' . esc_html__( 'Select All', 'rl-freemius-bi' ) . '</button> ';
		echo '<button type="button" class="button button-secondary button-small rl-fsbi-uncheck-all">' . esc_html__( 'Deselect All', 'rl-freemius-bi' ) . '</button>';
		echo '</div>';

		echo '<div class="rl-fsbi-checkbox-list-items">';
		foreach ( $options as $option_id => $option_meta ) {
			$option_id  = (string) $option_id;
			$is_checked = in_array( $option_id, $values, true );

			$title = '';
			$slug  = '';
			if ( is_array( $option_meta ) ) {
				$title = (string) ( $option_meta['title'] ?? $option_meta['label'] ?? '' );
				$slug  = (string) ( $option_meta['slug'] ?? '' );
			} elseif ( is_object( $option_meta ) ) {
				$title = (string) ( $option_meta->title ?? $option_meta->label ?? '' );
				$slug  = (string) ( $option_meta->slug ?? '' );
			} else {
				$title = (string) $option_meta;
			}

			if ( '' === $title ) {
				$title = sprintf( esc_html__( 'Plugin #%s', 'rl-freemius-bi' ), $option_id );
			}

			echo '<label class="rl-fsbi-checkbox-item">';
			printf(
				'<input type="checkbox" name="%1$s[]" value="%2$s" %3$s />',
				esc_attr( $field_name ),
				esc_attr( $option_id ),
				checked( $is_checked, true, false )
			);

			// Title above the identifiers. Beside them the two badges took most of the
			// control width and every plugin name was truncated to a few letters.
			echo '<span class="rl-fsbi-checkbox-item-body">';
			echo '<span class="rl-fsbi-checkbox-item-title">' . esc_html( $title ) . '</span>';
			echo '<span class="rl-fsbi-checkbox-item-meta">';
			echo '<span class="rl-fsbi-checkbox-item-id">ID: ' . esc_html( $option_id ) . '</span>';
			if ( '' !== $slug ) {
				echo '<code class="rl-fsbi-checkbox-item-slug">' . esc_html( $slug ) . '</code>';
			}
			echo '</span>';
			echo '</span>';
			echo '</label>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Sanitize submitted values.
	 *
	 * @param array $field   Field definition.
	 * @param mixed $value   Raw value.
	 * @param array $context Runtime processing context.
	 * @return array Sanitized array of selected IDs.
	 */
	public function sanitize( array $field, $value, array $context = [] ) {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $value as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$sanitized[] = $item;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Validate field values.
	 *
	 * @param array  $field   Field definition.
	 * @param mixed  $value   Value to validate.
	 * @param string $error   Validation error output reference.
	 * @param array  $context Runtime processing context.
	 * @return bool
	 */
	public function validate( array $field, $value, string &$error, array $context = [] ): bool {
		return true;
	}

	/**
	 * Prepare value for validation.
	 *
	 * @param array $field   Field definition.
	 * @param mixed $value   Raw submitted value.
	 * @param array $context Runtime processing context.
	 * @return mixed
	 */
	public function prepare_for_validation( array $field, $value, array $context = [] ) {
		return is_array( $value ) ? $value : [];
	}
}

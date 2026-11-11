<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

class RL_FSBI_Field_Text implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	public function type(): string
	{
		return 'text';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');
		$allowed_types = ['text', 'email', 'url', 'tel', 'number', 'search', 'password'];
		$input_type    = strtolower((string) ($field['input_type'] ?? 'text'));
		if (!in_array($input_type, $allowed_types, true)) {
			$input_type = 'text';
		}

		printf(
			'<input type="%4$s" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
			esc_attr($input_id),
			esc_attr($field_name),
			esc_attr((string) $value),
			esc_attr($input_type)
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		return is_scalar($value) ? sanitize_text_field((string) $value) : '';
	}

	public function validate(array $field, $value, string &$error, array $context = []): bool
	{
		$field_label = $context['field_label'] ?? 'Field';
		$text_domain = $context['text_domain'] ?? 'default';

		if (null !== $value && !is_scalar($value)) {
			$error = sprintf(
				/* translators: %s: field label */
				__('%s has an invalid value.', RL_FSBI_Options_Framework::text_domain()),
				$field_label
			);
			return false;
		}

		if (isset($field['maxlength']) && mb_strlen((string) $value) > $field['maxlength']) {
			$error = sprintf(
				/* translators: 1: field label, 2: max length */
				__('%1$s must be no more than %2$s characters.', RL_FSBI_Options_Framework::text_domain()),
				$field_label,
				$field['maxlength']
			);
			return false;
		}

		if (isset($field['pattern']) && !preg_match($field['pattern'], $value)) {
			$error = sprintf(
				/* translators: %s: field label */
				__('%s has an invalid format.', RL_FSBI_Options_Framework::text_domain()),
				$field_label
			);
			return false;
		}

		return true;
	}

	public function prepare_for_validation(array $field, $value, array $context = [])
	{
		return $value;
	}
}

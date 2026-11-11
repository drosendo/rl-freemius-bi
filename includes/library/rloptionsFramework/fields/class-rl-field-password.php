<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Password input with a reveal toggle.
 */
class RL_Field_Password implements RL_Field_Interface, RL_Field_Processing_Interface
{
	public function type(): string
	{
		return 'password';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');

		printf(
			'<span class="rl-password"><input type="password" id="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="new-password" /><button type="button" class="button rl-password-toggle" aria-label="%4$s" aria-pressed="false">%5$s</button></span>',
			esc_attr($input_id),
			esc_attr($field_name),
			esc_attr((string) $value),
			esc_attr__('Show password', RL_Options_Framework::text_domain()),
			esc_html__('Show', RL_Options_Framework::text_domain())
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		return is_scalar($value) ? trim((string) $value) : '';
	}

	public function validate(array $field, $value, string &$error, array $context = []): bool
	{
		if (!empty($field['minlength']) && is_numeric($field['minlength']) && strlen((string) $value) < (int) $field['minlength']) {
			$error = sprintf(
				/* translators: 1: field label, 2: minimum length */
				__('%1$s must be at least %2$s characters.', RL_Options_Framework::text_domain()),
				$context['field_label'] ?? 'Field',
				(int) $field['minlength']
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

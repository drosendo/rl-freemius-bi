<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Switch field: a boolean toggle with Redux-style switch markup.
 */
class RL_Field_Switch implements RL_Field_Interface, RL_Field_Processing_Interface
{
	public function type(): string
	{
		return 'switch';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');
		$label      = (string) ($field['text'] ?? $field['label'] ?? '');

		printf(
			'<label class="rl-switch" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s><span class="rl-switch-track"><span class="rl-switch-handle"></span></span>%4$s</label>',
			esc_attr($input_id),
			esc_attr($field_name),
			checked(!empty($value), true, false),
			'' !== $label ? '<span class="rl-switch-label">' . esc_html($label) . '</span>' : ''
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		return !empty($value);
	}

	public function validate(array $field, $value, string &$error, array $context = []): bool
	{
		return true;
	}

	public function prepare_for_validation(array $field, $value, array $context = [])
	{
		return $value;
	}
}

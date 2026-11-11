<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Numeric input with increment/decrement buttons.
 */
class RL_FSBI_Field_Spinner extends RL_FSBI_Field_Number
{
	public function type(): string
	{
		return 'spinner';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');

		$min   = isset($field['min']) && is_numeric($field['min']) ? $field['min'] : 0;
		$max   = isset($field['max']) && is_numeric($field['max']) ? $field['max'] : '';
		$step  = isset($field['step']) && is_numeric($field['step']) ? $field['step'] : 1;
		$value = is_numeric($value) ? $value : ($field['default'] ?? $min);

		printf(
			'<div class="rl-spinner"><button type="button" class="button rl-spinner-down" aria-label="%1$s">&minus;</button><input type="number" class="small-text rl-spinner-input" id="%2$s" name="%3$s" value="%4$s" min="%5$s"%6$s step="%7$s" /><button type="button" class="button rl-spinner-up" aria-label="%8$s">&plus;</button><span class="rl-spinner-unit">%9$s</span></div>',
			esc_attr__('Decrease', RL_FSBI_Options_Framework::text_domain()),
			esc_attr($input_id),
			esc_attr($field_name),
			esc_attr((string) $value),
			esc_attr((string) $min),
			'' !== (string) $max ? ' max="' . esc_attr((string) $max) . '"' : '',
			esc_attr((string) $step),
			esc_attr__('Increase', RL_FSBI_Options_Framework::text_domain()),
			esc_html((string) ($field['unit'] ?? ''))
		);
	}
}

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Range slider with a synced numeric input.
 */
class RL_FSBI_Field_Slider implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	public function type(): string
	{
		return 'slider';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');

		$min   = isset($field['min']) && is_numeric($field['min']) ? (float) $field['min'] : 0;
		$max   = isset($field['max']) && is_numeric($field['max']) ? (float) $field['max'] : 100;
		$step  = isset($field['step']) && is_numeric($field['step']) ? (float) $field['step'] : 1;
		$value = is_numeric($value) ? (float) $value : ($field['default'] ?? $min);
		$unit  = (string) ($field['unit'] ?? '');

		printf(
			'<div class="rl-slider" data-unit="%1$s"><input type="range" class="rl-slider-range" id="%2$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s" aria-label="%7$s" /><input type="number" class="rl-slider-number small-text" name="%8$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s" /><span class="rl-slider-unit">%9$s</span></div>',
			esc_attr($unit),
			esc_attr($input_id),
			esc_attr((string) $value),
			esc_attr((string) $min),
			esc_attr((string) $max),
			esc_attr((string) $step),
			esc_attr((string) ($field['label'] ?? '')),
			esc_attr($field_name),
			esc_html($unit)
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		$min   = isset($field['min']) && is_numeric($field['min']) ? (float) $field['min'] : 0;
		$max   = isset($field['max']) && is_numeric($field['max']) ? (float) $field['max'] : 100;
		$step  = isset($field['step']) && is_numeric($field['step']) ? (float) $field['step'] : 1;
		$value = is_numeric($value) ? (float) $value : (float) ($field['default'] ?? $min);

		$value = max($min, min($max, $value));

		return ((float) $step !== (float) (int) $step) ? $value : (int) round($value);
	}

	public function validate(array $field, $value, string &$error, array $context = []): bool
	{
		// Mirrors the number field: blank optional values are not type errors.
		if ($value === '' || $value === null) {
			return true;
		}

		if (!is_numeric($value)) {
			$error = sprintf(
				/* translators: %s: field label */
				__('%s must be a valid number.', RL_FSBI_Options_Framework::text_domain()),
				$context['field_label'] ?? 'Field'
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

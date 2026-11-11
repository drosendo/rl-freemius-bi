<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Segmented button group (single or multi select).
 */
class RL_Field_Button_Set implements RL_Field_Interface, RL_Field_Processing_Interface
{
	public function type(): string
	{
		return 'button_set';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id    = (string) ($context['input_id'] ?? '');
		$field_name  = (string) ($context['field_name'] ?? '');
		$options     = is_array($field['options'] ?? null) ? $field['options'] : [];
		$multiple    = !empty($field['multiple']);
		$values      = $multiple ? (is_array($value) ? array_map('strval', $value) : []) : [ (string) $value ];
		$input_type  = $multiple ? 'checkbox' : 'radio';

		printf('<div class="rl-button-set" data-multiple="%s">', $multiple ? '1' : '0');

		$index = 0;
		foreach ($options as $option_value => $option_label) {
			$option_value = (string) $option_value;
			$is_active    = in_array($option_value, $values, true);
			$option_id    = $input_id . '_' . $index;

			printf(
				'<label class="rl-button-set-item%1$s" for="%2$s"><input type="%3$s" id="%2$s" name="%4$s" value="%5$s"%6$s /><span>%7$s</span></label>',
				$is_active ? ' is-active' : '',
				esc_attr($option_id),
				esc_attr($input_type),
				esc_attr($multiple ? $field_name . '[]' : $field_name),
				esc_attr($option_value),
				checked($is_active, true, false),
				esc_html((string) $option_label)
			);
			$index++;
		}

		echo '</div>';
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		$allowed = array_map('strval', array_keys(is_array($field['options'] ?? null) ? $field['options'] : []));

		if (!empty($field['multiple'])) {
			$values = is_array($value) ? array_map('strval', $value) : [];

			return array_values(array_intersect($allowed, $values));
		}

		$current = is_scalar($value) ? (string) $value : '';

		return in_array($current, $allowed, true) ? $current : '';
	}

	public function validate(array $field, $value, string &$error, array $context = []): bool
	{
		$allowed = array_map('strval', array_keys(is_array($field['options'] ?? null) ? $field['options'] : []));
		$values  = is_array($value) ? $value : [ $value ];

		foreach ($values as $item) {
			if ('' === (string) $item) {
				continue;
			}
			if (!in_array((string) $item, $allowed, true)) {
				$error = sprintf(
					/* translators: %s: field label */
					__('%s has an invalid option selected.', RL_Options_Framework::text_domain()),
					$context['field_label'] ?? 'Field'
				);
				return false;
			}
		}

		return true;
	}

	public function prepare_for_validation(array $field, $value, array $context = [])
	{
		return $value;
	}
}

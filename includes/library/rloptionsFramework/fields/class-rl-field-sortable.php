<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Drag-sortable multi-select: stores the enabled keys in display order.
 */
class RL_FSBI_Field_Sortable implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	public function type(): string
	{
		return 'sortable';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$field_name = (string) ($context['field_name'] ?? '');
		$options    = is_array($field['options'] ?? null) ? $field['options'] : [];
		$selected   = is_array($value) ? array_map('strval', $value) : [];
		$ordered    = [];

		foreach ($selected as $key) {
			if (array_key_exists($key, $options)) {
				$ordered[] = (string) $key;
			}
		}

		foreach (array_keys($options) as $key) {
			$key = (string) $key;
			if (!in_array($key, $ordered, true)) {
				$ordered[] = $key;
			}
		}

		printf('<div class="rl-sortable" data-name="%s"><ul class="rl-sortable-list">', esc_attr($field_name));

		foreach ($ordered as $key) {
			$is_enabled = in_array($key, $selected, true);

			printf(
				'<li class="rl-sortable-item%1$s" data-value="%2$s" draggable="true"><span class="rl-sortable-handle dashicons dashicons-menu" aria-hidden="true"></span><label><input type="checkbox" class="rl-sortable-toggle"%3$s /> %4$s</label><input type="hidden" class="rl-sortable-value" name="%5$s[]" value="%2$s"%6$s /></li>',
				$is_enabled ? ' is-enabled' : '',
				esc_attr($key),
				checked($is_enabled, true, false),
				esc_html((string) ($options[ $key ] ?? $key)),
				esc_attr($field_name),
				$is_enabled ? '' : ' disabled="disabled"'
			);
		}

		echo '</ul></div>';
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		$allowed = array_map('strval', array_keys(is_array($field['options'] ?? null) ? $field['options'] : []));
		$values  = is_array($value) ? $value : [];
		$clean   = [];

		foreach ($values as $item) {
			$item = (string) $item;
			if (in_array($item, $allowed, true) && !in_array($item, $clean, true)) {
				$clean[] = $item;
			}
		}

		return $clean;
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

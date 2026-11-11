<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Repeatable single-line text rows.
 */
class RL_FSBI_Field_Multi_Text implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	public function type(): string
	{
		return 'multi_text';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$field_name = (string) ($context['field_name'] ?? '');
		$rows       = is_array($value) ? array_values($value) : [];

		if (empty($rows)) {
			$rows = [''];
		}

		printf(
			'<div class="rl-multi-text" data-name="%1$s"><div class="rl-multi-text-rows">',
			esc_attr($field_name)
		);

		foreach ($rows as $row) {
			$this->render_row($field_name, is_scalar($row) ? (string) $row : '');
		}

		printf(
			'</div><button type="button" class="button rl-multi-text-add">%s</button></div>',
			esc_html__('Add row', RL_FSBI_Options_Framework::text_domain())
		);
	}

	/**
	 * Render one text row (also used by the JS template).
	 */
	public function render_row(string $field_name, string $value): void
	{
		printf(
			'<div class="rl-multi-text-row"><input type="text" class="regular-text" name="%1$s[]" value="%2$s" /><button type="button" class="button rl-multi-text-remove" aria-label="%3$s">&times;</button></div>',
			esc_attr($field_name),
			esc_attr($value),
			esc_attr__('Remove row', RL_FSBI_Options_Framework::text_domain())
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		$rows  = is_array($value) ? $value : [ $value ];
		$clean = [];

		foreach ($rows as $row) {
			if (!is_scalar($row)) {
				continue;
			}

			$row = sanitize_text_field((string) $row);
			if ('' !== $row) {
				$clean[] = $row;
			}
		}

		$limit = isset($field['max_rows']) && is_numeric($field['max_rows']) ? (int) $field['max_rows'] : 0;

		return $limit > 0 ? array_slice($clean, 0, $limit) : $clean;
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

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Code editor backed by WordPress' bundled CodeMirror.
 *
 * Values are stored verbatim (CSS/JS/HTML snippets); only invalid UTF-8 is stripped.
 */
class RL_Field_Code implements RL_Field_Interface, RL_Field_Processing_Interface
{
	public function type(): string
	{
		return 'code';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');
		$language   = sanitize_key((string) ($field['language'] ?? 'text/html'));

		printf(
			'<textarea id="%1$s" name="%2$s" class="large-text code rl-code-editor" rows="%3$d" data-language="%4$s" spellcheck="false">%5$s</textarea>',
			esc_attr($input_id),
			esc_attr($field_name),
			max(6, (int) ($field['rows'] ?? 12)),
			esc_attr($language),
			esc_textarea((string) $value)
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		if (!is_scalar($value)) {
			return '';
		}

		return wp_check_invalid_utf8((string) $value, true);
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

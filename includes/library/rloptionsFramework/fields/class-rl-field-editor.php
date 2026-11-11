<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Rich text editor backed by wp_editor().
 */
class RL_FSBI_Field_Editor implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	public function type(): string
	{
		return 'editor';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');

		if (!function_exists('wp_editor')) {
			printf(
				'<textarea id="%1$s" name="%2$s" class="large-text" rows="10">%3$s</textarea>',
				esc_attr($input_id),
				esc_attr($field_name),
				esc_textarea((string) $value)
			);
			return;
		}

		if (function_exists('wp_enqueue_editor')) {
			wp_enqueue_editor();
		}

		wp_editor(
			(string) $value,
			$input_id,
			[
				'textarea_name' => $field_name,
				'textarea_rows' => max(5, (int) ($field['rows'] ?? 10)),
				'media_buttons' => false,
				'quicktags'     => true,
				'teeny'         => !empty($field['teeny']),
			]
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		return is_scalar($value) ? wp_kses_post((string) $value) : '';
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

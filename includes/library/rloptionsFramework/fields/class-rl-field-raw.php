<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Raw output field: `callback` (callable) or `html` (trusted markup).
 */
class RL_FSBI_Field_Raw implements RL_FSBI_Field_Interface
{
	public function type(): string
	{
		return 'raw';
	}

	public function render(array $field, $value, array $context = []): void
	{
		echo '<div class="rl-raw-field">';

		if (!empty($field['callback']) && is_callable($field['callback'])) {
			call_user_func($field['callback'], $field, $value, $context);
		} elseif (!empty($field['html'])) {
			echo wp_kses_post((string) $field['html']);
		} elseif (!empty($field['content'])) {
			echo wp_kses_post((string) $field['content']);
		}

		echo '</div>';
	}
}

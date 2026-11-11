<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Visual divider with an optional heading and description.
 */
class RL_FSBI_Field_Divider implements RL_FSBI_Field_Interface
{
	public function type(): string
	{
		return 'divider';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$heading = (string) ($field['heading'] ?? $field['label'] ?? '');
		$desc    = (string) ($field['description'] ?? '');

		echo '<div class="rl-divider">';
		if ('' !== $heading) {
			printf('<h3 class="rl-divider-title">%s</h3>', esc_html($heading));
		}
		if ('' !== $desc) {
			printf('<p class="description">%s</p>', esc_html($desc));
		}
		echo '<hr /></div>';
	}
}

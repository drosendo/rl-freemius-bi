<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

class RL_Field_Info implements RL_Field_Interface
{
	public function type(): string
	{
		return 'info';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$content = '';

		if ( isset( $field['content'] ) ) {
			$content = (string) $field['content'];
		} elseif ( isset( $field['description'] ) ) {
			$content = (string) $field['description'];
		} elseif ( isset( $field['desc'] ) ) {
			$content = (string) $field['desc'];
		}

		printf( '<div class="rl-info-field">%s</div>', wp_kses_post( $content ) );
	}
}

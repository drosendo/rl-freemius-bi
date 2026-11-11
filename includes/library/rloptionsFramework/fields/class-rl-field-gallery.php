<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Multi-image gallery storing an ordered list of attachment IDs.
 */
class RL_Field_Gallery implements RL_Field_Interface, RL_Field_Processing_Interface
{
	public function type(): string
	{
		return 'gallery';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');
		$ids        = is_array($value) ? array_values(array_filter(array_map('absint', $value))) : [];

		printf(
			'<div class="rl-gallery" data-input-id="%1$s"><input type="hidden" id="%1$s" name="%2$s" value="%3$s" class="rl-gallery-input" /><div class="rl-gallery-items">',
			esc_attr($input_id),
			esc_attr($field_name),
			esc_attr(implode(',', $ids))
		);

		foreach ($ids as $attachment_id) {
			$thumb = wp_get_attachment_image($attachment_id, 'thumbnail');

			printf(
				'<span class="rl-gallery-item" data-id="%1$d">%2$s<button type="button" class="rl-gallery-remove" aria-label="%3$s">&times;</button></span>',
				(int) $attachment_id,
				$thumb ? $thumb : '<span class="rl-gallery-placeholder">#' . (int) $attachment_id . '</span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr__('Remove image', RL_Options_Framework::text_domain())
			);
		}

		printf(
			'</div><button type="button" class="button rl-gallery-add">%s</button></div>',
			esc_html__('Add images', RL_Options_Framework::text_domain())
		);
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		$values = is_array($value) ? $value : (is_scalar($value) ? explode(',', (string) $value) : []);
		$ids    = [];

		foreach ($values as $item) {
			$id = absint($item);
			if ($id > 0) {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
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

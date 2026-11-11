<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Single media picker (image, video, audio or any file) storing the attachment URL.
 */
class RL_FSBI_Field_Media implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	public function type(): string
	{
		return 'media';
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');
		$media_type = (string) ($field['media_type'] ?? 'image');
		$value      = (string) $value;

		echo '<div class="rl-media-field">';
		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text rl-media-url" />',
			esc_attr($input_id),
			esc_attr($field_name),
			esc_attr($value)
		);
		printf(
			' <button type="button" class="button rl-pick-media" data-input-id="%1$s" data-media-type="%2$s">%3$s</button>',
			esc_attr($input_id),
			esc_attr($media_type),
			esc_html__('Select media', RL_FSBI_Options_Framework::text_domain())
		);
		printf(
			' <button type="button" class="button rl-clear-media" data-input-id="%1$s"%2$s>%3$s</button>',
			esc_attr($input_id),
			'' === $value ? ' style="display:none;"' : '',
			esc_html__('Remove', RL_FSBI_Options_Framework::text_domain())
		);
		printf('<div class="rl-media-preview">%s</div>', '' === $value ? '' : $this->preview($value, $media_type));
		echo '</div>';
	}

	/**
	 * Build a preview element for a stored URL.
	 */
	private function preview(string $url, string $media_type): string
	{
		if ('video' === $media_type) {
			return sprintf('<video src="%s" style="max-width:240px;height:auto;" controls></video>', esc_url($url));
		}

		if ('audio' === $media_type) {
			return sprintf('<audio src="%s" controls></audio>', esc_url($url));
		}

		if ('file' === $media_type) {
			return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($url), esc_html(basename($url)));
		}

		return sprintf('<img src="%s" style="max-width:160px;height:auto;display:block;" />', esc_url($url));
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		return is_scalar($value) ? esc_url_raw(trim((string) $value)) : '';
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

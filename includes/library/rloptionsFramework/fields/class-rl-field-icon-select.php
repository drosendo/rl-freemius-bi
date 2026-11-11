<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Dashicons picker with a search filter.
 */
class RL_FSBI_Field_Icon_Select implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	public function type(): string
	{
		return 'icon_select';
	}

	/**
	 * Curated Dashicons set (filterable).
	 *
	 * @return string[]
	 */
	public static function icons(): array
	{
		$icons = [
			'admin-appearance', 'admin-comments', 'admin-customizer', 'admin-generic', 'admin-home',
			'admin-media', 'admin-network', 'admin-page', 'admin-plugins', 'admin-settings',
			'admin-site', 'admin-tools', 'admin-users', 'align-center', 'align-full-width',
			'align-left', 'align-none', 'align-pull-left', 'align-pull-right', 'align-right',
			'art', 'awards', 'backup', 'book', 'book-alt', 'building', 'businessman', 'calendar',
			'calendar-alt', 'camera', 'carrot', 'cart', 'category', 'chart-area', 'chart-bar',
			'chart-line', 'chart-pie', 'clipboard', 'clock', 'cloud', 'cloud-upload', 'dashboard',
			'database', 'desktop', 'dismiss', 'download', 'edit', 'editor-code', 'editor-expand',
			'editor-help', 'editor-paste-word', 'editor-removeformatting', 'editor-table',
			'editor-ul', 'editor-ol', 'email', 'email-alt', 'external', 'facebook', 'filter',
			'flag', 'format-aside', 'format-chat', 'format-gallery', 'format-image', 'format-quote',
			'format-status', 'format-video', 'forms', 'google', 'groups', 'hammer', 'heart',
			'hidden', 'html', 'id', 'id-alt', 'image-filter', 'images-alt', 'info', 'instagram',
			'laptop', 'layout', 'leftright', 'lightbulb', 'link', 'list-view', 'location',
			'location-alt', 'lock', 'marker', 'media-archive', 'media-audio', 'media-code',
			'media-default', 'media-document', 'media-interactive', 'media-spreadsheet',
			'media-text', 'media-video', 'megaphone', 'menu', 'menu-alt', 'microphone',
			'migrate', 'minus', 'money', 'move', 'no', 'open-folder', 'palmtree', 'paperclip',
			'phone', 'playlist-audio', 'playlist-video', 'plus', 'plus-alt', 'portfolio', 'post-status',
			'pressthis', 'products', 'randomize', 'redo', 'remove', 'rest-api', 'rss', 'search',
			'share', 'shield', 'shield-alt', 'slides', 'smartphone', 'smiley', 'sort', 'sos',
			'star-empty', 'star-filled', 'star-half', 'sticky', 'store', 'tag', 'testimonial',
			'thumbs-down', 'thumbs-up', 'tickets', 'translation', 'trash', 'twitter', 'undo',
			'universal-access', 'unlock', 'update', 'upload', 'vault', 'video-alt', 'visibility',
			'warning', 'welcome-widgets-menus', 'wordpress', 'yes',
		];

		return apply_filters('rl_options_framework_icons', $icons);
	}

	public function render(array $field, $value, array $context = []): void
	{
		$input_id   = (string) ($context['input_id'] ?? '');
		$field_name = (string) ($context['field_name'] ?? '');
		$current    = (string) $value;

		printf(
			'<div class="rl-icon-select"><input type="search" class="rl-icon-search" placeholder="%1$s" aria-label="%1$s" /><input type="hidden" id="%2$s" name="%3$s" value="%4$s" class="rl-icon-value" /><div class="rl-icon-grid" role="listbox">',
			esc_attr__('Search icons…', RL_FSBI_Options_Framework::text_domain()),
			esc_attr($input_id),
			esc_attr($field_name),
			esc_attr($current)
		);

		foreach (self::icons() as $icon) {
			$class = 'dashicons-' . $icon;
			printf(
				'<button type="button" class="rl-icon-option%1$s" role="option" aria-selected="%2$s" data-icon="%3$s" title="%3$s"><span class="dashicons %3$s"></span></button>',
				$class === $current ? ' is-selected' : '',
				$class === $current ? 'true' : 'false',
				esc_attr($class)
			);
		}

		echo '</div><button type="button" class="button rl-icon-clear">' . esc_html__('Clear', RL_FSBI_Options_Framework::text_domain()) . '</button></div>';
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		$icon = is_scalar($value) ? trim((string) $value) : '';

		return preg_match('/^dashicons-[a-z0-9-]+$/', $icon) ? $icon : '';
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

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Linear / radial gradient builder with a live CSS preview.
 */
class RL_FSBI_Field_Color_Gradient extends RL_FSBI_Field_Composite
{
	public function type(): string
	{
		return 'color_gradient';
	}

	protected function sub_fields(): array
	{
		return [
			'from'  => [ 'type' => 'color', 'label' => __('From', RL_FSBI_Options_Framework::text_domain()), 'default' => '#2271b1' ],
			'to'    => [ 'type' => 'color', 'label' => __('To', RL_FSBI_Options_Framework::text_domain()), 'default' => '#135e96' ],
			'angle' => [ 'type' => 'number', 'label' => __('Angle', RL_FSBI_Options_Framework::text_domain()), 'default' => 180, 'min' => 0, 'max' => 360, 'range' => true ],
			'type'  => [
				'type'    => 'select',
				'label'   => __('Type', RL_FSBI_Options_Framework::text_domain()),
				'default' => 'linear',
				'options' => [
					'linear' => __('Linear', RL_FSBI_Options_Framework::text_domain()),
					'radial' => __('Radial', RL_FSBI_Options_Framework::text_domain()),
				],
			],
		];
	}

	public function render(array $field, $value, array $context = []): void
	{
		parent::render($field, $value, $context);

		$value = is_array($value) ? $value : [];
		$from  = (string) ($value['from'] ?? $field['default']['from'] ?? '#2271b1');
		$to    = (string) ($value['to'] ?? $field['default']['to'] ?? '#135e96');
		$angle = (int) ($value['angle'] ?? 180);
		$type  = (string) ($value['type'] ?? 'linear');

		$css = 'radial' === $type
			? sprintf('radial-gradient(circle, %s 0%%, %s 100%%)', $from, $to)
			: sprintf('linear-gradient(%ddeg, %s 0%%, %s 100%%)', $angle, $from, $to);

		printf(
			'<div class="rl-gradient-preview" style="background:%s;height:40px;border-radius:4px;border:1px solid #dcdcde;margin-top:8px;"></div>',
			esc_attr($css)
		);
	}
}

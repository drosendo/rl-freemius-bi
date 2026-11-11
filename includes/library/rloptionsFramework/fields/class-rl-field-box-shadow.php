<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Box shadow field: offset-x, offset-y, blur, spread, color and inset.
 */
class RL_FSBI_Field_Box_Shadow extends RL_FSBI_Field_Composite
{
	public function type(): string
	{
		return 'box_shadow';
	}

	protected function sub_fields(): array
	{
		return [
			'x'      => [ 'type' => 'number', 'label' => __('X offset', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => -200, 'max' => 200 ],
			'y'      => [ 'type' => 'number', 'label' => __('Y offset', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => -200, 'max' => 200 ],
			'blur'   => [ 'type' => 'number', 'label' => __('Blur', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => 0, 'max' => 200 ],
			'spread' => [ 'type' => 'number', 'label' => __('Spread', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => -200, 'max' => 200 ],
			'color'  => [ 'type' => 'color', 'label' => __('Color', RL_FSBI_Options_Framework::text_domain()), 'default' => 'rgba(0,0,0,0.15)' ],
			'inset'  => [ 'type' => 'checkbox', 'label' => __('Inset', RL_FSBI_Options_Framework::text_domain()), 'default' => false ],
		];
	}
}

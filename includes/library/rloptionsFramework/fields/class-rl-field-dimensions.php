<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Dimensions field: top / right / bottom / left plus a unit.
 */
class RL_Field_Dimensions extends RL_Field_Composite
{
	public function type(): string
	{
		return 'dimensions';
	}

	protected function sub_fields(): array
	{
		return [
			'top'    => [ 'type' => 'number', 'label' => __('Top', RL_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'right'  => [ 'type' => 'number', 'label' => __('Right', RL_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'bottom' => [ 'type' => 'number', 'label' => __('Bottom', RL_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'left'   => [ 'type' => 'number', 'label' => __('Left', RL_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'unit'   => [ 'type' => 'select', 'label' => __('Unit', RL_Options_Framework::text_domain()), 'default' => 'px', 'options' => 'units' ],
		];
	}
}

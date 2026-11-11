<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Dimensions field: top / right / bottom / left plus a unit.
 */
class RL_FSBI_Field_Dimensions extends RL_FSBI_Field_Composite
{
	public function type(): string
	{
		return 'dimensions';
	}

	protected function sub_fields(): array
	{
		return [
			'top'    => [ 'type' => 'number', 'label' => __('Top', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'right'  => [ 'type' => 'number', 'label' => __('Right', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'bottom' => [ 'type' => 'number', 'label' => __('Bottom', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'left'   => [ 'type' => 'number', 'label' => __('Left', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => -1000, 'max' => 1000 ],
			'unit'   => [ 'type' => 'select', 'label' => __('Unit', RL_FSBI_Options_Framework::text_domain()), 'default' => 'px', 'options' => 'units' ],
		];
	}
}

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Border field: width + style + color.
 */
class RL_FSBI_Field_Border extends RL_FSBI_Field_Composite
{
	public function type(): string
	{
		return 'border';
	}

	protected function sub_fields(): array
	{
		return [
			'width' => [ 'type' => 'number', 'label' => __('Width', RL_FSBI_Options_Framework::text_domain()), 'default' => 0, 'min' => 0, 'max' => 100, 'range' => true ],
			'unit'  => [ 'type' => 'select', 'label' => __('Unit', RL_FSBI_Options_Framework::text_domain()), 'default' => 'px', 'options' => 'units' ],
			'style' => [
				'type'    => 'select',
				'label'   => __('Style', RL_FSBI_Options_Framework::text_domain()),
				'default' => 'solid',
				'options' => [
					'none'   => __('None', RL_FSBI_Options_Framework::text_domain()),
					'solid'  => __('Solid', RL_FSBI_Options_Framework::text_domain()),
					'dashed' => __('Dashed', RL_FSBI_Options_Framework::text_domain()),
					'dotted' => __('Dotted', RL_FSBI_Options_Framework::text_domain()),
					'double' => __('Double', RL_FSBI_Options_Framework::text_domain()),
				],
			],
			'color' => [ 'type' => 'color', 'label' => __('Color', RL_FSBI_Options_Framework::text_domain()), 'default' => '' ],
		];
	}
}

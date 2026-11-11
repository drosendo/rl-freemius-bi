<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Background field: color, image, repeat, position, size and attachment.
 */
class RL_FSBI_Field_Background extends RL_FSBI_Field_Composite
{
	public function type(): string
	{
		return 'background';
	}

	protected function sub_fields(): array
	{
		return [
			'color'      => [ 'type' => 'color', 'label' => __('Background color', RL_FSBI_Options_Framework::text_domain()), 'default' => '' ],
			'image'      => [ 'type' => 'media', 'label' => __('Background image', RL_FSBI_Options_Framework::text_domain()), 'default' => '' ],
			'repeat'     => [
				'type'    => 'select',
				'label'   => __('Repeat', RL_FSBI_Options_Framework::text_domain()),
				'default' => 'no-repeat',
				'options' => [
					'no-repeat' => __('No repeat', RL_FSBI_Options_Framework::text_domain()),
					'repeat'    => __('Repeat', RL_FSBI_Options_Framework::text_domain()),
					'repeat-x'  => __('Repeat horizontally', RL_FSBI_Options_Framework::text_domain()),
					'repeat-y'  => __('Repeat vertically', RL_FSBI_Options_Framework::text_domain()),
				],
			],
			'position'   => [
				'type'    => 'select',
				'label'   => __('Position', RL_FSBI_Options_Framework::text_domain()),
				'default' => 'center center',
				'options' => [
					'left top'      => __('Left top', RL_FSBI_Options_Framework::text_domain()),
					'left center'   => __('Left center', RL_FSBI_Options_Framework::text_domain()),
					'left bottom'   => __('Left bottom', RL_FSBI_Options_Framework::text_domain()),
					'center top'    => __('Center top', RL_FSBI_Options_Framework::text_domain()),
					'center center' => __('Center center', RL_FSBI_Options_Framework::text_domain()),
					'center bottom' => __('Center bottom', RL_FSBI_Options_Framework::text_domain()),
					'right top'     => __('Right top', RL_FSBI_Options_Framework::text_domain()),
					'right center'  => __('Right center', RL_FSBI_Options_Framework::text_domain()),
					'right bottom'  => __('Right bottom', RL_FSBI_Options_Framework::text_domain()),
				],
			],
			'size'       => [
				'type'    => 'select',
				'label'   => __('Size', RL_FSBI_Options_Framework::text_domain()),
				'default' => 'cover',
				'options' => [
					'auto'    => __('Auto', RL_FSBI_Options_Framework::text_domain()),
					'cover'   => __('Cover', RL_FSBI_Options_Framework::text_domain()),
					'contain' => __('Contain', RL_FSBI_Options_Framework::text_domain()),
				],
			],
			'attachment' => [
				'type'    => 'select',
				'label'   => __('Attachment', RL_FSBI_Options_Framework::text_domain()),
				'default' => 'scroll',
				'options' => [
					'scroll' => __('Scroll', RL_FSBI_Options_Framework::text_domain()),
					'fixed'  => __('Fixed', RL_FSBI_Options_Framework::text_domain()),
				],
			],
		];
	}
}

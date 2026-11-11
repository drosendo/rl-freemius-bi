<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Link colour field: link / hover / active / visited.
 */
class RL_FSBI_Field_Link_Color extends RL_FSBI_Field_Composite
{
	public function type(): string
	{
		return 'link_color';
	}

	protected function sub_fields(): array
	{
		return [
			'link'    => [ 'type' => 'color', 'label' => __('Link', RL_FSBI_Options_Framework::text_domain()), 'default' => '' ],
			'hover'   => [ 'type' => 'color', 'label' => __('Hover', RL_FSBI_Options_Framework::text_domain()), 'default' => '' ],
			'active'  => [ 'type' => 'color', 'label' => __('Active', RL_FSBI_Options_Framework::text_domain()), 'default' => '' ],
			'visited' => [ 'type' => 'color', 'label' => __('Visited', RL_FSBI_Options_Framework::text_domain()), 'default' => '' ],
		];
	}
}

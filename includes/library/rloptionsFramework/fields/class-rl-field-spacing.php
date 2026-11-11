<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Spacing field: same payload as dimensions, with its own type slug for clarity.
 */
class RL_Field_Spacing extends RL_Field_Dimensions
{
	public function type(): string
	{
		return 'spacing';
	}
}

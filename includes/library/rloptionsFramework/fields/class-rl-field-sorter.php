<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Sorter field: all options, each with an enable toggle and drag ordering.
 *
 * Stores the enabled keys in display order (same payload as `sortable`).
 */
class RL_Field_Sorter extends RL_Field_Sortable
{
	public function type(): string
	{
		return 'sorter';
	}
}

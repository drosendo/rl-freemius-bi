<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

class RL_FSBI_Field_Bootstrap
{
	/**
	 * Register default built-in field renderers.
	 */
	public static function register_defaults(RL_FSBI_Field_Registry $registry): void
	{
		$registry->register(new RL_FSBI_Field_Html());
		$registry->register(new RL_FSBI_Field_Text());
		$registry->register(new RL_FSBI_Field_Textarea());
		$registry->register(new RL_FSBI_Field_Select());
		$registry->register(new RL_FSBI_Field_Multiselect());
		$registry->register(new RL_FSBI_Field_Radio());
		$registry->register(new RL_FSBI_Field_Checkbox());
		$registry->register(new RL_FSBI_Field_Toggle());
		$registry->register(new RL_FSBI_Field_Image_Select());
		$registry->register(new RL_FSBI_Field_Color());
		$registry->register(new RL_FSBI_Field_Color_Palette());
		$registry->register(new RL_FSBI_Field_Number());
		$registry->register(new RL_FSBI_Field_Date());
		$registry->register(new RL_FSBI_Field_Datetime());
		$registry->register(new RL_FSBI_Field_Info());
		$registry->register(new RL_FSBI_Field_Image());
		$registry->register(new RL_FSBI_Field_Country());
		$registry->register(new RL_FSBI_Field_State());
		$registry->register(new RL_FSBI_Field_City());
		$registry->register(new RL_FSBI_Field_Country_State_City());
		$registry->register(new RL_FSBI_Field_Export());
		$registry->register(new RL_FSBI_Field_Import());
		$registry->register(new RL_FSBI_Field_Reset());
	}
}

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
		$registry->register(new RL_FSBI_Field_Reset_Section());

		// Redux-parity fields.
		$registry->register(new RL_FSBI_Field_Button_Set());
		$registry->register(new RL_FSBI_Field_Switch());
		$registry->register(new RL_FSBI_Field_Slider());
		$registry->register(new RL_FSBI_Field_Spinner());
		$registry->register(new RL_FSBI_Field_Password());
		$registry->register(new RL_FSBI_Field_Divider());
		$registry->register(new RL_FSBI_Field_Raw());
		$registry->register(new RL_FSBI_Field_Code());
		$registry->register(new RL_FSBI_Field_Editor());
		$registry->register(new RL_FSBI_Field_Icon_Select());
		$registry->register(new RL_FSBI_Field_Border());
		$registry->register(new RL_FSBI_Field_Box_Shadow());
		$registry->register(new RL_FSBI_Field_Dimensions());
		$registry->register(new RL_FSBI_Field_Spacing());
		$registry->register(new RL_FSBI_Field_Link_Color());
		$registry->register(new RL_FSBI_Field_Background());
		$registry->register(new RL_FSBI_Field_Typography());
		$registry->register(new RL_FSBI_Field_Color_Gradient());
		$registry->register(new RL_FSBI_Field_Media());
		$registry->register(new RL_FSBI_Field_Gallery());
		$registry->register(new RL_FSBI_Field_Multi_Text());
		$registry->register(new RL_FSBI_Field_Sortable());
		$registry->register(new RL_FSBI_Field_Sorter());

		// Aliases kept for Redux/legacy naming.
		$registry->register_alias('select_image', 'image_select');
		$registry->register_alias('color_rgba', 'color');
		$registry->register_alias('palette_color', 'color_palette');
		$registry->register_alias('content', 'html');
		$registry->register_alias('ace_editor', 'code');
		$registry->register_alias('select2', 'select');
	}
}

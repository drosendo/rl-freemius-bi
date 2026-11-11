<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Typography field: family, weight, size, line-height, letter-spacing, transform, style, color.
 */
class RL_Field_Typography extends RL_Field_Composite
{
	public function type(): string
	{
		return 'typography';
	}

	/**
	 * Font family choices (filterable so hosts can add their own stack).
	 *
	 * @return array<string,string>
	 */
	public static function font_families(): array
	{
		$families = [
			''                                     => __('Inherit', RL_Options_Framework::text_domain()),
			'system-ui, -apple-system, sans-serif' => __('System UI', RL_Options_Framework::text_domain()),
			'Arial, Helvetica, sans-serif'         => 'Arial',
			'Helvetica Neue, Helvetica, sans-serif' => 'Helvetica Neue',
			'Georgia, serif'                       => 'Georgia',
			'Times New Roman, Times, serif'        => 'Times New Roman',
			'Courier New, monospace'               => 'Courier New',
			'Verdana, Geneva, sans-serif'          => 'Verdana',
			'Tahoma, Geneva, sans-serif'           => 'Tahoma',
			'Trebuchet MS, sans-serif'             => 'Trebuchet MS',
			'Inter, sans-serif'                    => 'Inter',
			'Roboto, sans-serif'                   => 'Roboto',
			'Open Sans, sans-serif'                => 'Open Sans',
			'Lato, sans-serif'                     => 'Lato',
			'Montserrat, sans-serif'               => 'Montserrat',
			'Poppins, sans-serif'                  => 'Poppins',
			'Playfair Display, serif'              => 'Playfair Display',
		];

		return apply_filters('rl_options_framework_font_families', $families);
	}

	protected function sub_fields(): array
	{
		return [
			'font_family'      => [
				'type'    => 'select',
				'label'   => __('Font family', RL_Options_Framework::text_domain()),
				'default' => '',
				'options' => [ __CLASS__, 'font_families' ],
			],
			'font_weight'      => [
				'type'    => 'select',
				'label'   => __('Weight', RL_Options_Framework::text_domain()),
				'default' => '400',
				'options' => [
					'100' => '100', '200' => '200', '300' => '300', '400' => '400',
					'500' => '500', '600' => '600', '700' => '700', '800' => '800', '900' => '900',
				],
			],
			'font_size'        => [ 'type' => 'number', 'label' => __('Size', RL_Options_Framework::text_domain()), 'default' => 16, 'min' => 0, 'max' => 200, 'step' => '0.1', 'range' => true ],
			'font_size_unit'   => [ 'type' => 'select', 'label' => __('Size unit', RL_Options_Framework::text_domain()), 'default' => 'px', 'options' => 'units' ],
			'line_height'      => [ 'type' => 'number', 'label' => __('Line height', RL_Options_Framework::text_domain()), 'default' => 1.5, 'min' => 0, 'max' => 10, 'step' => '0.1', 'range' => true ],
			'line_height_unit' => [
				'type'    => 'select',
				'label'   => __('Line-height unit', RL_Options_Framework::text_domain()),
				'default' => '',
				'options' => [ '' => __('Unitless', RL_Options_Framework::text_domain()) ] + $this->units(),
			],
			'letter_spacing'   => [ 'type' => 'number', 'label' => __('Letter spacing', RL_Options_Framework::text_domain()), 'default' => 0, 'min' => -20, 'max' => 50, 'step' => '0.1', 'range' => true ],
			'letter_spacing_unit' => [ 'type' => 'select', 'label' => __('Spacing unit', RL_Options_Framework::text_domain()), 'default' => 'px', 'options' => 'units' ],
			'text_transform'   => [
				'type'    => 'select',
				'label'   => __('Transform', RL_Options_Framework::text_domain()),
				'default' => 'none',
				'options' => [
					'none'      => __('None', RL_Options_Framework::text_domain()),
					'uppercase' => __('Uppercase', RL_Options_Framework::text_domain()),
					'lowercase' => __('Lowercase', RL_Options_Framework::text_domain()),
					'capitalize' => __('Capitalize', RL_Options_Framework::text_domain()),
				],
			],
			'font_style'       => [
				'type'    => 'select',
				'label'   => __('Style', RL_Options_Framework::text_domain()),
				'default' => 'normal',
				'options' => [
					'normal' => __('Normal', RL_Options_Framework::text_domain()),
					'italic' => __('Italic', RL_Options_Framework::text_domain()),
				],
			],
			'color'            => [ 'type' => 'color', 'label' => __('Color', RL_Options_Framework::text_domain()), 'default' => '' ],
		];
	}
}

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

/**
 * Base class for composite (array-valued) fields.
 *
 * Subclasses only declare their sub-fields; rendering and sanitization are shared.
 * Values are stored as an array keyed by sub-field name, unknown keys are dropped.
 */
abstract class RL_FSBI_Field_Composite implements RL_FSBI_Field_Interface, RL_FSBI_Field_Processing_Interface
{
	/**
	 * Sub-field definitions: key => [type, label, default, options, min, max, step, attrs].
	 *
	 * @return array<string,array>
	 */
	abstract protected function sub_fields(): array;

	/**
	 * CSS units offered by `unit` selects.
	 *
	 * @return array<string,string>
	 */
	protected function units(): array
	{
		return [
			'px' => 'px',
			'%' => '%',
			'em' => 'em',
			'rem' => 'rem',
			'vw' => 'vw',
			'vh' => 'vh',
		];
	}

	public function render(array $field, $value, array $context = []): void
	{
		$value     = is_array($value) ? $value : [];
		$base_name = (string) ($context['field_name'] ?? '');
		$input_id  = (string) ($context['input_id'] ?? '');
		$subs      = $this->sub_fields();
		$unit      = $this->current_unit($subs, $value);
		$linkable  = $this->has_sides($subs);

		echo '<div class="rl-composite rl-composite-' . esc_attr($this->type()) . '" data-rl-composite="' . esc_attr($this->type()) . '"' . ($linkable ? ' data-rl-sides="1"' : '') . '>';

		foreach ($subs as $key => $spec) {
			$current = array_key_exists($key, $value) ? $value[$key] : ($spec['default'] ?? '');
			$this->render_sub($field, (string) $key, $spec, $current, $base_name, $input_id, $unit, $linkable);
		}

		echo '</div>';
	}

	/**
	 * Does this composite expose the four box sides (top / right / bottom / left)?
	 *
	 * @param array<string,array> $subs Sub-field definitions.
	 */
	protected function has_sides(array $subs): bool
	{
		foreach (['top', 'right', 'bottom', 'left'] as $side) {
			if (!isset($subs[$side]) || 'number' !== (string) ($subs[$side]['type'] ?? '')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Find the shared unit sub-field (if the composite has one) and its current value.
	 *
	 * @param array<string,array> $subs  Sub-field definitions.
	 * @param array               $value Stored value.
	 * @return array{key:string,label:string}|null
	 */
	protected function current_unit(array $subs, array $value): ?array
	{
		foreach (['unit', 'units'] as $candidate) {
			if (!isset($subs[$candidate]) || 'select' !== (string) ($subs[$candidate]['type'] ?? '')) {
				continue;
			}

			$current = array_key_exists($candidate, $value) ? $value[$candidate] : ($subs[$candidate]['default'] ?? '');
			$options = $this->resolve_options($subs[$candidate], []);

			return [
				'key'   => $candidate,
				'label' => (string) ($options[(string) $current] ?? $current),
			];
		}

		return null;
	}

	/**
	 * Render one sub-control in the block-editor visual language:
	 * label on the left, control cluster on the right (optional range + unit box).
	 *
	 * @param array      $field     Parent field definition.
	 * @param string     $key       Sub-field key.
	 * @param array      $spec      Sub-field spec.
	 * @param mixed      $current   Current value.
	 * @param string     $base_name Parent input name.
	 * @param string     $input_id  Parent input id.
	 * @param array|null $unit      Shared unit sub-field (`key` + current `label`).
	 * @param bool       $linkable  Whether the composite exposes the four box sides.
	 */
	protected function render_sub(array $field, string $key, array $spec, $current, string $base_name, string $input_id, ?array $unit = null, bool $linkable = false): void
	{
		$type    = (string) ($spec['type'] ?? 'text');
		$name    = $base_name . '[' . $key . ']';
		$id      = $input_id . '_' . $key;
		$label   = (string) ($spec['label'] ?? ucfirst(str_replace('_', ' ', $key)));
		$attrs   = (string) ($spec['attrs'] ?? '');
		$suffix  = '';
		$range   = '';

		if ('number' === $type && !empty($spec['range']) && isset($spec['min'], $spec['max'])) {
			$range = sprintf(
				'<input type="range" class="rl-gb-range" min="%1$s" max="%2$s" step="%3$s" value="%4$s" data-rl-range-for="%5$s" aria-label="%6$s" />',
				esc_attr((string) $spec['min']),
				esc_attr((string) $spec['max']),
				esc_attr((string) ($spec['step'] ?? '1')),
				esc_attr((string) $current),
				esc_attr($id),
				esc_attr($label)
			);
		}

		echo '<div class="rl-gb-row rl-gb-row-' . esc_attr($key) . ' rl-gb-row-' . esc_attr($type) . '">';
		printf('<label class="rl-gb-label" for="%s">%s</label>', esc_attr($id), esc_html($label));
		echo '<div class="rl-gb-controls">';
		echo $range; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped above.

		switch ($type) {
			case 'color':
				printf(
					'<input type="text" class="rl-color-field rl-gb-color" id="%1$s" name="%2$s" value="%3$s"%4$s />',
					esc_attr($id),
					esc_attr($name),
					esc_attr((string) $current),
					$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				break;

			case 'number':
				if (null !== $unit) {
					$suffix = '<span class="rl-gb-suffix" data-rl-suffix>' . esc_html($unit['label']) . '</span>';
				}

				$number  = '<input type="number" class="rl-gb-input" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $current) . '" step="' . esc_attr((string) ($spec['step'] ?? '1')) . '"';
				$number .= $linkable && in_array($key, ['top', 'right', 'bottom', 'left'], true) ? ' data-rl-side="' . esc_attr($key) . '"' : '';
				$number .= isset($spec['min']) ? ' min="' . esc_attr((string) $spec['min']) . '"' : '';
				$number .= isset($spec['max']) ? ' max="' . esc_attr((string) $spec['max']) . '"' : '';
				$number .= $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$number .= $suffix;

				printf(
					'<span class="rl-gb-number%1$s">%2$s</span>',
					'' !== $suffix ? ' rl-gb-number-suffixed' : '',
					$number // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				break;

			case 'select':
				$options   = $this->resolve_options($spec, $field);
				$select_cl = null !== $unit && $unit['key'] === $key ? 'rl-gb-select rl-gb-unit-select' : 'rl-gb-select';
				printf('<select class="%s" id="%s" name="%s"%s>', esc_attr($select_cl), esc_attr($id), esc_attr($name), $attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ($options as $option_value => $option_label) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr((string) $option_value),
						selected((string) $current, (string) $option_value, false),
						esc_html((string) $option_label)
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
				printf(
					'<label class="rl-gb-check"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> <span>%4$s</span></label>',
					esc_attr($id),
					esc_attr($name),
					checked(!empty($current), true, false),
					esc_html((string) ($spec['text'] ?? ''))
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="3" class="rl-gb-input rl-gb-textarea"%3$s>%4$s</textarea>',
					esc_attr($id),
					esc_attr($name),
					$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_textarea((string) $current)
				);
				break;

			case 'media':
				printf(
					'<div class="rl-composite-media"><input type="text" class="rl-gb-input rl-image-input" id="%1$s" name="%2$s" value="%3$s" /> <button type="button" class="button rl-upload-image-button" data-input-id="%1$s">%4$s</button> <button type="button" class="button rl-remove-image-button" data-input-id="%1$s"%5$s>%6$s</button><div class="rl-image-preview">%7$s</div></div>',
					esc_attr($id),
					esc_attr($name),
					esc_attr((string) $current),
					esc_html__('Choose Image', RL_FSBI_Options_Framework::text_domain()),
					'' === (string) $current ? ' style="display:none;"' : '',
					esc_html__('Remove', RL_FSBI_Options_Framework::text_domain()),
					'' === (string) $current ? '' : sprintf('<img src="%s" style="max-width:120px;height:auto;display:block;" />', esc_url((string) $current))
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" class="rl-gb-input" id="%1$s" name="%2$s" value="%3$s"%4$s />',
					esc_attr($id),
					esc_attr($name),
					esc_attr((string) $current),
					$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
		}

		if ($linkable && in_array($key, ['top', 'right', 'bottom', 'left'], true)) {
			printf(
				'<button type="button" class="rl-gb-link" data-rl-link aria-pressed="false" title="%1$s"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span class="screen-reader-text">%1$s</span></button>',
				esc_attr__('Link all sides', RL_FSBI_Options_Framework::text_domain())
			);
		}

		echo '</div>'; // .rl-gb-controls

		if (!empty($spec['description'])) {
			printf('<span class="rl-gb-desc">%s</span>', esc_html((string) $spec['description']));
		}

		echo '</div>';
	}

	/**
	 * Resolve a sub-field option list (static array, `units`, or callable).
	 *
	 * @param array $spec  Sub-field spec.
	 * @param array $field Parent field definition.
	 * @return array<string|int,string>
	 */
	protected function resolve_options(array $spec, array $field): array
	{
		$options = $spec['options'] ?? [];

		if ('units' === $options) {
			return $this->units();
		}

		if (is_callable($options)) {
			$options = call_user_func($options, $field);
		}

		return is_array($options) ? $options : [];
	}

	public function sanitize(array $field, $value, array $context = [])
	{
		$value = is_array($value) ? $value : [];
		$out   = [];

		foreach ($this->sub_fields() as $key => $spec) {
			$raw          = array_key_exists($key, $value) ? $value[$key] : ($spec['default'] ?? '');
			$out[ $key ]  = $this->sanitize_sub($spec, $raw, $field);
		}

		return $out;
	}

	/**
	 * Sanitize a single sub-value according to its declared type.
	 *
	 * @param array $spec  Sub-field spec.
	 * @param mixed $raw   Raw value.
	 * @param array $field Parent field definition.
	 * @return mixed
	 */
	protected function sanitize_sub(array $spec, $raw, array $field)
	{
		$type    = (string) ($spec['type'] ?? 'text');
		$default = $spec['default'] ?? '';

		switch ($type) {
			case 'color':
				return $this->sanitize_color($raw, (string) $default);

			case 'number':
				if (!is_scalar($raw) || !is_numeric($raw)) {
					$raw = $default;
				}
				$number = (float) $raw;
				if (isset($spec['min']) && is_numeric($spec['min']) && $number < (float) $spec['min']) {
					$number = (float) $spec['min'];
				}
				if (isset($spec['max']) && is_numeric($spec['max']) && $number > (float) $spec['max']) {
					$number = (float) $spec['max'];
				}
				$is_float = isset($spec['step']) && (float) $spec['step'] !== (float) (int) $spec['step'];

				return $is_float ? $number : (int) round($number);

			case 'select':
				$allowed = array_map('strval', array_keys($this->resolve_options($spec, $field)));
				$current = is_scalar($raw) ? (string) $raw : '';

				if (in_array($current, $allowed, true)) {
					return $current;
				}

				$allowed_default = (string) $default;

				return in_array($allowed_default, $allowed, true) ? $allowed_default : (string) ($allowed[0] ?? '');

			case 'checkbox':
				return !empty($raw);

			case 'media':
			case 'url':
				return is_scalar($raw) ? esc_url_raw(trim((string) $raw)) : '';

			case 'textarea':
				return is_scalar($raw) ? sanitize_textarea_field((string) $raw) : '';

			case 'text':
			default:
				return is_scalar($raw) ? sanitize_text_field((string) $raw) : '';
		}
	}

	/**
	 * Accept hex (3/6/8), rgb()/rgba(), `transparent` or an empty value.
	 *
	 * @param mixed  $raw     Raw color.
	 * @param string $default Fallback color.
	 * @return string
	 */
	protected function sanitize_color($raw, string $default = ''): string
	{
		if (!is_scalar($raw)) {
			return $default;
		}

		$color = trim((string) $raw);

		if ('' === $color || 'transparent' === strtolower($color)) {
			return '' === $color ? '' : 'transparent';
		}

		if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $color)) {
			return $color;
		}

		if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/i', $color)) {
			return $color;
		}

		return $default;
	}

	public function validate(array $field, $value, string &$error, array $context = []): bool
	{
		return true;
	}

	public function prepare_for_validation(array $field, $value, array $context = [])
	{
		return is_array($value) ? $value : [];
	}
}

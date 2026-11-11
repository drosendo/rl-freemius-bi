<?php
/**
 * RL Options Builder Service.
 *
 * Opt-in visual builder: enabled with the `builder` config flag. It renders a
 * drag-and-drop canvas where tabs, sections and fields can be assembled, with a
 * per-field inspector for visibility conditions and required rules, and exports
 * the resulting schema as ready-to-paste PHP.
 *
 * @package RL_Options_Framework
 * @since 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Visual schema builder for the RL Options Framework.
 */
class RL_Options_Builder {

	/**
	 * Framework instance.
	 *
	 * @var RL_Options_Framework
	 */
	private $framework;

	/**
	 * Constructor.
	 *
	 * @param RL_Options_Framework $framework Framework instance.
	 */
	public function __construct( RL_Options_Framework $framework ) {
		$this->framework = $framework;
	}

	/**
	 * Whether the builder is enabled for this instance.
	 */
	public function is_enabled(): bool {
		return ! empty( $this->framework->get_config( 'builder' ) );
	}

	/**
	 * Builder page slug.
	 */
	public function page_slug(): string {
		$configured = (string) $this->framework->get_config( 'builder_page_slug' );

		return '' !== $configured ? sanitize_key( $configured ) : sanitize_key( $this->framework->get_config( 'page_slug' ) . '-builder' );
	}

	/**
	 * Draft option key.
	 */
	public function draft_key(): string {
		return $this->framework->get_config( 'option_name' ) . '_builder_draft';
	}

	/**
	 * AJAX action used to persist the draft.
	 */
	public function save_action(): string {
		return 'rl_of_' . sanitize_key( (string) $this->framework->get_config( 'option_name' ) ) . '_builder_save';
	}

	/**
	 * AJAX action used to render a single field preview.
	 */
	public function field_action(): string {
		return 'rl_of_' . sanitize_key( (string) $this->framework->get_config( 'option_name' ) ) . '_builder_field';
	}

	/**
	 * Validate the AJAX request (nonce + capability).
	 */
	private function guard_request(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $this->save_action() . '_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Refresh the page and try again.', RL_Options_Framework::text_domain() ) ], 403 );
		}

		if ( ! current_user_can( (string) $this->framework->get_config( 'capability' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to use the builder.', RL_Options_Framework::text_domain() ) ], 403 );
		}
	}

	/**
	 * Render field previews with the real renderer so the canvas mirrors the final page.
	 *
	 * Accepts a single `field` or a batch `fields` payload; the batch form keeps the
	 * builder to one request when it hydrates a whole schema.
	 */
	public function handle_render_field(): void {
		$this->guard_request();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and sanitized per field below.
		$single = isset( $_POST['field'] ) ? wp_unslash( $_POST['field'] ) : '';
		$batch  = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$options = get_option( (string) $this->framework->get_config( 'option_name' ), [] );

		if ( ! is_array( $options ) ) {
			$options = [];
		}

		$renderer = new RL_Options_Render_Service( $this->framework );

		if ( is_string( $batch ) && '' !== $batch ) {
			$fields = json_decode( $batch, true );
			$fields = is_array( $fields ) ? array_slice( $fields, 0, 200 ) : [];

			if ( ! $fields ) {
				wp_send_json_error( [ 'message' => __( 'Invalid field payload.', RL_Options_Framework::text_domain() ) ], 400 );
			}

			$html = [];

			foreach ( $fields as $field ) {
				$preview = $this->render_model_field( $field, $renderer, $options );

				if ( null !== $preview ) {
					$html[ $preview[0] ] = $preview[1];
				}
			}

			wp_send_json_success( [ 'fields' => $html ] );
		}

		$field = is_string( $single ) ? json_decode( $single, true ) : null;
		$html  = $this->render_model_field( $field, $renderer, $options );

		if ( null === $html ) {
			wp_send_json_error( [ 'message' => __( 'Invalid field payload.', RL_Options_Framework::text_domain() ) ], 400 );
		}

		wp_send_json_success( [ 'html' => $html[1] ] );
	}

	/**
	 * Sanitize one builder field and render it.
	 *
	 * @param mixed                    $field    Raw field payload.
	 * @param RL_Options_Render_Service $renderer Renderer instance.
	 * @param array                    $options  Stored option values.
	 * @return array{0:string,1:string}|null Field id + markup, or null when the payload is unusable.
	 */
	private function render_model_field( $field, RL_Options_Render_Service $renderer, array $options ): ?array {
		if ( ! is_array( $field ) || empty( $field['id'] ) || empty( $field['type'] ) ) {
			return null;
		}

		$definition = $this->model_field_to_schema( $this->sanitize_field( $field ) );

		// Preview the configured default unless the option already has a value.
		if ( ! array_key_exists( $definition['id'], $options ) && isset( $definition['default'] ) ) {
			$options[ $definition['id'] ] = $definition['default'];
		}

		return [ (string) $definition['id'], $renderer->render_single_field( $definition, $options ) ];
	}

	/**
	 * Whitelist a single field coming from the builder.
	 *
	 * @param array $field Raw field payload.
	 */
	private function sanitize_field( array $field ): array {
		$clean = [
			'id'          => sanitize_key( (string) ( $field['id'] ?? '' ) ),
			'type'        => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
			'label'       => sanitize_text_field( (string) ( $field['label'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $field['description'] ?? '' ) ),
			'default'     => is_scalar( $field['default'] ?? null ) ? sanitize_text_field( (string) $field['default'] ) : '',
			'required'    => ! empty( $field['required'] ),
			'width'       => sanitize_html_class( (string) ( $field['width'] ?? '' ) ),
			'class'       => sanitize_text_field( (string) ( $field['class'] ?? '' ) ),
			'multiple'    => ! empty( $field['multiple'] ),
			'text'        => sanitize_text_field( (string) ( $field['text'] ?? '' ) ),
			'placeholder' => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
			'unit'        => sanitize_text_field( (string) ( $field['unit'] ?? '' ) ),
			'media_type'  => sanitize_key( (string) ( $field['media_type'] ?? 'image' ) ),
			'language'    => sanitize_text_field( (string) ( $field['language'] ?? 'text/html' ) ),
			'conditions'  => $this->sanitize_conditions( $field['conditions'] ?? [] ),
			'required_if' => $this->sanitize_rules( $field['required_if'] ?? [] ),
			'options'     => [],
		];

		foreach ( [ 'min', 'max', 'step', 'rows', 'max_rows', 'minlength' ] as $numeric_key ) {
			if ( isset( $field[ $numeric_key ] ) && '' !== $field[ $numeric_key ] ) {
				$clean[ $numeric_key ] = (string) (float) $field[ $numeric_key ];
			}
		}

		foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
			if ( is_array( $option ) && isset( $option[0] ) ) {
				$clean['options'][] = [
					sanitize_text_field( (string) $option[0] ),
					sanitize_text_field( (string) ( $option[1] ?? $option[0] ) ),
				];
			}
		}

		return $clean;
	}

	/**
	 * Convert one builder field into a field definition.
	 *
	 * @param array $field Sanitized builder field.
	 */
	private function model_field_to_schema( array $field ): array {
		$def = [
			'id'          => (string) $field['id'],
			'type'        => (string) $field['type'],
			'label'       => (string) ( $field['label'] ?? '' ),
			'description' => (string) ( $field['description'] ?? '' ),
			'default'     => $field['default'] ?? '',
			'conditions'  => $field['conditions'] ?? [],
		];

		foreach ( [ 'required', 'multiple' ] as $flag ) {
			if ( ! empty( $field[ $flag ] ) ) {
				$def[ $flag ] = true;
			}
		}

		foreach ( [ 'width', 'class', 'text', 'placeholder', 'unit', 'media_type', 'language' ] as $key ) {
			if ( ! empty( $field[ $key ] ) ) {
				$def[ $key ] = (string) $field[ $key ];
			}
		}

		foreach ( [ 'min', 'max', 'step', 'rows', 'max_rows', 'minlength' ] as $key ) {
			if ( isset( $field[ $key ] ) && '' !== $field[ $key ] ) {
				$def[ $key ] = is_numeric( $field[ $key ] ) ? $field[ $key ] + 0 : $field[ $key ];
			}
		}

		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			$options = [];

			foreach ( $field['options'] as $pair ) {
				if ( is_array( $pair ) && isset( $pair[0] ) && '' !== $pair[0] ) {
					$options[ (string) $pair[0] ] = (string) ( $pair[1] ?? $pair[0] );
				}
			}

			if ( $options ) {
				$def['options'] = $options;
			}
		}

		if ( ! empty( $field['required_if'] ) && is_array( $field['required_if'] ) ) {
			$def['required_if'] = $field['required_if'];
		}

		return $def;
	}

	/**
	 * Register hooks (no-op when the builder is disabled).
	 */
	public function register(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_menu' ], 61 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . $this->save_action(), [ $this, 'handle_save' ] );
		add_action( 'wp_ajax_' . $this->field_action(), [ $this, 'handle_render_field' ] );
	}

	/**
	 * Register the builder page next to the options page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			(string) $this->framework->get_config( 'parent_menu' ),
			__( 'RL Builder', RL_Options_Framework::text_domain() ),
			__( 'RL Builder', RL_Options_Framework::text_domain() ),
			(string) $this->framework->get_config( 'capability' ),
			$this->page_slug(),
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue builder assets on its own screen only.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, $this->page_slug() ) ) {
			return;
		}

		$config     = $this->framework->get_config();
		$assets_url = trailingslashit( (string) $this->framework->get_config( 'assets_url' ) );

		if ( '' === $assets_url || '/' === $assets_url ) {
			$assets_url = trailingslashit( plugin_dir_url( dirname( __DIR__ ) . '/main.php' ) . 'assets' );
		}

		// The canvas renders real field markup, so it needs the real field styles.
		$handle_base     = $this->framework->asset_handle_base();
		$framework_style = $handle_base . '-framework';
		$builder_style   = $handle_base . '-builder';
		$builder_script  = $handle_base . '-builder-script';

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( $framework_style, $assets_url . 'css/options-framework.css', [ 'dashicons', 'wp-color-picker' ], RL_Options_Framework::VERSION );
		wp_enqueue_style( $builder_style, $assets_url . 'css/options-builder.css', [ 'dashicons', $framework_style ], RL_Options_Framework::VERSION );
		wp_enqueue_script( $builder_script, $assets_url . 'js/options-builder.js', [], RL_Options_Framework::VERSION, true );

		wp_localize_script(
			$builder_script,
			'rlBuilder',
			[
				'nonce'       => wp_create_nonce( $this->save_action() . '_nonce' ),
				'saveAction'  => $this->save_action(),
				'fieldAction' => $this->field_action(),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'optionName'  => (string) $config['option_name'],
				'prefix'      => (string) $config['form_field_prefix'],
				'pageSlug'    => $this->page_slug(),
				'draft'       => $this->get_draft(),
				'currentTabs' => $this->current_tabs_for_export(),
				'catalog'     => $this->field_catalog(),
				'operators'   => $this->operators(),
				'textDomain'  => RL_Options_Framework::text_domain(),
			]
		);
	}

	/**
	 * Stored draft (decoded) or an empty schema.
	 */
	public function get_draft(): array {
		$raw = get_option( $this->draft_key(), '' );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return [ 'tabs' => [] ];
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) && isset( $decoded['tabs'] ) && is_array( $decoded['tabs'] ) ? $decoded : [ 'tabs' => [] ];
	}

	/**
	 * Persist the draft (AJAX).
	 */
	public function handle_save(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $this->save_action() . '_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Refresh the page and try again.', RL_Options_Framework::text_domain() ) ], 403 );
		}

		if ( ! current_user_can( (string) $this->framework->get_config( 'capability' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to use the builder.', RL_Options_Framework::text_domain() ) ], 403 );
		}

		$payload = isset( $_POST['schema'] ) ? wp_unslash( $_POST['schema'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is re-encoded below.
		$payload = is_string( $payload ) ? $payload : '';

		if ( strlen( $payload ) > 1048576 ) {
			wp_send_json_error( [ 'message' => __( 'Schema is too large to store.', RL_Options_Framework::text_domain() ) ], 413 );
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['tabs'] ) || ! is_array( $decoded['tabs'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid schema payload.', RL_Options_Framework::text_domain() ) ], 400 );
		}

		$schema = $this->sanitize_schema( $decoded );
		update_option( $this->draft_key(), wp_json_encode( $schema ), false );

		wp_send_json_success(
			[
				'message' => __( 'Draft saved.', RL_Options_Framework::text_domain() ),
				'tabs'    => count( $schema['tabs'] ),
			]
		);
	}

	/**
	 * Whitelist an incoming schema so only known keys are stored.
	 *
	 * Public so integrators can validate a schema (from a form, an API call or a
	 * generated export) before persisting it themselves.
	 *
	 * @param array $schema Raw schema (`[ 'tabs' => [ … ] ]`).
	 * @return array Sanitized schema with the same shape.
	 */
	public function sanitize_schema( array $schema ): array {
		$clean = [ 'tabs' => [] ];

		foreach ( (array) ( $schema['tabs'] ?? [] ) as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}

			$clean_tab = [
				'id'         => sanitize_key( (string) ( $tab['id'] ?? '' ) ),
				'label'      => sanitize_text_field( (string) ( $tab['label'] ?? '' ) ),
				'priority'   => (int) ( $tab['priority'] ?? 10 ),
				'conditions' => $this->sanitize_conditions( $tab['conditions'] ?? [] ),
				'sections'   => [],
			];

			foreach ( (array) ( $tab['sections'] ?? [] ) as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}

				$clean_section = [
					'id'         => sanitize_key( (string) ( $section['id'] ?? '' ) ),
					'title'      => sanitize_text_field( (string) ( $section['title'] ?? '' ) ),
					'priority'   => (int) ( $section['priority'] ?? 10 ),
					'accordion'  => ! empty( $section['accordion'] ),
					'conditions' => $this->sanitize_conditions( $section['conditions'] ?? [] ),
					'fields'     => [],
				];

				foreach ( (array) ( $section['fields'] ?? [] ) as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}

					$clean_field = [
						'id'          => sanitize_key( (string) ( $field['id'] ?? '' ) ),
						'type'        => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
						'label'       => sanitize_text_field( (string) ( $field['label'] ?? '' ) ),
						'description' => sanitize_textarea_field( (string) ( $field['description'] ?? '' ) ),
						'default'     => is_scalar( $field['default'] ?? null ) ? sanitize_text_field( (string) $field['default'] ) : '',
						'required'    => ! empty( $field['required'] ),
						'width'       => sanitize_html_class( (string) ( $field['width'] ?? '' ) ),
						'class'       => sanitize_text_field( (string) ( $field['class'] ?? '' ) ),
						'multiple'    => ! empty( $field['multiple'] ),
						'text'        => sanitize_text_field( (string) ( $field['text'] ?? '' ) ),
						'placeholder' => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
						'unit'        => sanitize_text_field( (string) ( $field['unit'] ?? '' ) ),
						'media_type'  => sanitize_key( (string) ( $field['media_type'] ?? 'image' ) ),
						'language'    => sanitize_text_field( (string) ( $field['language'] ?? 'text/html' ) ),
						'conditions'  => $this->sanitize_conditions( $field['conditions'] ?? [] ),
						'required_if' => $this->sanitize_rules( $field['required_if'] ?? [] ),
						'options'     => [],
					];

					foreach ( [ 'min', 'max', 'step', 'rows', 'max_rows', 'minlength' ] as $numeric_key ) {
						if ( isset( $field[ $numeric_key ] ) && '' !== $field[ $numeric_key ] ) {
							$clean_field[ $numeric_key ] = (string) (float) $field[ $numeric_key ];
						}
					}

					foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
						if ( ! is_array( $option ) || ! isset( $option[0] ) ) {
							continue;
						}
						$clean_field['options'][] = [
							sanitize_text_field( (string) $option[0] ),
							sanitize_text_field( (string) ( $option[1] ?? $option[0] ) ),
						];
					}

					$clean_section['fields'][] = $clean_field;
				}

				$clean_tab['sections'][] = $clean_section;
			}

			$clean['tabs'][] = $clean_tab;
		}

		return $clean;
	}

	/**
	 * Sanitize a condition group.
	 *
	 * @param mixed $conditions Condition payload.
	 */
	private function sanitize_conditions( $conditions ): array {
		$conditions = is_array( $conditions ) ? $conditions : [];
		$relation   = 'OR' === strtoupper( (string) ( $conditions['relation'] ?? 'AND' ) ) ? 'OR' : 'AND';

		return [
			'relation' => $relation,
			'rules'    => $this->sanitize_rules( $conditions['rules'] ?? [] ),
		];
	}

	/**
	 * Sanitize a rule list.
	 *
	 * @param mixed $rules Rule payload.
	 */
	private function sanitize_rules( $rules ): array {
		$clean = [];

		foreach ( (array) $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				continue;
			}

			$value   = $rule['value'] ?? '';
			$clean[] = [
				'field'    => sanitize_key( (string) $rule['field'] ),
				'operator' => sanitize_key( (string) ( $rule['operator'] ?? 'equals' ) ),
				'value'    => is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '',
			];
		}

		return $clean;
	}

	/**
	 * Existing tabs, prepared for the "load current tabs" action.
	 */
	private function current_tabs_for_export(): array {
		$tabs  = $this->framework->get_tabs();
		$model = [];

		foreach ( $tabs as $slug => $tab ) {
			$node = [
				'id'       => (string) $slug,
				'label'    => (string) ( $tab['label'] ?? $slug ),
				'priority' => (int) ( $tab['priority'] ?? 10 ),
				'sections' => [],
			];

			foreach ( (array) ( $tab['sections'] ?? [] ) as $section_id => $section ) {
				$section_node = [
					'id'        => (string) $section_id,
					'title'     => (string) ( $section['title'] ?? $section_id ),
					'priority'  => (int) ( $section['priority'] ?? 10 ),
					'accordion' => ! empty( $section['accordion'] ),
					'fields'    => [],
				];

				foreach ( (array) ( $section['fields'] ?? [] ) as $field_id => $field ) {
					$section_node['fields'][] = [
						'id'          => (string) $field_id,
						'type'        => (string) ( $field['type'] ?? 'text' ),
						'label'       => (string) ( $field['label'] ?? '' ),
						'description' => (string) ( $field['description'] ?? $field['desc'] ?? '' ),
						'default'     => is_scalar( $field['default'] ?? '' ) ? (string) $field['default'] : '',
						'required'    => ! empty( $field['required'] ),
						'options'     => $this->options_to_pairs( $field['options'] ?? [] ),
						'conditions'  => $this->conditions_to_model( $field['conditions'] ?? [] ),
					];
				}

				$node['sections'][] = $section_node;
			}

			$model[] = $node;
		}

		return $model;
	}

	/**
	 * Convert options to [[value,label],…] pairs for the JS model.
	 *
	 * @param mixed $options Field options.
	 */
	private function options_to_pairs( $options ): array {
		$pairs = [];

		foreach ( (array) $options as $value => $label ) {
			$pairs[] = [ (string) $value, is_scalar( $label ) ? (string) $label : (string) $value ];
		}

		return $pairs;
	}

	/**
	 * Convert stored conditions to the JS model.
	 *
	 * @param mixed $conditions Conditions.
	 */
	private function conditions_to_model( $conditions ): array {
		$conditions = is_array( $conditions ) ? $conditions : [];
		$rules      = [];

		foreach ( (array) ( $conditions['rules'] ?? [] ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$value   = $rule['value'] ?? '';
			$rules[] = [
				'field'    => (string) ( $rule['field'] ?? '' ),
				'operator' => (string) ( $rule['operator'] ?? 'equals' ),
				'value'    => is_scalar( $value ) ? (string) $value : '',
			];
		}

		return [
			'relation' => (string) ( $conditions['relation'] ?? 'AND' ),
			'rules'    => $rules,
		];
	}

	/**
	 * Field catalogue used by the palette and the inspector.
	 */
	private function field_catalog(): array {
		return [
			'groups' => [
				[
					'id'    => 'text',
					'label' => __( 'Text & numbers', RL_Options_Framework::text_domain() ),
					'types' => [ 'text', 'textarea', 'password', 'number', 'slider', 'spinner', 'code', 'editor' ],
				],
				[
					'id'    => 'choice',
					'label' => __( 'Choice', RL_Options_Framework::text_domain() ),
					'types' => [ 'select', 'multiselect', 'radio', 'checkbox', 'toggle', 'switch', 'button_set', 'image_select', 'icon_select' ],
				],
				[
					'id'    => 'media',
					'label' => __( 'Media', RL_Options_Framework::text_domain() ),
					'types' => [ 'image', 'media', 'gallery' ],
				],
				[
					'id'    => 'design',
					'label' => __( 'Design', RL_Options_Framework::text_domain() ),
					'types' => [ 'color', 'color_palette', 'color_gradient', 'link_color', 'border', 'box_shadow', 'dimensions', 'spacing', 'typography', 'background' ],
				],
				[
					'id'    => 'content',
					'label' => __( 'Content & layout', RL_Options_Framework::text_domain() ),
					'types' => [ 'html', 'info', 'divider', 'raw' ],
				],
				[
					'id'    => 'collections',
					'label' => __( 'Collections', RL_Options_Framework::text_domain() ),
					'types' => [ 'multi_text', 'sortable', 'sorter' ],
				],
				[
					'id'    => 'geo',
					'label' => __( 'Geo & dates', RL_Options_Framework::text_domain() ),
					'types' => [ 'country', 'state', 'city', 'country_state_city', 'date', 'datetime' ],
				],
				[
					'id'    => 'system',
					'label' => __( 'System', RL_Options_Framework::text_domain() ),
					'types' => [ 'export', 'import', 'reset', 'reset_section' ],
				],
			],
			'types'  => [
				'text'        => [ 'label' => 'text', 'props' => [ 'placeholder', 'required', 'width', 'class' ] ],
				'textarea'    => [ 'label' => 'textarea', 'props' => [ 'placeholder', 'required', 'width', 'class' ] ],
				'password'    => [ 'label' => 'password', 'props' => [ 'minlength', 'required', 'width' ] ],
				'number'      => [ 'label' => 'number', 'props' => [ 'min', 'max', 'step', 'unit', 'width' ] ],
				'slider'      => [ 'label' => 'slider', 'props' => [ 'min', 'max', 'step', 'unit', 'width' ] ],
				'spinner'     => [ 'label' => 'spinner', 'props' => [ 'min', 'max', 'step', 'unit', 'width' ] ],
				'code'        => [ 'label' => 'code', 'props' => [ 'language', 'rows', 'width' ] ],
				'editor'      => [ 'label' => 'editor', 'props' => [ 'rows', 'width' ] ],
				'select'      => [ 'label' => 'select', 'props' => [ 'options', 'required', 'width' ] ],
				'multiselect' => [ 'label' => 'multiselect', 'props' => [ 'options', 'required', 'width' ] ],
				'radio'       => [ 'label' => 'radio', 'props' => [ 'options', 'required', 'width' ] ],
				'checkbox'    => [ 'label' => 'checkbox', 'props' => [ 'text', 'width' ] ],
				'toggle'      => [ 'label' => 'toggle', 'props' => [ 'text', 'width' ] ],
				'switch'      => [ 'label' => 'switch', 'props' => [ 'text', 'width' ] ],
				'button_set'  => [ 'label' => 'button_set', 'props' => [ 'options', 'multiple', 'width' ] ],
				'image_select' => [ 'label' => 'image_select', 'props' => [ 'options', 'width' ] ],
				'icon_select' => [ 'label' => 'icon_select', 'props' => [ 'width' ] ],
				'image'       => [ 'label' => 'image', 'props' => [ 'width' ] ],
				'media'       => [ 'label' => 'media', 'props' => [ 'media_type', 'width' ] ],
				'gallery'     => [ 'label' => 'gallery', 'props' => [ 'width' ] ],
				'color'       => [ 'label' => 'color', 'props' => [ 'width' ] ],
				'color_palette' => [ 'label' => 'color_palette', 'props' => [ 'options', 'width' ] ],
				'color_gradient' => [ 'label' => 'color_gradient', 'props' => [] ],
				'link_color'  => [ 'label' => 'link_color', 'props' => [] ],
				'border'      => [ 'label' => 'border', 'props' => [] ],
				'box_shadow'  => [ 'label' => 'box_shadow', 'props' => [] ],
				'dimensions'  => [ 'label' => 'dimensions', 'props' => [] ],
				'spacing'     => [ 'label' => 'spacing', 'props' => [] ],
				'typography'  => [ 'label' => 'typography', 'props' => [] ],
				'background'  => [ 'label' => 'background', 'props' => [] ],
				'html'        => [ 'label' => 'html', 'props' => [] ],
				'info'        => [ 'label' => 'info', 'props' => [] ],
				'divider'     => [ 'label' => 'divider', 'props' => [] ],
				'raw'         => [ 'label' => 'raw', 'props' => [] ],
				'multi_text'  => [ 'label' => 'multi_text', 'props' => [ 'max_rows', 'width' ] ],
				'sortable'    => [ 'label' => 'sortable', 'props' => [ 'options', 'width' ] ],
				'sorter'      => [ 'label' => 'sorter', 'props' => [ 'options', 'width' ] ],
				'country'     => [ 'label' => 'country', 'props' => [ 'width' ] ],
				'state'       => [ 'label' => 'state', 'props' => [ 'width' ] ],
				'city'        => [ 'label' => 'city', 'props' => [ 'width' ] ],
				'country_state_city' => [ 'label' => 'country_state_city', 'props' => [] ],
				'date'        => [ 'label' => 'date', 'props' => [ 'width' ] ],
				'datetime'    => [ 'label' => 'datetime', 'props' => [ 'width' ] ],
				'export'      => [ 'label' => 'export', 'props' => [] ],
				'import'      => [ 'label' => 'import', 'props' => [] ],
				'reset'       => [ 'label' => 'reset', 'props' => [] ],
				'reset_section' => [ 'label' => 'reset_section', 'props' => [] ],
			],
		];
	}

	/**
	 * Operators offered by the condition/required editors.
	 */
	private function operators(): array {
		return [
			'equals'     => __( 'equals', RL_Options_Framework::text_domain() ),
			'not_equals' => __( 'not equals', RL_Options_Framework::text_domain() ),
			'in'         => __( 'in', RL_Options_Framework::text_domain() ),
			'not_in'     => __( 'not in', RL_Options_Framework::text_domain() ),
			'empty'      => __( 'is empty', RL_Options_Framework::text_domain() ),
			'not_empty'  => __( 'is not empty', RL_Options_Framework::text_domain() ),
			'truthy'     => __( 'is truthy', RL_Options_Framework::text_domain() ),
			'falsy'      => __( 'is falsy', RL_Options_Framework::text_domain() ),
			'contains'   => __( 'contains', RL_Options_Framework::text_domain() ),
			'match'      => __( 'matches regex', RL_Options_Framework::text_domain() ),
			'>'          => __( 'greater than', RL_Options_Framework::text_domain() ),
			'>='         => __( 'greater or equal', RL_Options_Framework::text_domain() ),
			'<'          => __( 'less than', RL_Options_Framework::text_domain() ),
			'<='         => __( 'less or equal', RL_Options_Framework::text_domain() ),
		];
	}

	/**
	 * Render the builder shell. All UI is driven by options-builder.js.
	 */
	public function render_page(): void {
		if ( ! current_user_can( (string) $this->framework->get_config( 'capability' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to use the builder.', RL_Options_Framework::text_domain() ) );
		}
		?>
		<div class="wrap rl-builder-wrap">
			<div class="rl-builder" id="rl-builder">
				<header class="rl-builder-bar">
					<div class="rl-builder-title">
						<h1><?php esc_html_e( 'RL Builder', RL_Options_Framework::text_domain() ); ?></h1>
						<p class="rl-builder-sub">
							<?php
							printf(
								/* translators: %s: option name */
								esc_html__( 'Drag fields into a section, select one to edit its conditions and rules, then export the PHP for %s.', RL_Options_Framework::text_domain() ),
								'<code>' . esc_html( (string) $this->framework->get_config( 'option_name' ) ) . '</code>'
							);
							?>
						</p>
					</div>
					<div class="rl-builder-actions">
						<div class="rl-builder-history" role="group" aria-label="<?php esc_attr_e( 'History', RL_Options_Framework::text_domain() ); ?>">
							<button type="button" class="button rl-builder-icon-button" data-builder-action="undo" title="<?php esc_attr_e( 'Undo (Ctrl/Cmd+Z)', RL_Options_Framework::text_domain() ); ?>" disabled>
								<span class="dashicons dashicons-undo" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Undo', RL_Options_Framework::text_domain() ); ?></span>
							</button>
							<button type="button" class="button rl-builder-icon-button" data-builder-action="redo" title="<?php esc_attr_e( 'Redo (Ctrl/Cmd+Shift+Z)', RL_Options_Framework::text_domain() ); ?>" disabled>
								<span class="dashicons dashicons-redo" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Redo', RL_Options_Framework::text_domain() ); ?></span>
							</button>
						</div>
						<button type="button" class="button rl-builder-toggle" data-builder-action="wysiwyg" aria-pressed="false" title="<?php esc_attr_e( 'Hide the builder chrome and show only the fields as visitors will see them', RL_Options_Framework::text_domain() ); ?>">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							<span class="rl-builder-toggle-label"><?php esc_html_e( 'Preview page', RL_Options_Framework::text_domain() ); ?></span>
						</button>
						<button type="button" class="button" data-builder-action="new"><?php esc_html_e( 'New', RL_Options_Framework::text_domain() ); ?></button>
						<button type="button" class="button" data-builder-action="load-current"><?php esc_html_e( 'Load current tabs', RL_Options_Framework::text_domain() ); ?></button>
						<button type="button" class="button" data-builder-action="save"><?php esc_html_e( 'Save draft', RL_Options_Framework::text_domain() ); ?></button>
						<button type="button" class="button button-primary" data-builder-action="export"><?php esc_html_e( 'Export PHP', RL_Options_Framework::text_domain() ); ?></button>
						<span class="rl-builder-dirty is-clean" data-builder-dirty aria-live="polite"><?php esc_html_e( 'All changes saved', RL_Options_Framework::text_domain() ); ?></span>
						<span class="rl-builder-status" data-builder-status aria-live="polite"></span>
					</div>
				</header>

				<div class="rl-builder-body">
					<aside class="rl-builder-palette" aria-label="<?php esc_attr_e( 'Field palette', RL_Options_Framework::text_domain() ); ?>">
						<label class="screen-reader-text" for="rl-builder-search"><?php esc_html_e( 'Search field types', RL_Options_Framework::text_domain() ); ?></label>
						<input type="search" id="rl-builder-search" class="rl-builder-search" data-builder-search placeholder="<?php esc_attr_e( 'Filter field types…', RL_Options_Framework::text_domain() ); ?>" autocomplete="off">
						<h2><?php esc_html_e( 'Fields', RL_Options_Framework::text_domain() ); ?></h2>
						<div class="rl-builder-palette-groups" data-builder-palette></div>
					</aside>

					<main class="rl-builder-canvas" aria-label="<?php esc_attr_e( 'Schema canvas', RL_Options_Framework::text_domain() ); ?>">
						<div class="rl-builder-tabs" data-builder-canvas></div>
						<div class="rl-builder-empty" data-builder-empty>
							<p><?php esc_html_e( 'No tabs yet.', RL_Options_Framework::text_domain() ); ?></p>
							<button type="button" class="button" data-builder-action="add-tab"><?php esc_html_e( '+ Add tab', RL_Options_Framework::text_domain() ); ?></button>
						</div>
					</main>

					<aside class="rl-builder-inspector" data-builder-inspector aria-label="<?php esc_attr_e( 'Inspector', RL_Options_Framework::text_domain() ); ?>">
						<p class="rl-builder-hint"><?php esc_html_e( 'Select a field, section or tab to edit it.', RL_Options_Framework::text_domain() ); ?></p>
					</aside>
				</div>

				<section class="rl-builder-export" data-builder-export hidden>
					<div class="rl-builder-export-bar">
						<h2><?php esc_html_e( 'Export', RL_Options_Framework::text_domain() ); ?></h2>
						<div class="rl-builder-export-actions">
							<label class="rl-builder-format">
								<span><?php esc_html_e( 'Format', RL_Options_Framework::text_domain() ); ?></span>
								<select data-builder-format>
									<option value="calls"><?php esc_html_e( 'add_tab / add_section / add_field calls', RL_Options_Framework::text_domain() ); ?></option>
									<option value="filter"><?php esc_html_e( 'Tabs filter array', RL_Options_Framework::text_domain() ); ?></option>
								</select>
							</label>
							<button type="button" class="button" data-builder-action="copy"><?php esc_html_e( 'Copy', RL_Options_Framework::text_domain() ); ?></button>
							<button type="button" class="button" data-builder-action="download"><?php esc_html_e( 'Download .php', RL_Options_Framework::text_domain() ); ?></button>
							<button type="button" class="button-link" data-builder-action="close-export"><?php esc_html_e( 'Close', RL_Options_Framework::text_domain() ); ?></button>
						</div>
					</div>
					<pre class="rl-builder-code"><code data-builder-code></code></pre>
				</section>
			</div>
		</div>
		<?php
	}
}

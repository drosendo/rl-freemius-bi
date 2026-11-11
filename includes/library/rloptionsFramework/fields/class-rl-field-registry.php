<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

class RL_Field_Registry
{
	/**
	 * @var array<string,RL_Field_Interface>
	 */
	private array $renderers = [];

	public function register(RL_Field_Interface $renderer): void
	{
		$this->renderers[$renderer->type()] = $renderer;
	}

	public function get(string $type): ?RL_Field_Interface
	{
		return $this->renderers[$type] ?? null;
	}

	/**
	 * Map an alternative type name onto an already registered renderer.
	 *
	 * @param string $alias Alias type name.
	 * @param string $type  Registered type to reuse.
	 */
	public function register_alias(string $alias, string $type): void
	{
		if (isset($this->renderers[$type])) {
			$this->renderers[$alias] = $this->renderers[$type];
		}
	}

	/**
	 * @return string[]
	 */
	public function types(): array
	{
		return array_keys($this->renderers);
	}
}

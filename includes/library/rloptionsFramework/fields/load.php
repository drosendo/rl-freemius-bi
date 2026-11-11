<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (!defined('ABSPATH')) {
	return;
}

require_once __DIR__ . '/class-rl-field-interface.php';
require_once __DIR__ . '/class-rl-field-processing-interface.php';
require_once __DIR__ . '/class-rl-field-registry.php';

// Shared bases must exist before the alphabetically-sorted renderers are loaded.
require_once __DIR__ . '/class-rl-field-composite.php';

$reserved = [
	'class-rl-field-interface.php',
	'class-rl-field-processing-interface.php',
	'class-rl-field-registry.php',
	'class-rl-field-composite.php',
];

$files = glob(__DIR__ . '/class-rl-field-*.php');
if (is_array($files)) {
	sort($files);
	foreach ($files as $file) {
		if (in_array(basename($file), $reserved, true)) {
			continue;
		}
		require_once $file;
	}
}

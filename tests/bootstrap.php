<?php
/*
 * Test bootstrap.
 *
 * The plugin classes extend the Jeedom core classes and require the core at load time, which is not
 * available when running the tests outside of a Jeedom instance. This file provides minimal stubs and
 * loads the real plugin class with its core require removed, so the tests exercise the actual code.
 * Only pure logic can be tested this way: anything touching the database or the commands needs a Jeedom.
 */
require_once __DIR__ . '/vendor/autoload.php';

if (!class_exists('eqLogic')) {
	class eqLogic {
	}
}
if (!class_exists('cmd')) {
	class cmd {
	}
}
if (!function_exists('__')) {
	function __($_string, $_plugin = '') {
		return $_string;
	}
}

$source = file_get_contents(__DIR__ . '/../core/class/energy.class.php');
$source = preg_replace('/^require_once .*core\.inc\.php.*;\s*$/m', '', $source, 1, $count);
if ($count !== 1) {
	throw new RuntimeException('Could not strip the Jeedom core require from energy.class.php: update tests/bootstrap.php');
}
$file = tempnam(sys_get_temp_dir(), 'energy_class_');
file_put_contents($file, $source);
register_shutdown_function(function () use ($file) {
	@unlink($file);
});
require_once $file;

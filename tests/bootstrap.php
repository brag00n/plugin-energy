<?php
/*
 * Test bootstrap.
 *
 * The plugin classes extend the Jeedom core classes and require the core at load time, which is not
 * available when running the tests outside of a Jeedom instance. This file loads in-memory doubles of
 * the core (tests/Support/JeedomStubs.php) and the real plugin class with its core require removed, so
 * the tests exercise the actual plugin code. Anything touching a real database still needs a Jeedom.
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Support/JeedomStubs.php';

$source = file_get_contents(__DIR__ . '/../core/class/energy.class.php');
$source = preg_replace('/^require_once .*core\.inc\.php.*;\s*$/m', '', $source, 1, $count);
if ($count !== 1) {
	throw new RuntimeException('Could not strip the Jeedom core require from energy.class.php: update tests/bootstrap.php');
}
// The class is loaded from a temporary copy, so the paths relative to its own file (the anomaly detection folder)
// must point to the real folder instead. When the anomaly detection has been removed from the plugin (see
// energyAnomaly.class.php), there is nothing to redirect: that is fine.
$source = str_replace("dirname(__FILE__) . '/anomaly/", var_export(realpath(__DIR__ . '/../core/class') . '/anomaly/', true) . " . '", $source, $anomalyCount);
if ($anomalyCount < 1 && strpos($source, 'ANOMALY') !== false) {
	throw new RuntimeException('Could not redirect the anomaly folder of energy.class.php: update tests/bootstrap.php');
}
$file = tempnam(sys_get_temp_dir(), 'energy_class_');
file_put_contents($file, $source);
register_shutdown_function(function () use ($file) {
	@unlink($file);
});
require_once $file;
require_once __DIR__ . '/Support/EnergyTestCase.php';

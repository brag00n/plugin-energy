<?php
/*
 * TYPES OF DEBUG TRACES OF THE PLUGIN
 *
 * The debug level of the log of the plugin (Settings > System > Configuration > Logs) switches ALL the debug traces on.
 * The plugin writes several kinds of them, and each can be switched off on its own from the configuration of the
 * plugin (section "Logs"), so that one kind can be followed without drowning in the others:
 *
 *   type      setting (plugin "energy")   prefix of the lines   written by
 *   --------  --------------------------  ---------------------  ------------------------------------------
 *   perf      logPerf                     [perf]                 the panel (getGraph, SQL, ajax duration)
 *   anomaly   logAnomaly                  [jump] [cron]          the abnormal consumption detection
 *
 * A type is written only when BOTH hold: the log level of the plugin is debug, and the checkbox of the type is on.
 * Both are on by default, so nothing changes until a box is unchecked. The warnings of the detection (an abnormal
 * jump, a cron that ran late) are NOT debug traces and are written whatever these boxes say.
 *
 * To add a type: add a line in TYPES and a checkbox with the same data-l1key in plugin_info/configuration.php.
 */
class energyLogTypes {

	/** Type => setting that switches it off. */
	const TYPES = array(
		'perf' => 'logPerf',
		'anomaly' => 'logAnomaly',
	);

	/** Forces a type on or off, only meant for the unit tests: type => bool. */
	public static $override = array();

	/** True when the log level of the plugin lets debug traces through. */
	public static function isDebugLevel() {
		return log::getLogLevel('energy') <= 100;
	}

	/** True when the checkbox of the type is on (on by default, and for a type this class does not know). */
	public static function isTypeOn($_type) {
		if (!isset(self::TYPES[$_type])) {
			return true;
		}
		return config::byKey(self::TYPES[$_type], 'energy', 1) != 0;
	}

	/** True when debug traces of this type must be written: the debug level AND the checkbox of the type. */
	public static function enabled($_type) {
		if (array_key_exists($_type, self::$override)) {
			return (bool) self::$override[$_type];
		}
		return self::isDebugLevel() && self::isTypeOn($_type);
	}
}

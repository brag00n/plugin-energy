<?php
/*
 * ANOMALY DETECTION - THE ENTRY POINT
 *
 * The only class the rest of the plugin talks to. energy::cron15() calls it in exactly 3 places, all marked
 * "ANOMALY" in core/class/energy.class.php:
 *
 *   1. energyAnomaly::cycleStart($now)                           at the start of a cron15 cycle
 *   2. $anomalyLines[] = energyAnomaly::line(...)                for each line that brings energy to an equipment
 *   3. energyAnomaly::check($energy, $quantity, $lines, ...)    once per equipment, before it is saved
 *
 * A 4th call, energyAnomaly::forgetProfile($this), is made by energy::emptyHistory().
 *
 * The detection only REPORTS: it never changes what is credited to an equipment.
 *
 * ---------------------------------------------------------------------------------------------------------
 * TO REMOVE THE DETECTION COMPLETELY
 *   1. delete the folder core/class/anomaly/ and the folder tests/Unit/Anomaly/;
 *   2. in core/class/energy.class.php, delete the block between the markers "ANOMALY: begin" and "ANOMALY: end"
 *      (it requires the folder) and the 3 calls above and the call of emptyHistory() (search "ANOMALY");
 *   3. optionally, remove the configuration it wrote: the "cronHistory" setting of the plugin and the
 *      "jumpProfile" configuration of each equipment (they are small and harmless if left).
 * Nothing else depends on it.
 *
 * TO DISABLE IT WITHOUT REMOVING IT: set the plugin setting "energyAnomalyEnabled" to 0.
 * ---------------------------------------------------------------------------------------------------------
 *
 * FILES (core/class/anomaly/)
 *   energyAnomaly.class.php             this file: entry point, one method per step
 *   energyAnomalyRules.class.php        THE RULES R1..R12, each described (read this one to understand or change them)
 *   energyAnomalyKind.class.php         what differs per energy (base class)
 *   energyAnomalyElectricity.class.php  electricity: kWh, rate in W
 *   energyAnomalyWater.class.php        water: L, rate in L/h
 *   energyAnomalyGas.class.php          gas: kWh, rate in W
 *   energyAnomalyProfile.class.php      what is learned about an equipment, and how it is stored
 *   energyAnomalyCron.class.php         the memory of the last cycles of cron15
 *   energyAnomalyReport.class.php       every log line written by the detector
 */
class energyAnomaly {

	/** The detection can be switched off with the plugin setting "energyAnomalyEnabled" = 0 (on by default). */
	public static function isEnabled() {
		return config::byKey('energyAnomalyEnabled', 'energy', 1) != 0;
	}

	/** The class that handles an energy ("electricity", "water", "gas"). Anything else is handled as electricity. */
	public static function kindFor($_type) {
		switch ($_type) {
			case 'water':
				return new energyAnomalyWater();
			case 'gas':
				return new energyAnomalyGas();
			default:
				return new energyAnomalyElectricity();
		}
	}

	/**
	 * 1. Start of a cron15 cycle: remembers it and traces how it follows the previous ones.
	 *
	 * @return array|null the cron description (see energyAnomalyCron::record()), null when the detection is disabled
	 */
	public static function cycleStart($_now) {
		if (!self::isEnabled()) {
			return null;
		}
		$cron = energyAnomalyCron::record($_now);
		energyAnomalyReport::cronCycle($cron, energy::integrationMaxGap());
		return $cron;
	}

	/**
	 * 2. Describes what one line of an equipment brought in this cycle (to explain a jump).
	 *
	 * @param string $_key key of the line (see energy::getConfEnergyKey())
	 * @param string $_category category of the line
	 * @param string $_mode "index delta" or "integrated power"
	 * @param float $_quantity quantity brought by the line, in the unit of the energy
	 * @param string $_info what it comes from (index before => after, power and duration...)
	 */
	public static function line($_key, $_category, $_mode, $_quantity, $_info) {
		return array('key' => $_key, 'category' => $_category, 'mode' => $_mode, 'quantity' => $_quantity, 'info' => $_info);
	}

	/**
	 * 4. Forgets what was learned about an equipment (called when its history is emptied, because the rates learned
	 *    come from the data that is being erased).
	 */
	public static function forgetProfile($_energy) {
		energyAnomalyProfile::forget($_energy);
	}

	/**
	 * 3. Judges the cycle of one equipment, learns it or reports it.
	 *
	 * @param eqLogic $_energy the equipment (its profile is updated in memory, the caller saves it)
	 * @param float $_quantity quantity credited to it in this cycle, in the unit of its energy
	 * @param array $_lines the lines that brought it, see line()
	 * @param array|null $_cron result of cycleStart()
	 * @param int|null $_now timestamp of the cycle
	 * @return bool true when a warning was logged
	 */
	public static function check($_energy, $_quantity, $_lines, $_cron, $_now = null) {
		if ($_cron === null || !self::isEnabled()) {
			return false;
		}
		$now = ($_now !== null) ? $_now : time();
		$kind = self::kindFor($_energy->getConfiguration('type'));
		$name = $_energy->getHumanName();

		// R1: the rate of the cycle
		list($duration, $durationKnown) = energyAnomalyRules::cycleDuration($_cron);
		$rate = $kind->rate($_quantity, $duration);

		// R2, R3, R4: judge it against what this equipment did before
		$profile = energyAnomalyProfile::load($_energy);
		$verdict = energyAnomalyRules::judge($profile, $rate, $kind);
		energyAnomalyReport::decision($name, $kind, $_quantity, $duration, $durationKnown, $rate, $profile, $verdict);

		// R5, R6, R7: a normal cycle is learned
		if (!$verdict['abnormal']) {
			$learned = energyAnomalyRules::learn($profile, $rate, $now, $kind);
			// R12: once established, a wrong value learned while the profile was built is dropped
			list($learned, $dropped, $ceiling, $median) = energyAnomalyRules::trimOutliers($learned, $kind);
			if (count($dropped) > 0) {
				energyAnomalyReport::outliersDropped($name, $kind, $dropped, $ceiling, $median);
			}
			if ($learned !== $profile) {
				energyAnomalyProfile::store($_energy, $learned);
				energyAnomalyReport::learned($name, $kind, $rate, $learned);
			}
			return false;
		}

		// R8, R9: an abnormal cycle is not learned, unless it is a change of regime
		list($profile, $accepted, $recent) = energyAnomalyRules::registerAbnormal($profile, $rate, $now, $kind);
		energyAnomalyProfile::store($_energy, $profile);
		$regimeCount = intval(energyAnomalyRules::param($kind, 'RegimeCount', energyAnomalyRules::REGIME_COUNT));
		$regimeHours = energyAnomalyRules::param($kind, 'RegimeHours', energyAnomalyRules::REGIME_HOURS);
		energyAnomalyReport::abnormalHandled($name, $kind, $profile, $accepted, $recent, $regimeCount, $regimeHours);

		// R11: explain it
		energyAnomalyReport::warning($name, $kind, $_quantity, $duration, $rate, $verdict, energyAnomalyRules::param($kind, 'Margin', energyAnomalyRules::MARGIN), $_cron, energyAnomalyRules::probableCause($_cron), $_lines, $accepted, $recent, $profile, $regimeHours);
		return true;
	}
}

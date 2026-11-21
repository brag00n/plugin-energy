<?php
/*
 * ANOMALY DETECTION - THE LOGS
 *
 * Every line written by the detector is here, in the log of the plugin ("energy"):
 *
 *   level    prefix / start of the line                  when
 *   -------  ------------------------------------------  ----------------------------------------------------
 *   warning  "Abnormal consumption jump for <eq>"        a cycle above the threshold (rule R3)
 *   warning  "Consumption regime change accepted"        3 abnormal cycles within 6 h (rule R9)
 *   warning  "cron15 ran late"                           the cron was not running in between (rule R10)
 *   debug    "[jump] <eq>: ..."                          every decision of the detector, for the analysis afterwards
 *   debug    "[cron] cycle started"                      every cycle of cron15
 *
 * The debug lines only exist at the debug level of the plugin, and only when the "consumption anomaly" box of the
 * plugin configuration is on: nothing is built nor written otherwise. The warnings are written whatever that box says.
 */
class energyAnomalyReport {

	/**
	 * True when the debug traces of the detection must be written: the log level of the plugin is debug AND the
	 * "consumption anomaly" box of the plugin configuration is on (see energyLogTypes). The warnings are not concerned.
	 */
	public static function isDebug() {
		return energyLogTypes::enabled('anomaly');
	}

	/** One line per cycle of cron15: a warning when the cron ran late, a debug line otherwise. */
	public static function cronCycle($_cron, $_maxGap) {
		if ($_cron['late']) {
			log::add('energy', 'warning', 'cron15 ran late: ' . $_cron['interval'] . ' s since the previous cycle (expected about ' . energyAnomalyCron::NOMINAL_INTERVAL . ' s, tolerated up to ' . $_maxGap . ' s). Last intervals: ' . implode(', ', $_cron['intervals']) . ' s. The cron was not running between these two cycles.');
			return;
		}
		if (self::isDebug()) {
			log::add('energy', 'debug', '[cron] cycle started' . ($_cron['interval'] === null ? ' (first recorded cycle)' : ', ' . $_cron['interval'] . ' s since the previous one (expected about ' . energyAnomalyCron::NOMINAL_INTERVAL . ' s)'));
		}
	}

	/** The decision taken for a cycle: rate, reference, threshold, verdict. */
	public static function decision($_name, $_kind, $_quantity, $_duration, $_durationKnown, $_rate, $_profile, $_verdict) {
		if (!self::isDebug()) {
			return;
		}
		$u = $_kind->rateUnit();
		log::add('energy', 'debug', '[jump] ' . $_name . ': ' . round($_quantity, 3) . ' ' . $_kind->unit() . ' over ' . $_duration . ' s' . ($_durationKnown ? '' : ' (nominal, no previous cycle)') . ' = ' . round($_rate, 1) . ' ' . $u . '; ' . ($_verdict['established']
			? 'reference ' . round($_verdict['reference'], 1) . ' ' . $u . ' over ' . count($_profile['days']) . ' day(s), threshold ' . round($_verdict['threshold'], 1) . ' ' . $u . ' -> ' . ($_verdict['abnormal'] ? 'ABNORMAL' : 'normal')
			: 'profile not established yet (' . $_profile['active'] . '/' . $_verdict['minActive'] . ' active cycles, reference ' . round($_verdict['reference'], 1) . ' ' . $u . ') -> not judged'));
	}

	/** A normal cycle that has been learned. */
	/** R12: a wrong value learned while the profile was built has been dropped (always written: it was a jump no one could judge). */
	public static function outliersDropped($_name, $_kind, $_dropped, $_ceiling, $_median) {
		$u = $_kind->rateUnit();
		$list = array();
		foreach ($_dropped as $day => $rate) {
			$list[] = $day . ': ' . round($rate, 1) . ' ' . $u;
		}
		log::add('energy', 'warning', '[jump] ' . $_name . ': while its profile was built, the equipment learned ' . implode(', ', $list) . ', more than ' . round($_ceiling, 1) . ' ' . $u . ' (' . intval(energyAnomalyRules::param($_kind, 'OutlierFactor', energyAnomalyRules::OUTLIER_FACTOR)) . ' x the median of ' . round($_median, 1) . ' ' . $u . '). It is dropped from the profile so that it does not blind the detector.');
	}

	public static function learned($_name, $_kind, $_rate, $_profile) {
		if (!self::isDebug()) {
			return;
		}
		$u = $_kind->rateUnit();
		log::add('energy', 'debug', '[jump] ' . $_name . ': learned ' . round($_rate, 1) . ' ' . $u . ', profile now ' . count($_profile['days']) . ' day(s), highest ' . round(max($_profile['days']), 1) . ' ' . $u . ', ' . $_profile['active'] . ' active cycle(s)');
	}

	/** What became of an abnormal cycle: kept out of the profile, or accepted as a change of regime. */
	public static function abnormalHandled($_name, $_kind, $_profile, $_accepted, $_recent, $_regimeCount, $_regimeHours) {
		if (!self::isDebug()) {
			return;
		}
		log::add('energy', 'debug', '[jump] ' . $_name . ': abnormal cycle ' . ($_accepted
			? 'accepted as a change of regime (' . count($_recent) . ' abnormal cycles within ' . $_regimeHours . ' h), reference raised to ' . round(max($_profile['days']), 1) . ' ' . $_kind->rateUnit()
			: 'NOT learned (' . count($_recent) . '/' . $_regimeCount . ' abnormal cycles within ' . $_regimeHours . ' h), the reference is unchanged'));
	}

	/**
	 * The warning for an abnormal cycle, with what is needed to explain it: the threshold, the reference, the cron
	 * intervals, the probable cause (rule R11) and the lines that brought the energy, the biggest first.
	 */
	public static function warning($_name, $_kind, $_quantity, $_duration, $_rate, $_verdict, $_margin, $_cron, $_cause, $_lines, $_accepted, $_recent, $_profile, $_regimeHours) {
		$u = $_kind->rateUnit();
		$intervals = (count($_cron['intervals']) > 0) ? implode(', ', $_cron['intervals']) . ' s' : 'none';
		usort($_lines, function ($a, $b) {
			return $b['quantity'] <=> $a['quantity'];
		});
		$details = array();
		foreach ($_lines as $line) {
			if ($line['quantity'] > 0) {
				$details[] = '[' . $line['category'] . ', ' . $line['mode'] . ', ' . $line['key'] . '] ' . round($line['quantity'], 3) . ' ' . $_kind->unit() . ' (' . $line['info'] . ')';
			}
		}
		$what = round($_quantity, 3) . ' ' . $_kind->unit() . ' credited in ' . $_duration . ' s = ' . round($_rate, 1) . ' ' . $u . ', above the threshold of ' . round($_verdict['threshold'], 1) . ' ' . $u . ' (' . $_margin . ' x the highest normal rate of ' . round($_verdict['reference'], 1) . ' ' . $u . ' seen over the last ' . $_verdict['days'] . ' day(s)). Cron: ' . $_cause['cron'] . '; last intervals: ' . $intervals . '. Probable cause: ' . $_cause['cause'] . '. Lines: ' . implode('; ', $details);
		if ($_accepted) {
			log::add('energy', 'warning', 'Consumption regime change accepted for ' . $_name . ': ' . count($_recent) . ' abnormal cycles within ' . $_regimeHours . ' h, so this is no longer an isolated jump; the reference is raised to ' . round(max($_profile['days']), 1) . ' ' . $u . '. Last cycle: ' . $what);
		} else {
			log::add('energy', 'warning', 'Abnormal consumption jump for ' . $_name . ': ' . $what);
		}
	}
}

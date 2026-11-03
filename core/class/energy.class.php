<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class energy extends eqLogic {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Méthodes statiques*************************** */
	public static function getConfEnergyKey($_confEnergy, $_position = 0) {
		$key = '';
		if (isset($_confEnergy['power']) && $_confEnergy['power'] != '') {
			preg_match_all("/#([0-9]*)#/", $_confEnergy['power'], $matches);
			foreach ($matches[1] as $cmd_id) {
				$key .= '#' . $cmd_id . '#';
			}
		}
		if (isset($_confEnergy['consumption']) && $_confEnergy['consumption'] != '') {
			preg_match_all("/#([0-9]*)#/", $_confEnergy['consumption'], $matches);
			foreach ($matches[1] as $cmd_id) {
				$key .= '#' . $cmd_id . '#';
			}
		}
		if ($key === '') {
			$key = $_position;
		}
		return $key;
	}

	/**
	 * Longest gap, in seconds, that the power integration of cron15 accepts to credit at the last power seen.
	 * cron15 runs every 15 minutes: 30 minutes tolerates one missed cycle and a slow run, anything longer
	 * means cron15 was not running and what the equipment did meanwhile is unknown.
	 * The "energyMaxIntegrationGap" setting of the plugin (seconds) overrides it.
	 */
	const INTEGRATION_MAX_GAP = 1800;

	public static function integrationMaxGap() {
		$configured = intval(config::byKey('energyMaxIntegrationGap', 'energy', 0));
		return ($configured > 0) ? $configured : self::INTEGRATION_MAX_GAP;
	}

	/**
	 * Time to credit at the last power seen, between the previous cycle and now.
	 *
	 * - no previous cycle recorded (first run, or the state was lost): nothing can be credited, 0 s;
	 * - clock going backwards (negative gap): 0 s;
	 * - gap longer than the maximum: capped (the surplus is NOT credited, see the comment in cron15()).
	 *
	 * @param mixed $_lastChange timestamp of the previous cycle, '' or null when unknown
	 * @param int $_now timestamp of the current cycle
	 * @return array {seconds: credited seconds, gap: real gap (0 when unknown), capped: bool}
	 */
	public static function integrationElapsed($_lastChange, $_now) {
		if (!is_numeric($_lastChange) || $_lastChange <= 0) {
			return array('seconds' => 0, 'gap' => 0, 'capped' => false);
		}
		$gap = $_now - intval($_lastChange);
		if ($gap <= 0) {
			return array('seconds' => 0, 'gap' => $gap, 'capped' => false);
		}
		$max = self::integrationMaxGap();
		if ($gap > $max) {
			return array('seconds' => $max, 'gap' => $gap, 'capped' => true);
		}
		return array('seconds' => $gap, 'gap' => $gap, 'capped' => false);
	}

	/** Normal period of cron15, in seconds. */
	const CRON_NOMINAL_INTERVAL = 900;

	/** Number of cron15 cycles remembered to compute the intervals shown in the warnings. */
	const CRON_HISTORY_SIZE = 12;

	/*
	 * ADAPTIVE DETECTION OF AN ABNORMAL CONSUMPTION JUMP
	 *
	 * Nothing here depends on the kind or the size of the equipment: a 0.8 W standby, a 2 kW radiator, a house
	 * meter and a 80 kW warehouse are all judged against what THEY did before.
	 *
	 * For each equipment and each cron15 cycle:
	 *   1. the rate of the cycle is the energy credited divided by the REAL duration since the previous cycle
	 *      (so a counter that legitimately catches up after a cron stop is not a jump: 50 kWh over 25 h is 2 kW);
	 *   2. the reference is the highest rate of the normal cycles of the last JUMP_WINDOW_DAYS days, kept as one
	 *      value per day so the stored profile stays tiny;
	 *   3. the cycle is abnormal when its rate exceeds JUMP_MARGIN x the reference;
	 *   4. an abnormal cycle is NOT learned, otherwise a jump would raise the reference and hide the next one.
	 *
	 * An equipment is only judged once it has shown JUMP_MIN_ACTIVE cycles with a non zero rate. Without that,
	 * a heater that stayed at 0 all summer would have a reference of 0 and its first cold day would be a "jump"
	 * (the offline simulation of this rule gave 740 false alerts on such a load, and none with this guard).
	 *
	 * Limit: a sudden and lasting change of regime (the load really triples overnight) is flagged once, then
	 * learned. The warning only reports, the consumption is credited as before.
	 */

	/** Window, in days, over which the highest normal rate of an equipment is remembered. */
	const JUMP_WINDOW_DAYS = 30;

	/** Number of cycles with a non zero rate an equipment must have shown before its cycles are judged. */
	const JUMP_MIN_ACTIVE = 24;

	/** A cycle is abnormal above this multiple of the highest normal rate of the window. */
	const JUMP_MARGIN = 1.5;

	/**
	 * How much one cycle can raise the reference, as a multiple of it. A normal cycle above the reference is learned
	 * only up to reference x JUMP_LEARN_STEP: without this cap, a cycle just under the threshold would be learned in
	 * full, raise the reference by up to JUMP_MARGIN and let the next, stronger cycle through (a staircase of jumps
	 * that the detector would follow one step at a time). A real change of regime is still followed, step by step.
	 */
	const JUMP_LEARN_STEP = 1.1;

	/**
	 * Abnormal cycles are not learned (a jump must not raise its own threshold). But a LASTING change of regime
	 * (heating season starting, a new appliance) would then be flagged on every cycle for the whole window.
	 * When JUMP_REGIME_COUNT abnormal cycles happen within JUMP_REGIME_HOURS hours, it is no longer an isolated
	 * jump: the highest of them becomes the new reference and a "regime change" warning says so.
	 * Limit: a source that keeps jumping 3 times in a few hours is accepted the same way (the warnings before it remain).
	 */
	const JUMP_REGIME_COUNT = 3;
	const JUMP_REGIME_HOURS = 6;

	/**
	 * Records an abnormal cycle in the profile. Returns {0: the profile, 1: true when the cycle is accepted as a change
	 * of regime, 2: the abnormal cycles of the recent period, the current one included}.
	 */
	public static function jumpAbnormal($_profile, $_rate, $_now) {
		$since = $_now - intval(self::jumpParam('RegimeHours', self::JUMP_REGIME_HOURS) * 3600);
		$recent = array();
		foreach ($_profile['abnormal'] as $entry) {
			if ($entry[0] >= $since && $entry[0] < $_now) {
				$recent[] = $entry;
			}
		}
		$recent[] = array($_now, round($_rate, 3));
		if (count($recent) >= intval(self::jumpParam('RegimeCount', self::JUMP_REGIME_COUNT))) {
			$top = 0;
			foreach ($recent as $entry) {
				$top = max($top, $entry[1]);
			}
			$day = date('Y-m-d', $_now);
			$_profile['days'][$day] = max(isset($_profile['days'][$day]) ? $_profile['days'][$day] : 0, $top);
			ksort($_profile['days']);
			$_profile['active']++;
			$_profile['abnormal'] = array();
			return array($_profile, true, $recent);
		}
		$_profile['abnormal'] = $recent;
		return array($_profile, false, $recent);
	}

	/** Tuning value of the detector: the "energyJump<Name>" setting of the plugin when it is a positive number, else the default. */
	private static function jumpParam($_name, $_default) {
		$configured = floatval(config::byKey('energyJump' . $_name, 'energy', 0));
		return ($configured > 0) ? $configured : $_default;
	}

	/** Traces of the algorithms (jump detection, ...) follow the log level of the plugin: only in debug. */
	public static function isDebugEnabled() {
		return log::getLogLevel('energy') <= 100;
	}

	/**
	 * Profile learned for an equipment: {days: {YYYY-MM-DD: highest normal rate of the day}, active: number of cycles
	 * with a non zero rate}. Stored in the configuration of the equipment, like the integration state.
	 * Anything unreadable in the stored value is ignored.
	 */
	public static function jumpProfile($_energy) {
		$raw = $_energy->getConfiguration('jumpProfile', '');
		$data = is_array($raw) ? $raw : json_decode((string) $raw, true);
		$profile = array('days' => array(), 'active' => 0, 'abnormal' => array());
		if (!is_array($data)) {
			return $profile;
		}
		if (isset($data['active']) && is_numeric($data['active']) && $data['active'] > 0) {
			$profile['active'] = intval($data['active']);
		}
		if (isset($data['abnormal']) && is_array($data['abnormal'])) {
			foreach ($data['abnormal'] as $entry) {
				if (is_array($entry) && count($entry) == 2 && is_numeric($entry[0]) && is_numeric($entry[1]) && $entry[0] > 0 && $entry[1] > 0) {
					$profile['abnormal'][] = array(intval($entry[0]), floatval($entry[1]));
				}
			}
		}
		if (isset($data['days']) && is_array($data['days'])) {
			foreach ($data['days'] as $day => $rate) {
				if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && is_numeric($rate) && $rate > 0) {
					$profile['days'][$day] = floatval($rate);
				}
			}
		}
		return $profile;
	}

	/**
	 * Judges a rate against a profile.
	 *
	 * @return array {established: the profile is solid enough to judge, reference: highest normal rate of the window,
	 *                threshold: reference x margin (null when not established), abnormal: bool, minActive: cycles required}
	 */
	public static function jumpJudge($_profile, $_rate) {
		$minActive = intval(self::jumpParam('MinActive', self::JUMP_MIN_ACTIVE));
		$reference = (count($_profile['days']) > 0) ? max($_profile['days']) : 0;
		$verdict = array('established' => false, 'reference' => $reference, 'threshold' => null, 'abnormal' => false, 'minActive' => $minActive, 'days' => count($_profile['days']));
		if ($_profile['active'] < $minActive || $reference <= 0) {
			return $verdict;
		}
		$verdict['established'] = true;
		$verdict['threshold'] = $reference * self::jumpParam('Margin', self::JUMP_MARGIN);
		$verdict['abnormal'] = $_rate > $verdict['threshold'];
		return $verdict;
	}

	/** Adds a normal cycle to the profile: keeps the highest rate of the day and forgets the days out of the window. */
	public static function jumpLearn($_profile, $_rate, $_now) {
		if ($_rate <= 0) {
			return $_profile;
		}
		// Cap the rise of the reference (see JUMP_LEARN_STEP), but only once the profile is established: while it is
		// being built the first cycles must set the level freely, otherwise a small first cycle (a heater that starts
		// at the end of a cycle) would lock the reference far below the real level and flag every following cycle.
		$reference = (count($_profile['days']) > 0) ? max($_profile['days']) : 0;
		if ($reference > 0 && $_profile['active'] >= intval(self::jumpParam('MinActive', self::JUMP_MIN_ACTIVE))) {
			$_rate = min($_rate, $reference * self::jumpParam('LearnStep', self::JUMP_LEARN_STEP));
		}
		$day = date('Y-m-d', $_now);
		$_profile['days'][$day] = max(isset($_profile['days'][$day]) ? $_profile['days'][$day] : 0, round($_rate, 3));
		$_profile['active']++;
		$oldest = date('Y-m-d', $_now - intval(self::jumpParam('WindowDays', self::JUMP_WINDOW_DAYS)) * 86400);
		foreach (array_keys($_profile['days']) as $storedDay) {
			if ($storedDay < $oldest) {
				unset($_profile['days'][$storedDay]);
			}
		}
		ksort($_profile['days']);
		return $_profile;
	}

	/** Timestamps of the last cron15 cycles, oldest first. Anything unreadable in the stored value is ignored. */
	public static function cronHistory() {
		$raw = config::byKey('cronHistory', 'energy', '');
		$list = is_array($raw) ? $raw : json_decode((string) $raw, true);
		if (!is_array($list)) {
			return array();
		}
		$history = array();
		foreach ($list as $timestamp) {
			if (is_numeric($timestamp) && $timestamp > 0) {
				$history[] = intval($timestamp);
			}
		}
		sort($history);
		return $history;
	}

	/**
	 * Records a cron15 cycle and describes how it follows the previous ones.
	 *
	 * This is the only way the plugin can notice that its cron stopped: it cannot run while the cron is stopped,
	 * so the stop is seen by the first cycle that follows it, from the time elapsed since the previous one.
	 *
	 * @param int $_now timestamp of the current cycle
	 * @return array {interval: seconds since the previous cycle (null when unknown), intervals: the last intervals,
	 *                oldest first, late: true when the interval is longer than integrationMaxGap()}
	 */
	public static function cronRecord($_now) {
		$history = self::cronHistory();
		$previous = count($history) > 0 ? end($history) : null;
		if ($previous !== null && $_now < $previous) {
			// Clock set back: the old references are meaningless, start again from now
			$history = array();
			$previous = null;
		}
		$interval = ($previous !== null && $_now > $previous) ? $_now - $previous : null;
		$intervals = array();
		for ($i = 1; $i < count($history); $i++) {
			if ($history[$i] > $history[$i - 1]) {
				$intervals[] = $history[$i] - $history[$i - 1];
			}
		}
		if ($interval !== null) {
			$intervals[] = $interval;
		}
		if ($previous === null || $_now > $previous) {
			$history[] = $_now;
		}
		config::save('cronHistory', json_encode(array_slice($history, -self::CRON_HISTORY_SIZE)), 'energy');
		return array(
			'interval' => $interval,
			'intervals' => array_slice($intervals, -5),
			'late' => ($interval !== null && $interval > self::integrationMaxGap()),
		);
	}

	/** Traces the cycle that just started: a warning when the cron ran late, a debug line otherwise. */
	private static function logCronCycle($_cron) {
		if ($_cron['late']) {
			log::add('energy', 'warning', 'cron15 ran late: ' . $_cron['interval'] . ' s since the previous cycle (expected about ' . self::CRON_NOMINAL_INTERVAL . ' s, tolerated up to ' . self::integrationMaxGap() . ' s). Last intervals: ' . implode(', ', $_cron['intervals']) . ' s. The cron was not running between these two cycles.');
			return;
		}
		log::add('energy', 'debug', '[cron] cycle started' . ($_cron['interval'] === null ? ' (first recorded cycle)' : ', ' . $_cron['interval'] . ' s since the previous one (expected about ' . self::CRON_NOMINAL_INTERVAL . ' s)'));
	}

	/**
	 * Warns when one equipment is credited more energy in a single cycle than it can plausibly consume,
	 * and gives what is needed to explain it: the cron intervals and the lines that brought the energy.
	 *
	 * Case handled: a jump is either (a) a cron that stopped, whose gap lands in one cycle, or (b) a source
	 * that jumped (an energy counter that skipped, a wrong power). The intervals tell the two apart:
	 * a long interval points to the cron, normal intervals point to the source.
	 * Production example: a Bureau radiator credited 50 kWh in one cycle after 25 h without cron15.
	 *
	 * @param eqLogic $_energy the equipment
	 * @param float $_kwh energy credited to it in this cycle
	 * @param array $_lines {key, category, mode, kwh, info} for each line that brought energy
	 * @param array $_cron result of cronRecord()
	 * @return bool true when a warning was logged
	 */
	public static function warnConsumptionJump($_energy, $_kwh, $_lines, $_cron, $_now = null) {
		$now = ($_now !== null) ? $_now : time();
		$name = $_energy->getHumanName();
		$debug = self::isDebugEnabled();

		// 1. The rate of the cycle: energy credited over the real time since the previous cycle.
		// Without a previous cycle the duration is unknown: the nominal period is used, and the debug line says so.
		$durationKnown = ($_cron['interval'] !== null && $_cron['interval'] > 0);
		$duration = $durationKnown ? $_cron['interval'] : self::CRON_NOMINAL_INTERVAL;
		$rate = ($_kwh * 1000) / ($duration / 3600);

		// 2. Judge it against what this equipment did before
		$profile = self::jumpProfile($_energy);
		$verdict = self::jumpJudge($profile, $rate);

		if ($debug) {
			log::add('energy', 'debug', '[jump] ' . $name . ': ' . round($_kwh, 3) . ' kWh over ' . $duration . ' s' . ($durationKnown ? '' : ' (nominal, no previous cycle)') . ' = ' . round($rate, 1) . ' W; ' . ($verdict['established'] ? 'reference ' . round($verdict['reference'], 1) . ' W over ' . count($profile['days']) . ' day(s), threshold ' . round($verdict['threshold'], 1) . ' W -> ' . ($verdict['abnormal'] ? 'ABNORMAL' : 'normal') : 'profile not established yet (' . $profile['active'] . '/' . $verdict['minActive'] . ' active cycles, reference ' . round($verdict['reference'], 1) . ' W) -> not judged'));
		}

		// 3. A normal cycle is learned; an abnormal one never is, or it would raise the reference and hide the next jump
		if (!$verdict['abnormal']) {
			$learned = self::jumpLearn($profile, $rate, $now);
			if ($learned !== $profile) {
				$_energy->setConfiguration('jumpProfile', json_encode($learned));
				if ($debug) {
					log::add('energy', 'debug', '[jump] ' . $name . ': learned ' . round($rate, 1) . ' W, profile now ' . count($learned['days']) . ' day(s), highest ' . round(max($learned['days']), 1) . ' W, ' . $learned['active'] . ' active cycle(s)');
				}
			}
			return false;
		}
		list($profile, $accepted, $recent) = self::jumpAbnormal($profile, $rate, $now);
		$_energy->setConfiguration('jumpProfile', json_encode($profile));
		$regimeCount = intval(self::jumpParam('RegimeCount', self::JUMP_REGIME_COUNT));
		$regimeHours = self::jumpParam('RegimeHours', self::JUMP_REGIME_HOURS);
		if ($debug) {
			log::add('energy', 'debug', '[jump] ' . $name . ': abnormal cycle ' . ($accepted
				? 'accepted as a change of regime (' . count($recent) . ' abnormal cycles within ' . $regimeHours . ' h), reference raised to ' . round(max($profile['days']), 1) . ' W'
				: 'NOT learned (' . count($recent) . '/' . $regimeCount . ' abnormal cycles within ' . $regimeHours . ' h), the reference is unchanged'));
		}

		// 4. Explain it with the cron intervals and the lines that brought the energy
		if ($_cron['interval'] === null) {
			$cronText = 'no previous cycle recorded, the intervals are unknown';
			$cause = 'cannot tell whether the cron was stopped';
		} else {
			$cronText = $_cron['interval'] . ' s since the previous cycle (expected about ' . self::CRON_NOMINAL_INTERVAL . ' s)';
			if ($_cron['late']) {
				$cause = 'the cron was not running for a while, so what accumulated meanwhile lands in this single cycle';
			} else {
				$cause = 'the cron intervals are normal, so the jump comes from the source of the lines below (a counter that skipped or a wrong power)';
			}
		}
		$intervals = (count($_cron['intervals']) > 0) ? implode(', ', $_cron['intervals']) . ' s' : 'none';
		usort($_lines, function ($a, $b) {
			return $b['kwh'] <=> $a['kwh'];
		});
		$details = array();
		foreach ($_lines as $line) {
			if ($line['kwh'] > 0) {
				$details[] = '[' . $line['category'] . ', ' . $line['mode'] . ', ' . $line['key'] . '] ' . round($line['kwh'], 3) . ' kWh (' . $line['info'] . ')';
			}
		}
		$what = round($_kwh, 3) . ' kWh credited in ' . $duration . ' s = ' . round($rate, 1) . ' W, above the threshold of ' . round($verdict['threshold'], 1) . ' W (' . self::jumpParam('Margin', self::JUMP_MARGIN) . ' x the highest normal rate of ' . round($verdict['reference'], 1) . ' W seen over the last ' . $verdict['days'] . ' day(s)). Cron: ' . $cronText . '; last intervals: ' . $intervals . '. Probable cause: ' . $cause . '. Lines: ' . implode('; ', $details);
		if ($accepted) {
			log::add('energy', 'warning', 'Consumption regime change accepted for ' . $name . ': ' . count($recent) . ' abnormal cycles within ' . $regimeHours . ' h, so this is no longer an isolated jump; the reference is raised to ' . round(max($profile['days']), 1) . ' W. Last cycle: ' . $what);
		} else {
			log::add('energy', 'warning', 'Abnormal consumption jump for ' . $name . ': ' . $what);
		}
		return true;
	}

	/**
	 * Tells if an hour (format Gi, e.g. 2230) is inside a range, handling ranges crossing midnight (e.g. 2200 -> 0600).
	 * An unset range (negative value, or start equal to end) never matches.
	 */
	public static function isInHourRange($_hourtime, $_start, $_end) {
		$hourtime = intval($_hourtime);
		$start = intval($_start);
		$end = intval($_end);
		if ($start < 0 || $end < 0 || $start == $end) {
			return false;
		}
		if ($start < $end) {
			return ($hourtime >= $start && $hourtime <= $end);
		}
		return ($hourtime >= $start || $hourtime <= $end);
	}

	/** Overrides the current time (timestamp) of cron15, only meant for the unit tests. */
	public static $nowOverride = null;

	/** Forces the performance traces on or off (null = follow the log level of the plugin), only meant for the unit tests. */
	public static $perfOverride = null;

	/** @var array Counters of the current getGraph() run, see perfStart() / perfStop() */
	private static $perfStats = array();

	/**
	 * Performance traces are only produced when the log level of the plugin is "debug",
	 * so they cost nothing in a normal run.
	 */
	public static function isPerfEnabled() {
		if (self::$perfOverride !== null) {
			return self::$perfOverride;
		}
		return log::getLogLevel('energy') <= 100;
	}

	/** Starts a timer. Returns null when the traces are disabled, which perfStop() ignores. */
	private static function perfStart() {
		return self::isPerfEnabled() ? microtime(true) : null;
	}

	/**
	 * Stops a timer, logs the line and adds the duration to the counters of the run.
	 *
	 * @param float|null $_start value returned by perfStart()
	 * @param string $_group counter name (e.g. "history power", "sql energy")
	 * @param string $_label what was measured, shown in the log
	 * @param int|null $_rows number of rows returned, when relevant
	 */
	private static function perfStop($_start, $_group, $_label, $_rows = null) {
		if ($_start === null) {
			return;
		}
		$ms = (microtime(true) - $_start) * 1000;
		if (!isset(self::$perfStats[$_group])) {
			self::$perfStats[$_group] = array('calls' => 0, 'ms' => 0, 'rows' => 0);
		}
		self::$perfStats[$_group]['calls']++;
		self::$perfStats[$_group]['ms'] += $ms;
		self::$perfStats[$_group]['rows'] += intval($_rows);
		log::add('energy', 'debug', '[perf] ' . $_label . ': ' . round($ms) . ' ms' . ($_rows !== null ? ', ' . intval($_rows) . ' rows' : ''));
	}

	/** Logs the counters of the run, one line per group, slowest first. */
	private static function perfSummary($_label, $_start) {
		if ($_start === null) {
			return;
		}
		$stats = self::$perfStats;
		uasort($stats, function ($a, $b) {
			return $b['ms'] <=> $a['ms'];
		});
		foreach ($stats as $group => $stat) {
			log::add('energy', 'debug', '[perf]   ' . $group . ': ' . $stat['calls'] . ' calls, ' . round($stat['ms']) . ' ms, ' . $stat['rows'] . ' rows');
		}
		log::add('energy', 'debug', '[perf] ' . $_label . ' done in ' . round((microtime(true) - $_start) * 1000) . ' ms');
	}

	public static function cron15() {
		$cycleNow = (self::$nowOverride !== null) ? self::$nowOverride : time();
		$datetime = date('Y-m-d H:i:00', $cycleNow);
		$powerTotal = 0;
		// Remember this cycle and trace how it follows the previous ones: it is the only evidence that the cron stopped
		$cron = self::cronRecord($cycleNow);
		self::logCronCycle($cron);
		foreach (self::byType('energy',true) as $energy) {
			if ($energy->getConfiguration('type') == 'electricity') {
				$totalConsumption = 0;
				$power = 0;
				$consumptions = array('other' => 0, 'light' => 0, 'multimedia' => 0, 'heating' => 0, 'electrical' => 0, 'automatism' => 0, 'hc' => 0, 'hp' => 0);
				// What each line of the equipment brings in this cycle, to explain a consumption jump (see warnConsumptionJump())
				$jumpLines = array();
				$position = 0;
				foreach ($energy->getConfiguration('confEnergy') as $confEnergy) {
					$key = self::getConfEnergyKey($confEnergy, $position);
					if ($confEnergy['power'] != '') {
						$value = floatval(jeedom::evaluateExpression($confEnergy['power']));
						$power += $value;
						if ($confEnergy['consumption'] == '') {
							// Line with a power but no energy counter: the consumption is the integral of the power over time.
							// Each cycle credits "last power seen x time elapsed since the previous cycle".
							//
							// Case handled here: when cron15 stops for a long time (Jeedom restart, plugin or cron not running...),
							// the first cycle afterwards used to apply the last power seen BEFORE the stop to the WHOLE gap.
							// Example seen in production: a 2000 W radiator last seen "on", cron15 silent for 25 h, so the first
							// cycle credited 2000 W x 25 h = 50 kWh (and about 11 EUR) that nobody consumed.
							// We cannot know what happened during the gap, so the credited time is capped (see integrationElapsed()).
							$nowtime = (self::$nowOverride !== null) ? self::$nowOverride : strtotime('now');
							$lastChange = $energy->getConfiguration('lastChangeTime' . $key);
							$lastValue = $energy->getConfiguration('lastValue' . $key);
							$elapsed = self::integrationElapsed($lastChange, $nowtime);
							if ($elapsed['capped']) {
								log::add('energy', 'warning', 'Power integration gap capped for ' . $energy->getHumanName() . ' (' . $key . ') : ' . $elapsed['gap'] . ' s since the last cycle, only ' . $elapsed['seconds'] . ' s counted at ' . $lastValue . ' W');
							}
							$lineKwh = (($lastValue * $elapsed['seconds']) / 3600) / 1000;
							$consumptions[$confEnergy['category']] += $lineKwh;
							$jumpLines[] = array('key' => $key, 'category' => $confEnergy['category'], 'mode' => 'integrated power', 'kwh' => $lineKwh, 'info' => floatval($lastValue) . ' W over ' . $elapsed['seconds'] . ' s' . ($elapsed['capped'] ? ' (capped from ' . $elapsed['gap'] . ' s)' : ''));
							$energy->setConfiguration('lastChangeTime' . $key, $nowtime);
							$energy->setConfiguration('lastValue' . $key, $value);
						}
					}
					if ($confEnergy['consumption'] != '') {
						$previous = $energy->getConfiguration('previous' . $key, 0);
						$rawValue = jeedom::evaluateExpression($confEnergy['consumption']);
						$value = floatval($rawValue);
						if (($value - $previous) >= 0) {
							$consumptions[$confEnergy['category']] += ($value - $previous);
							$jumpLines[] = array('key' => $key, 'category' => $confEnergy['category'], 'mode' => 'index delta', 'kwh' => $value - $previous, 'info' => 'index ' . $previous . ' => ' . $value);
							$energy->setConfiguration('previous' . $key, $value);
						} elseif (is_numeric($rawValue)) {
							// Counter was reset or replaced: realign on the new index, otherwise nothing is counted until it exceeds the old one
							log::add('energy', 'debug', 'Counter reset detected for ' . $energy->getHumanName() . ' (' . $key . ') : ' . $previous . ' => ' . $value);
							$energy->setConfiguration('previous' . $key, $value);
						}
					}
					$position++;
				}
				// Abnormal jump: more energy credited to this equipment in one cycle than it can plausibly consume
				self::warnConsumptionJump($energy, array_sum($consumptions), $jumpLines, $cron, $cycleNow);
				$energy->save();
				foreach ($consumptions as $name => $consumption) {
					// Not "== 0": an unset option is '' and '' == 0 is false since PHP 8
					if ($energy->getConfiguration('totalCounter') != 1 && $consumption > 0) {
						$cmd = $energy->getCmd(null, 'consumption' . ucfirst($name));
						if (is_object($cmd)) {
							$previous = $cmd->execCmd();
							$cmd->setCollectDate($datetime);
							$cmd->event($consumption + $previous);
						}
					}
					$totalConsumption += $consumption;
				}
				if ($consumptions['hc'] > 0) {
					$cmd = $energy->getCmd(null, 'consumptionHc');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						log::add('energy', 'debug', 'HC : ' . $consumptions['hc'] . '+' . $previous);
						$cmd->event($consumptions['hc'] + $previous);
					}
				}
				if ($consumptions['hp'] > 0) {
					$cmd = $energy->getCmd(null, 'consumptionHp');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						log::add('energy', 'debug', 'HP : ' . $consumptions['hp'] . '+' . $previous);
						$cmd->event($consumptions['hp'] + $previous);
					}
				}
				if ($totalConsumption > 0) {
					$cmd = $energy->getCmd(null, 'consumptionTotal');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						log::add('energy', 'debug', 'Total : ' . $totalConsumption . '+' . $previous);
						$cmd->event($totalConsumption + $previous);
					}
					$calculCost = 0;
					if (config::byKey('rateMode', 'energy') == 'variable') {
						if ($consumptions['hp'] != 0 || $consumptions['hc'] != 0) {
							$calculCost = $consumptions['hp'] * str_replace(',', '.', config::byKey('rateHp', 'energy')) + $consumptions['hc'] * str_replace(',', '.', config::byKey('rateHc', 'energy'));
						} else {
							$rateHour = array(
								'startHp' => intval(ltrim(str_replace(':', '', config::byKey('startHc', 'energy', -1)), '0')),
								'endHp' => intval(ltrim(str_replace(':', '', config::byKey('endHc', 'energy', -1)), '0')),
								'startHp2' => intval(ltrim(str_replace(':', '', config::byKey('startHc2', 'energy',-1)), '0')),
								'endHp2' => intval(ltrim(str_replace(':', '', config::byKey('endHc2', 'energy', -1)), '0')),
								'startHp3' => intval(ltrim(str_replace(':', '', config::byKey('startHc3', 'energy', -1)), '0')),
								'endHp3' => intval(ltrim(str_replace(':', '', config::byKey('endHc3', 'energy', -1)), '0')),
								'rateHp' => str_replace(',', '.', config::byKey('rateHp', 'energy', 0)),
								'rateHc' => str_replace(',', '.', config::byKey('rateHc', 'energy', 0)),
							);
							$hourtime = date('Gi', strtotime($datetime));
							if (self::isInHourRange($hourtime, $rateHour['startHp'], $rateHour['endHp'])) {
								$calculCost = $totalConsumption * $rateHour['rateHc'];
							}

							if (self::isInHourRange($hourtime, $rateHour['startHp2'], $rateHour['endHp2'])) {
								$calculCost = $totalConsumption * $rateHour['rateHc'];
							}

							if (self::isInHourRange($hourtime, $rateHour['startHp3'], $rateHour['endHp3'])) {
								$calculCost = $totalConsumption * $rateHour['rateHc'];
							}
							if ($calculCost == 0) {
								$calculCost = $totalConsumption * $rateHour['rateHp'];
							}
						}
					} else {
						$cost = jeedom::evaluateExpression(str_replace(',', '.', config::byKey('rate', 'energy', 0)));
						$calculCost = $totalConsumption * $cost;
					}
					$cmd = $energy->getCmd(null, 'cost');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						$cmd->event($calculCost + $previous);
					}
				}
				$cmd = $energy->getCmd(null, 'power');
				if (is_object($cmd)) {
					$cmd->setCollectDate($datetime);
					$cmd->event($power);
				}
			} else {
				$totalConsumption = 0;
				$position = 0;
				foreach ($energy->getConfiguration('confEnergy') as $confEnergy) {
					$key = self::getConfEnergyKey($confEnergy, $position);
					$previous = $energy->getConfiguration('previous' . $key, 0);
					$rawValue = evaluate(cmd::cmdToValue($confEnergy['consumption']));
					$value = floatval($rawValue);
					if (($value - $previous) >= 0) {
						$totalConsumption += ($value - $previous);
						$energy->setConfiguration('previous' . $key, $value);
					} elseif (is_numeric($rawValue)) {
						// Counter was reset or replaced: realign on the new index
						log::add('energy', 'debug', 'Counter reset detected for ' . $energy->getHumanName() . ' (' . $key . ') : ' . $previous . ' => ' . $value);
						$energy->setConfiguration('previous' . $key, $value);
					}
					$position++;
				}
				$energy->save();
				if ($totalConsumption > 0) {
					$cmd = $energy->getCmd(null, 'consumptionTotal');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						$cmd->event($totalConsumption + $previous);
					}
					$cost = jeedom::evaluateExpression(str_replace(',', '.', config::byKey('rate' . ucfirst($energy->getConfiguration('type')), 'energy', 0)));
					$calculCost = $totalConsumption * $cost;
					$cmd = $energy->getCmd(null, 'cost');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						$cmd->event($calculCost + $previous);
					}
				}
			}
		}
	}

	public static function cronDaily() {
		sleep(60);
		self::resetDailyValues();
	}

	public static function resetDailyValues() {
		$datetime = date('Y-m-d 00:00:00');
		foreach (self::byType('energy',true) as $energy) {
			foreach (array('cost', 'consumptionOther', 'consumptionLight', 'consumptionMultimedia', 'consumptionAutomatism', 'consumptionElectrical', 'consumptionHeating', 'consumptionTotal', 'consumptionHp', 'consumptionHc') as $logicalId) {
				$cmd = $energy->getCmd(null, $logicalId);
				if (is_object($cmd)) {
					$cmd->setCollectDate($datetime);
					$cmd->event(0);
				}
			}
		}
	}

	public static function getGraph($_startTime, $_endTime, $_object_id, $_energyType = 'electricity') {
		$return = array(
			'category' => array('other' => array(), 'light' => array(), 'multimedia' => array(), 'heating' => array(), 'electrical' => array(), 'automatism' => array()),
			'cost' => array(),
			'totalConsumption' => array(),
			'translation' => array('other' => __('Autre', __FILE__), 'light' => __('Lumière', __FILE__), 'multimedia' => __('Multimedia', __FILE__), 'heating' => __('Chauffage', __FILE__), 'electrical' => __('Electroménager', __FILE__), 'automatism' => __('Automatisme', __FILE__)),
			'object' => array(),
			'total' => array('power' => 0, 'consumption' => 0, 'cost' => 0),
			'consumptionUnite' => 'kWh',
			'power' => array(),
		);
		if ($_energyType == 'water') {
			$return['consumptionUnite'] = 'L';
		}
		self::$perfStats = array();
		$perfRun = self::perfStart();
		if ($perfRun !== null) {
			log::add('energy', 'debug', '[perf] getGraph start: object ' . $_object_id . ', type ' . $_energyType . ', from ' . $_startTime . ' to ' . $_endTime);
		}
		$perfStep = self::perfStart();
		$object = jeeObject::byId($_object_id);
		if (!is_object($object)) {
			throw new Exception(__('Objet non trouvé. Vérifiez l\'id : ', __FILE__) . $_object_id);
		}
		$objects = $object->getChilds();
		$objects[] = $object;
		self::perfStop($perfStep, 'objects tree', 'object tree (' . count($objects) . ' objects)');

		foreach ($objects as $object) {
			$return['object'][$object->getName()] = array();
			$return['power'][$object->getName()] = array();
			$perfStep = self::perfStart();
			$energies = $object->getEqLogic(true, false, 'energy');
			self::perfStop($perfStep, 'equipments lookup', 'object "' . $object->getName() . '" equipments (' . count($energies) . ')');
			foreach ($energies as $energy) {
				if ($energy->getConfiguration('type') != $_energyType) {
					continue;
				}
				if (!$energy->hasRight('r')) {
					continue;
				}
				$cmd = $energy->getCmd(null, 'power');
				if (is_object($cmd)) {
					$return['total']['power'] += $cmd->execCmd();
					$perfStep = self::perfStart();
					$powerHistory = $cmd->getHistory($_startTime, date('Y-m-d 23:59:59', strtotime($_endTime)));
					self::perfStop($perfStep, 'history power', 'equipment ' . $energy->getId() . ' "' . $energy->getName() . '" power history', is_array($powerHistory) ? count($powerHistory) : null);
					foreach ($powerHistory as $value) {
						$datetime = floatval(strtotime($value->getDatetime() . " UTC")) * 1000;
						if (isset($return['power'][$object->getName()][$datetime])) {
							$return['power'][$object->getName()][$datetime][1] += floatval($value->getValue());
						} else {
							$return['power'][$object->getName()][$datetime] = array($datetime, floatval($value->getValue()));
						}
					}
				}




				//log::add('energy', 'debug', '-------TOTAL CONSOMMATION------- => ' . $object->getName());
				// One set of SQL queries per equipment: both loops below read the same data
				$perfStep = self::perfStart();
				$energyData = $energy->getData($_startTime, $_endTime);
				if ($perfStep !== null) {
					$perfRows = 0;
					foreach ($energyData as $perfValues) {
						$perfRows += is_array($perfValues) ? count($perfValues) : 0;
					}
					self::perfStop($perfStep, 'sql energy (getData)', 'equipment ' . $energy->getId() . ' "' . $energy->getName() . '" getData', $perfRows);
				}
				foreach ($energyData as $key => $values) {
					if ($key != 'consumptionTotal') {
						continue;
					}
					foreach ($values as $value) {
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						//log::add('energy', 'debug', '   > date -' . $value[0]);
						if (isset($return['totalConsumption'][$value[0]])) {
							$return['totalConsumption'][$value[0]][1] += floatval($value[1]);
							//log::add('energy', 'debug', '   > update -' . strval($return['totalConsumption'][$value[0]][1]));
						} else {
							$return['totalConsumption'][$value[0]] = array($value[0], floatval($value[1]));
							//log::add('energy', 'debug', '   > New -' . strval($value[1]));
						}
					}
				}
				//log::add('energy', 'debug', '______________________________________');

				
				if ($energy->getConfiguration('totalCounter') == 1) {
					continue;
				}
				foreach ($energyData as $key => $values) {
					if ($key == 'consumptionTotal') {
						continue;
					}
					foreach ($values as $value) {
						//log::add('energy', 'debug', '- key : ' . $object->getName() . ' ' . $key);
						
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;

						$date = new DateTime();
						$date->setTimestamp($value[0]/1000);
						$date->setTimezone(new DateTimeZone('UTC'));

						if ($key == 'cost') {
							$return['total']['cost'] += floatval($value[1]);
							if (isset($return['cost'][$value[0]])) {
								$return['cost'][$value[0]][1] += floatval($value[1]);
							} else {
								$return['cost'][$value[0]] = array($value[0], floatval($value[1]));
							}
							continue;
						}

						//log::add('energy', 'debug', '- ' . $object->getName() . ' - date : ' . $date->format('Y-m-d H:i:s') . ' conso : ' . strval($value[1]));

						$return['total']['consumption'] += floatval($value[1]);
						if (isset($return['category'][$key][$value[0]])) {
							$return['category'][$key][$value[0]][1] += floatval($value[1]);
						} else {
							$return['category'][$key][$value[0]] = array($value[0], floatval($value[1]));
						}
						if (isset($return['object'][$object->getName()][$value[0]])) {
							$return['object'][$object->getName()][$value[0]][1] += floatval($value[1]);
						} else {
							$return['object'][$object->getName()][$value[0]] = array($value[0], floatval($value[1]));
						}
					}

				}
			}
		}

		$perfStep = self::perfStart();
		$counterCandidates = $object->getEqLogic(true, false, 'energy');
		self::perfStop($perfStep, 'equipments lookup', 'total counter lookup (' . count($counterCandidates) . ' equipments)');
		foreach ($counterCandidates as $energy) {
			if ($energy->getConfiguration('type') == $_energyType && $energy->getConfiguration('totalCounter') == 1) {
				$counterConsumption = array();
				$counterConsumptionSum = 0;
				$counterCost = array();
				$counterCostSum = 0;
				$cmd = $energy->getCmd(null, 'consumptionTotal');
				if (is_object($cmd)) {
					$perfStep = self::perfStart();
					$counterHistory = $cmd->getHistoryEnergy($_startTime, $_endTime);
					self::perfStop($perfStep, 'sql energy (total counter)', 'total counter ' . $energy->getId() . ' consumption', is_array($counterHistory) ? count($counterHistory) : null);
					foreach ($counterHistory as $value) {
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;
						if (isset($counterConsumption[$value[0]])) {
							$counterConsumption[$value[0]][1] += floatval($value[1]);
						} else {
							$counterConsumption[$value[0]] = array($value[0], floatval($value[1]));
						}
						$counterConsumptionSum += floatval($value[1]);
					}
				}
				$cmd = $energy->getCmd(null, 'cost');
				if (is_object($cmd)) {
					$perfStep = self::perfStart();
					$counterCostHistory = $cmd->getHistoryEnergy($_startTime, $_endTime);
					self::perfStop($perfStep, 'sql energy (total counter)', 'total counter ' . $energy->getId() . ' cost', is_array($counterCostHistory) ? count($counterCostHistory) : null);
					foreach ($counterCostHistory as $value) {
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;
						if (isset($counterCost[$value[0]])) {
							$counterCost[$value[0]][1] += floatval($value[1]);
						} else {
							$counterCost[$value[0]] = array($value[0], floatval($value[1]));
						}
						$counterCostSum += floatval($value[1]);
					}
				}
				// The total counter replaces the sums of the equipments only when it recorded something over the period.
				// A counter whose source is down reads 0 everywhere: it must not blank the consumption and cost curves.
				if (count($counterConsumption) > 0) {
					$return['totalConsumption'] = $counterConsumption;
					$return['total']['consumption'] = $counterConsumptionSum;
				}
				if (count($counterCost) > 0) {
					$return['cost'] = $counterCost;
					$return['total']['cost'] = $counterCostSum;
				}
				break;
			}
		}
		if (isset($return['totalConsumption'])) {
			// Built equipment after equipment, so a day recorded only by a later equipment would come out of order
			ksort($return['totalConsumption']);
			$return['totalConsumption'] = array_values($return['totalConsumption']);
		}

		if (isset($return['cost'])) {
			sort($return['cost']);
			$return['cost'] = array_values($return['cost']);
		}
		foreach ($return['power'] as &$value) {
			sort($value);
			$value = array_values($value);
		}
		foreach ($return['category'] as &$value) {
			sort($value);
			$value = array_values($value);
		}
		foreach ($return['object'] as &$value) {
			sort($value);
			$value = array_values($value);
		}
		$return['total']['consumption'] = round($return['total']['consumption'], 2);
		$return['total']['cost'] = round($return['total']['cost'], 2);
		if ($perfRun !== null) {
			$powerPoints = 0;
			foreach ($return['power'] as $powerSeries) {
				$powerPoints += count($powerSeries);
			}
			log::add('energy', 'debug', '[perf] getGraph payload: ' . $powerPoints . ' power points, ' . count($return['totalConsumption']) . ' consumption points, ' . count($return['cost']) . ' cost points, about ' . round(strlen(json_encode($return)) / 1024) . ' KB of JSON');
			self::perfSummary('getGraph', $perfRun);
		}
		return $return;
	}

/*     * *********************Méthodes d'instance************************* */

	public function getData($_startTime = null, $_endTime = null) {
		$return = array('total' => array(),'other' => array(), 'light' => array(), 'multimedia' => array(), 'heating' => array(), 'electrical' => array(), 'automatism' => array(), 'cost' => array());
		
		//ajout consumptionTotal by Frixo
		$cmd = $this->getCmd(null, 'consumptionTotal');
		if (is_object($cmd)) {
			$return['consumptionTotal'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}

		$cmd = $this->getCmd(null, 'consumptionOther');
		if (is_object($cmd)) {
			$return['other'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionLight');
		if (is_object($cmd)) {
			$return['light'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionMultimedia');
		if (is_object($cmd)) {
			$return['multimedia'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionAutomatism');
		if (is_object($cmd)) {
			$return['automatism'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionElectrical');
		if (is_object($cmd)) {
			$return['electrical'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionHeating');
		if (is_object($cmd)) {
			$return['heating'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'cost');
		if (is_object($cmd)) {
			$return['cost'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		return $return;
	}

	public function emptyHistory() {
		foreach ($this->getCmd('info') as $cmd) {
			history::emptyHistory($cmd->getId());
			$cmd->save();
			$cmd->event(0);
		}
		foreach ($this->getConfiguration('confEnergy') as $confEnergy) {
			$key = self::getConfEnergyKey($confEnergy);
			if ($this->getConfiguration('type') == 'electricity') {
				$this->setConfiguration('lastChangeTime' . $key, strtotime('now'));
				$this->setConfiguration('lastValue' . $key, 0);
				if ($confEnergy['consumption'] != '') {
					$this->setConfiguration('previous' . $key, floatval(evaluate(cmd::cmdToValue($confEnergy['consumption']))));
				}
			} else {
				if ($confEnergy['consumption'] != '') {
					$this->setConfiguration('previous' . $key, floatval(evaluate(cmd::cmdToValue($confEnergy['consumption']))));
				}
			}
		}
		$this->save();
	}

	public function preInsert() {
		$this->setConfiguration('visiblePower', 1);
		$this->setConfiguration('visibleCost', 1);
		$this->setConfiguration('visibleConsumptionTotal', 1);
	}

	public function preUpdate() {
		if ($this->getConfiguration('type') == '') {
			throw new Exception('Le type ne peut pas être vide');
		}
		$this->setCategory('energy', 1);
	}

	public function postSave() {
		$cmd = $this->getCmd(null, 'cost');
		if (!is_object($cmd)) {
			$cmd = new energyCmd();
			$cmd->setLogicalId('cost');
			$cmd->setName(__('Coût', __FILE__));
			$cmd->setTemplate('dashboard', 'tile');
		}
		$cmd->setEqLogic_id($this->getId());
		$cmd->setType('info');
		$cmd->setSubType('numeric');
		$cmd->setUnite(config::byKey('currency', 'energy', '€'));
		$cmd->setIsVisible($this->getConfiguration('visibleCost', 0));
		$cmd->setIsHistorized(1);
		$cmd->save();

		$unite = '';
		if ($this->getConfiguration('type') == 'electricity') {
			$unite = 'kWh';
		} elseif ($this->getConfiguration('type') == 'water') {
			$unite = 'L';
		} elseif ($this->getConfiguration('type') == 'gas') {
			$unite = 'kWh';
		}

		$cmd = $this->getCmd(null, 'consumptionTotal');
		if (!is_object($cmd)) {
			$cmd = new energyCmd();
			$cmd->setLogicalId('consumptionTotal');
			$cmd->setTemplate('dashboard', 'tile');
			$cmd->setConfiguration('historizeMode', 'max');
			$cmd->setIsHistorized(1);
		}
		$cmd->setName(__('Consommation', __FILE__));
		$cmd->setEqLogic_id($this->getId());
		$cmd->setType('info');
		$cmd->setSubType('numeric');
		$cmd->setUnite($unite);
		$cmd->setIsVisible($this->getConfiguration('visibleConsumptionTotal', 0));
		$cmd->save();

		if ($this->getConfiguration('type') == 'electricity') {
			$cmd = $this->getCmd(null, 'power');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('power');
				$cmd->setName(__('Puissance', __FILE__));
				$cmd->setTemplate('dashboard', 'tile');
			}
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite('W');
			$cmd->setIsHistorized(1);
			$cmd->setIsVisible($this->getConfiguration('visiblePower', 0));
			$cmd->save();

			$categories = array(
				'consumptionLight' => array(__('Consommation Lumière', __FILE__), 'visibleConsumptionLight'),
				'consumptionOther' => array(__('Consommation Autre', __FILE__), 'visibleConsumptionOther'),
				'consumptionMultimedia' => array(__('Consommation Multimedia', __FILE__), 'visibleConsumptionMultimedia'),
				'consumptionAutomatism' => array(__('Consommation Automatisme', __FILE__), 'visibleConsumptionAutomatism'),
				'consumptionElectrical' => array(__('Consommation Electromenager', __FILE__), 'visibleConsumptionElectrical'),
				'consumptionHeating' => array(__('Consommation Chauffage', __FILE__), 'visibleConsumptionHeating'),
				'consumptionHp' => array(__('Consommation HP', __FILE__), 'visibleConsumptionHP'),
				'consumptionHc' => array(__('Consommation HC', __FILE__), 'visibleConsumptionHC'),
			);
			foreach ($categories as $logicalId => $categoryInfo) {
				$cmd = $this->getCmd(null, $logicalId);
				if (!is_object($cmd)) {
					$cmd = new energyCmd();
					$cmd->setLogicalId($logicalId);
					$cmd->setTemplate('dashboard', 'tile');
					$cmd->setConfiguration('historizeMode', 'max');
					$cmd->setIsHistorized(1);
				}
				$cmd->setName($categoryInfo[0]);
				$cmd->setEqLogic_id($this->getId());
				$cmd->setType('info');
				$cmd->setSubType('numeric');
				$cmd->setUnite($unite);
				$cmd->setIsVisible($this->getConfiguration($categoryInfo[1], 0));
				$cmd->save();
			}

		} else {
			$this->setConfiguration('totalCounter', 1);
		}

	}

/*     * **********************Getteur Setteur*************************** */
}

class energyCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Méthodes statiques*************************** */

	/*     * *********************Méthodes d'instance************************* */

	public function formatValueWidget($_value) {
		if ($this->getType() == 'info' && $this->getSubType() == 'numeric') {
			return round($_value, 2);
		}
		return $_value;
	}

	public function getHistoryEnergy($_startTime = null, $_endTime = null) {
		$values = array(
			'cmd_id' => $this->getId(),
		);
		if ($_startTime != null) {
			$values['startTime'] = date('Y-m-d H:i:s', strtotime('+1 hours ' . $_startTime));
		}
		if ($_endTime != null) {
			$values['endTime'] = date('Y-m-d H:i:s', strtotime('+1 day ' . $_endTime));
		}
		$sql = '
			SELECT date(`datetime`) as "0",  MAX(CAST(value as DOUBLE)) as "1"
		FROM (
			SELECT (`datetime`  - INTERVAL 240 SECOND) as `datetime`,value
			FROM history
			WHERE cmd_id=:cmd_id ';
		if ($_startTime != null) {
			$sql .= ' AND `datetime`>=:startTime';
		}
		if ($_endTime != null) {
			$sql .= ' AND `datetime`<=:endTime';
		}
		$sql .= ' UNION ALL
			SELECT (`datetime`  - INTERVAL 240 SECOND) as `datetime`,value
			FROM historyArch
			WHERE cmd_id=:cmd_id';
		if ($_startTime != null) {
			$sql .= ' AND `datetime`>=:startTime';
		}
		if ($_endTime != null) {
			$sql .= ' AND `datetime`<=:endTime';
		}
		$sql .= ' ) as dt
		GROUP BY date(`datetime`)
		ORDER BY date(`datetime`) ASC;';
		if (!energy::isPerfEnabled()) {
			return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL);
		}
		$start = microtime(true);
		$result = DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL);
		log::add('energy', 'debug', '[perf]     SQL history of cmd ' . $this->getId() . ' (' . $this->getLogicalId() . '): ' . round((microtime(true) - $start) * 1000) . ' ms, ' . (is_array($result) ? count($result) : 0) . ' rows');
		return $result;
	}

	public function dontRemoveCmd() {
		return true;
	}

	public function execute($_options = array()) {
		return;
	}

/*     * **********************Getteur Setteur*************************** */
	
}

?>

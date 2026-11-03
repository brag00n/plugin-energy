<?php
/*
 * ANOMALY DETECTION - THE RULES
 *
 * Every rule that decides whether a cycle of consumption is abnormal is in this file, and only here.
 * This class is pure: it computes and returns, it never logs and never touches the database
 * (the log is energyAnomalyReport, the storage is energyAnomalyProfile).
 *
 * Nothing depends on the kind or the size of the installation: an equipment is only judged against what
 * IT did before. A 0.8 W standby, a 2 kW radiator and a 80 kW warehouse follow exactly the same rules.
 *
 * INDEX OF THE RULES
 *
 *  Id   Rule                                  Method                    Parameter (default)
 *  ---  ------------------------------------  ------------------------  -------------------------------
 *  R1   Rate of the cycle                     cycleDuration()           -
 *  R2   Reference of an equipment             reference()               WindowDays (30)
 *  R3   Threshold and verdict                 judge()                   Margin (1.5)
 *  R4   Establishment guard                   isEstablished()           MinActive (24)
 *  R5   Learning of a normal cycle            learn()                   -
 *  R6   Cap on the rise of the reference      cappedRate()              LearnStep (1.1)
 *  R7   Forgetting of old days                forget()                  WindowDays (30)
 *  R8   An abnormal cycle is not learned      registerAbnormal()        -
 *  R9   Change of regime                      registerAbnormal()        RegimeCount (3), RegimeHours (6)
 *  R10  A cron that ran late                  cronIsLate()              (energy::integrationMaxGap())
 *  R11  Probable cause of a jump              probableCause()           -
 *
 * A parameter can be tuned in the configuration of the plugin: "energyJump<Parameter>" for every energy
 * (e.g. energyJumpMargin) or "energyJump<Energy><Parameter>" for one energy only (e.g. energyJumpWaterMargin).
 * The order is: setting of the energy, global setting, default of the energy (energyAnomalyKind::defaults()),
 * default of the rule (the constants below). A value that is not a positive number is ignored.
 */
class energyAnomalyRules {

	/** R2, R7 - Window, in days, over which the highest normal rate of an equipment is remembered. */
	const WINDOW_DAYS = 30;

	/** R4 - Number of cycles with a non zero rate an equipment must have shown before its cycles are judged. */
	const MIN_ACTIVE = 24;

	/** R3 - A cycle is abnormal above this multiple of the reference. */
	const MARGIN = 1.5;

	/** R6 - Largest rise of the reference that one learned cycle can cause, as a multiple of it. */
	const LEARN_STEP = 1.1;

	/** R9 - Number of abnormal cycles, within REGIME_HOURS hours, from which they are accepted as a new regime. */
	const REGIME_COUNT = 3;

	/** R9 - Length, in hours, of the period in which REGIME_COUNT abnormal cycles make a change of regime. */
	const REGIME_HOURS = 6;

	/**
	 * Value of a tuning parameter for an energy (see the order of precedence in the header of this file).
	 *
	 * @param energyAnomalyKind $_kind the energy (electricity, water, gas)
	 * @param string $_name WindowDays, MinActive, Margin, LearnStep, RegimeCount or RegimeHours
	 * @param float $_default default of the rule
	 */
	public static function param($_kind, $_name, $_default) {
		foreach (array('energyJump' . $_kind->name() . $_name, 'energyJump' . $_name) as $key) {
			$configured = floatval(config::byKey($key, 'energy', 0));
			if ($configured > 0) {
				return $configured;
			}
		}
		$defaults = $_kind->defaults();
		return isset($defaults[$_name]) ? $defaults[$_name] : $_default;
	}

	/**
	 * R1 - RATE OF THE CYCLE.
	 * What is judged is not the energy of a cycle but its RATE: the quantity credited divided by the REAL time
	 * since the previous cycle. Judging the energy would flag a counter that legitimately catches up after the
	 * cron stopped (50 kWh over 25 h is only 2 kW), and would miss nothing more. The conversion of the quantity to
	 * a rate depends on the energy and is done by energyAnomalyKind::rate().
	 * When there is no previous cycle the duration is unknown: the nominal period of the cron is used.
	 *
	 * @param array $_cron result of energyAnomalyCron::record()
	 * @return array {0: duration in seconds, 1: true when the duration is the real one}
	 */
	public static function cycleDuration($_cron) {
		$known = ($_cron['interval'] !== null && $_cron['interval'] > 0);
		return array($known ? $_cron['interval'] : energyAnomalyCron::NOMINAL_INTERVAL, $known);
	}

	/**
	 * R2 - REFERENCE OF AN EQUIPMENT.
	 * The reference is the highest rate of the NORMAL cycles of the window (WindowDays), kept as one value per day
	 * so that the stored profile stays tiny. The highest value, and not the average, because an on/off load
	 * (a radiator) is mostly at zero with peaks: its average is low and its standard deviation huge.
	 */
	public static function reference($_profile) {
		return (count($_profile['days']) > 0) ? max($_profile['days']) : 0;
	}

	/**
	 * R4 - ESTABLISHMENT GUARD.
	 * An equipment is judged only once it has shown MinActive cycles with a non zero rate. Without that, a heater that
	 * stayed at 0 all summer has a reference of 0 and its first cold day would be a "jump" (the offline simulation
	 * of the rule gave 740 false alerts on such a load, and none with this guard).
	 */
	public static function isEstablished($_profile, $_kind) {
		return $_profile['active'] >= intval(self::param($_kind, 'MinActive', self::MIN_ACTIVE)) && self::reference($_profile) > 0;
	}

	/**
	 * R3 - THRESHOLD AND VERDICT.
	 * The threshold is the reference (R2) times Margin. A cycle whose rate exceeds it is abnormal. Before the
	 * profile is established (R4) nothing is judged.
	 *
	 * @return array {established, reference, threshold (null when not established), abnormal, minActive, days}
	 */
	public static function judge($_profile, $_rate, $_kind) {
		$minActive = intval(self::param($_kind, 'MinActive', self::MIN_ACTIVE));
		$reference = self::reference($_profile);
		$verdict = array('established' => false, 'reference' => $reference, 'threshold' => null, 'abnormal' => false, 'minActive' => $minActive, 'days' => count($_profile['days']));
		if (!self::isEstablished($_profile, $_kind)) {
			return $verdict;
		}
		$verdict['established'] = true;
		$verdict['threshold'] = $reference * self::param($_kind, 'Margin', self::MARGIN);
		$verdict['abnormal'] = $_rate > $verdict['threshold'];
		return $verdict;
	}

	/**
	 * R5 - LEARNING OF A NORMAL CYCLE.
	 * A normal cycle is added to the profile: only the highest rate of each day is kept, and the number of active
	 * cycles (non zero rate) is counted for R4. A zero or negative rate teaches nothing.
	 */
	public static function learn($_profile, $_rate, $_now, $_kind) {
		if ($_rate <= 0) {
			return $_profile;
		}
		$_rate = self::cappedRate($_profile, $_rate, $_kind);
		$day = date('Y-m-d', $_now);
		$_profile['days'][$day] = max(isset($_profile['days'][$day]) ? $_profile['days'][$day] : 0, round($_rate, 3));
		$_profile['active']++;
		return self::forget($_profile, $_now, $_kind);
	}

	/**
	 * R6 - CAP ON THE RISE OF THE REFERENCE.
	 * Once the profile is established, a normal cycle above the reference is learned only up to reference x LearnStep.
	 * Without it, a cycle just under the threshold would be learned in full, raise the reference by up to Margin and
	 * let the next, stronger cycle through: a staircase of jumps followed one step at a time. A real and lasting rise
	 * is still followed, step by step (and by R9 when it is sudden).
	 * Not applied while the profile is being built: the first cycles must set the level freely, otherwise a small
	 * first cycle (a heater that starts at the end of a cycle) would lock the reference far below the real level.
	 */
	public static function cappedRate($_profile, $_rate, $_kind) {
		$reference = self::reference($_profile);
		if ($reference > 0 && $_profile['active'] >= intval(self::param($_kind, 'MinActive', self::MIN_ACTIVE))) {
			return min($_rate, $reference * self::param($_kind, 'LearnStep', self::LEARN_STEP));
		}
		return $_rate;
	}

	/**
	 * R7 - FORGETTING OF OLD DAYS.
	 * Days older than WindowDays are dropped, so that an old peak does not set the reference forever and the
	 * profile stays small (one value per day).
	 */
	public static function forget($_profile, $_now, $_kind) {
		$oldest = date('Y-m-d', $_now - intval(self::param($_kind, 'WindowDays', self::WINDOW_DAYS)) * 86400);
		foreach (array_keys($_profile['days']) as $storedDay) {
			if ($storedDay < $oldest) {
				unset($_profile['days'][$storedDay]);
			}
		}
		ksort($_profile['days']);
		return $_profile;
	}

	/**
	 * R8 - AN ABNORMAL CYCLE IS NOT LEARNED.
	 * A jump must not raise its own threshold, otherwise it would hide the next one. The abnormal cycle is only
	 * remembered, with its time, in the "abnormal" list of the profile.
	 *
	 * R9 - CHANGE OF REGIME.
	 * But a LASTING change (the heating season starts, a new appliance) would then be flagged on every cycle for the
	 * whole window. When RegimeCount abnormal cycles happen within RegimeHours hours it is no longer an isolated jump:
	 * the highest of them becomes the new reference and the caller reports a change of regime.
	 * Limit: a source that keeps jumping 3 times within a few hours is accepted the same way (the warnings that came
	 * before remain in the log).
	 *
	 * @return array {0: the profile, 1: true when the cycle is accepted as a change of regime,
	 *                2: the abnormal cycles of the recent period, the current one included}
	 */
	public static function registerAbnormal($_profile, $_rate, $_now, $_kind) {
		$since = $_now - intval(self::param($_kind, 'RegimeHours', self::REGIME_HOURS) * 3600);
		$recent = array();
		foreach ($_profile['abnormal'] as $entry) {
			if ($entry[0] >= $since && $entry[0] < $_now) {
				$recent[] = $entry;
			}
		}
		$recent[] = array($_now, round($_rate, 3));
		if (count($recent) >= intval(self::param($_kind, 'RegimeCount', self::REGIME_COUNT))) {
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

	/**
	 * R10 - A CRON THAT RAN LATE.
	 * cron15 runs every 15 minutes. A cycle that comes more than energy::integrationMaxGap() (30 minutes by default,
	 * the same limit as the power integration) after the previous one means the cron was not running in between.
	 * The plugin cannot run while its cron is stopped, so the stop is only seen by the first cycle that follows it.
	 */
	public static function cronIsLate($_interval) {
		return $_interval !== null && $_interval > energy::integrationMaxGap();
	}

	/**
	 * R11 - PROBABLE CAUSE OF A JUMP.
	 * A jump comes either from the cron (it stopped, and what accumulated meanwhile lands in one cycle) or from the
	 * source (a counter that skipped, a wrong power). The cron intervals tell the two apart: a long interval points
	 * to the cron, normal intervals point to the source. Without history it cannot be told.
	 *
	 * @return array {cron: how the cycle follows the previous one, cause: the probable cause, as a sentence}
	 */
	public static function probableCause($_cron) {
		if ($_cron['interval'] === null) {
			return array('cron' => 'no previous cycle recorded, the intervals are unknown', 'cause' => 'cannot tell whether the cron was stopped');
		}
		$cron = $_cron['interval'] . ' s since the previous cycle (expected about ' . energyAnomalyCron::NOMINAL_INTERVAL . ' s)';
		if ($_cron['late']) {
			return array('cron' => $cron, 'cause' => 'the cron was not running for a while, so what accumulated meanwhile lands in this single cycle');
		}
		return array('cron' => $cron, 'cause' => 'the cron intervals are normal, so the jump comes from the source of the lines below (a counter that skipped or a wrong power)');
	}
}

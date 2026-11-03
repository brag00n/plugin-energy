<?php
/*
 * ANOMALY DETECTION - THE MEMORY OF THE CRON
 *
 * The plugin cannot run while its cron is stopped, so the stop is only seen by the first cycle that follows it,
 * from the time elapsed since the previous cycle. This class remembers the last cycles of cron15 (in the "cronHistory"
 * setting of the plugin) and computes the intervals between them. They explain a jump: see rules R10 and R11 in
 * energyAnomalyRules.
 */
class energyAnomalyCron {

	/** Normal period of cron15, in seconds. */
	const NOMINAL_INTERVAL = 900;

	/** Number of cycles remembered. */
	const HISTORY_SIZE = 12;

	/** Number of intervals shown in a warning. */
	const INTERVALS_SHOWN = 5;

	/** Timestamps of the last cycles, oldest first. Anything unreadable in the stored value is ignored. */
	public static function history() {
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
	 * Records a cycle and describes how it follows the previous ones.
	 *
	 * @param int $_now timestamp of the current cycle
	 * @return array {interval: seconds since the previous cycle (null when unknown), intervals: the last intervals,
	 *                oldest first, late: true when the cron ran late (rule R10)}
	 */
	public static function record($_now) {
		$history = self::history();
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
		config::save('cronHistory', json_encode(array_slice($history, -self::HISTORY_SIZE)), 'energy');
		return array(
			'interval' => $interval,
			'intervals' => array_slice($intervals, -self::INTERVALS_SHOWN),
			'late' => energyAnomalyRules::cronIsLate($interval),
		);
	}

	/** Forgets the remembered cycles. */
	public static function forget() {
		config::save('cronHistory', '', 'energy');
	}
}

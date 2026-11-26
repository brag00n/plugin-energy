<?php
/*
 * TARIFF PERIODS
 *
 * The tariff of the electricity changes over time (the regulated tariff moved on 1 February and 1 August, the subscription
 * with it). The plugin used a single set of tariffs for every date. A tariff period gives its own set to a range of dates:
 * the cost computed live (cron), the cost split of the panel and the subscription use the period that holds the date.
 *
 * Setting "ratePeriods" (JSON list): {from, to, rate, rateHp, rateHc, subscriptionDaily, startHc, endHc, startHc2, endHc2,
 * startHc3, endHc3}. "from" is included, "to" is EXCLUDED and may be empty (no end: the current period). Periods do not
 * overlap. A date that belongs to no period uses the fields of the configuration (rateHp, rateHc, rate, subscriptionDaily,
 * startHc...), so that without any period the plugin behaves exactly as before.
 */
class energyRates {

	/** The fields of a set of tariffs, besides the dates. */
	const FIELDS = array('rate', 'rateHp', 'rateHc', 'subscriptionDaily', 'startHc', 'endHc', 'startHc2', 'endHc2', 'startHc3', 'endHc3');
	const NUMBERS = array('rateHp', 'rateHc', 'subscriptionDaily');
	const HOURS = array('startHc', 'endHc', 'startHc2', 'endHc2', 'startHc3', 'endHc3');

	private static function toNumber($_value) {
		return floatval(str_replace(',', '.', strval($_value)));
	}

	/**
	 * A setting that holds a JSON list. Jeedom decodes a JSON value by itself when it reads a setting (config::byKey returns an
	 * array then, and "Array" once cast to a string): both the text and the decoded list are accepted.
	 */
	public static function jsonSetting($_key, $_default = '[]') {
		$value = config::byKey($_key, 'energy', $_default);
		if (is_array($value)) {
			return $value;
		}
		$decoded = json_decode(strval($value), true);
		return is_array($decoded) ? $decoded : array();
	}

	/** Sets of tariffs memorized by period, sorted by start date. */
	public static function periods() {
		$periods = self::jsonSetting('ratePeriods');
		if (!is_array($periods)) {
			return array();
		}
		$clean = array();
		foreach ($periods as $period) {
			if (is_array($period) && isset($period['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $period['from'])) {
				$clean[] = $period;
			}
		}
		usort($clean, function ($a, $b) {
			return strcmp($a['from'], $b['from']);
		});
		return $clean;
	}

	/** The period that holds the date (YYYY-MM-DD), or null. */
	public static function periodFor($_date) {
		foreach (self::periods() as $period) {
			$to = isset($period['to']) ? trim(strval($period['to'])) : '';
			if ($_date >= $period['from'] && ($to === '' || $_date < $to)) {
				return $period;
			}
		}
		return null;
	}

	/**
	 * The tariffs that apply to a date, normalized.
	 *
	 * @param string|int $_date YYYY-MM-DD, "YYYY-MM-DD HH:MM:SS" or a timestamp
	 * @return array {variable, rate, rateHp, rateHc, subscriptionDaily, startHc.. endHc3 (as read), period (from|null)}
	 */
	public static function forDate($_date) {
		if (is_numeric($_date)) {
			$_date = date('Y-m-d', intval($_date));
		}
		$day = substr(strval($_date), 0, 10);
		$period = self::periodFor($day);
		$get = function ($key, $default = 0) use ($period) {
			if ($period !== null && array_key_exists($key, $period) && trim(strval($period[$key])) !== '') {
				return $period[$key];
			}
			// a field left empty in a period falls back to the configuration, so that only what changes has to be typed
			return config::byKey($key, 'energy', $default);
		};
		$set = array(
			'variable' => config::byKey('rateMode', 'energy') == 'variable',
			'rateHp' => self::toNumber($get('rateHp')),
			'rateHc' => self::toNumber($get('rateHc')),
			'subscriptionDaily' => self::toNumber($get('subscriptionDaily')),
			// the fixed rate may be a command: evaluated like the cron always did
			'rate' => floatval(jeedom::evaluateExpression(str_replace(',', '.', strval($get('rate'))))),
			'period' => $period === null ? null : $period['from'],
		);
		foreach (self::HOURS as $key) {
			$set[$key] = $get($key, -1);
		}
		return $set;
	}

	/**
	 * Checks the periods typed by the user and returns them sorted, ready to be saved.
	 *
	 * @throws Exception on an invalid or overlapping period
	 */
	public static function normalize($_periods) {
		$periods = array();
		foreach ((array) $_periods as $index => $period) {
			$period = (array) $period;
			$line = $index + 1;
			$from = isset($period['from']) ? trim(strval($period['from'])) : '';
			$to = isset($period['to']) ? trim(strval($period['to'])) : '';
			if (!energyCatchup::validDate($from)) {
				throw new Exception(__('Plage de tarification invalide (ligne ', __FILE__) . $line . ') : ' . $from);
			}
			if ($to !== '' && (!energyCatchup::validDate($to) || $from >= $to)) {
				throw new Exception(__('Plage de tarification invalide (ligne ', __FILE__) . $line . ') : ' . $from . ' → ' . $to);
			}
			$clean = array('from' => $from, 'to' => $to);
			foreach (self::NUMBERS as $key) {
				$raw = isset($period[$key]) ? trim(strval($period[$key])) : '';
				if ($raw !== '') {
					$number = str_replace(',', '.', $raw);
					if (!is_numeric($number) || floatval($number) < 0 || floatval($number) > 1000) {
						throw new Exception(__('Valeur invalide (ligne ', __FILE__) . $line . ') ' . $key . ' : ' . $raw);
					}
				}
				$clean[$key] = $raw;
			}
			$fixed = isset($period['rate']) ? trim(strval($period['rate'])) : '';
			if (strlen($fixed) > 200) {
				throw new Exception(__('Tarif fixe trop long (ligne ', __FILE__) . $line . ')');
			}
			$clean['rate'] = $fixed;
			foreach (self::HOURS as $key) {
				$raw = isset($period[$key]) ? trim(strval($period[$key])) : '';
				if ($raw !== '' && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $raw)) {
					throw new Exception(__('Heure invalide (ligne ', __FILE__) . $line . ') ' . $key . ' : ' . $raw);
				}
				$clean[$key] = $raw;
			}
			$periods[] = $clean;
		}
		usort($periods, function ($a, $b) {
			return strcmp($a['from'], $b['from']);
		});
		for ($i = 1; $i < count($periods); $i++) {
			$previousEnd = $periods[$i - 1]['to'];
			if ($previousEnd === '' || $periods[$i]['from'] < $previousEnd) {
				throw new Exception(__('Les plages de tarification se chevauchent : ', __FILE__) . $periods[$i - 1]['from'] . ' → ' . ($previousEnd === '' ? '…' : $previousEnd) . ' et ' . $periods[$i]['from']);
			}
		}
		return $periods;
	}

	/** Saves the periods (validated) and returns them. */
	public static function save($_periods) {
		$periods = self::normalize($_periods);
		config::save('ratePeriods', json_encode($periods), 'energy');
		return $periods;
	}
}

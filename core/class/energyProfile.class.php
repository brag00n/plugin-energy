<?php
/*
 * CONSUMPTION PROFILE BY EQUIPMENT, PER SEASON
 *
 * The total counter (Enedis) is the reference, but the equipments measure badly on some days: a counter that is not reset
 * (thousands of kWh in one day), a series frozen at the same value every day, no value at all. And the days imported from
 * Enedis carry no split by equipment. A profile gives the share (in %) of every equipment (the total counter excluded) in the
 * consumption of the house, for two seasons (cold, hot), the rest being "unmeasured". The panel then replaces the days to
 * recalculate by "share x total of the counter of the day". Nothing is written in the history: the replacement is made when the
 * graph is built, so changing the profile changes the graph and nothing has to be restored.
 *
 * Days to recalculate, per equipment:
 *   aberrant : the value of the day is above the threshold (setting "profileThreshold", 100 kWh by default)
 *   frozen   : a day imported from Enedis (flag of the total counter) whose value equals the day before
 *   missing  : a day imported from Enedis with no value at all
 * "frozen" and "missing" are only looked for on the imported days, and only when the setting "profileCatchup" is on (default).
 *
 * Two kinds of equipment. A VARIABLE one (a radiator) takes a share of what the house consumes beyond the fixed ones. A FIXED one
 * (a fridge, a box) draws a constant power: its day is always power x 24 h, whatever the counter says, and the variable ones share
 * what is left of the counter once the fixed ones are taken off. A fixed equipment is never "frozen": a constant value is normal.
 *
 * An equipment is identified by its internal id (eqLogic id), never by its name, which the user can change: the settings hold ids, the
 * name is only read to be displayed. The id of an equipment that no longer exists is ignored (it does not weigh on the profile).
 *
 * Settings (plugin "energy"):
 *   profileFixed     {"<id>":watts}      the fixed equipments and their constant power
 *   profileSeasons   {"cold":[11,12,1,2,3,4]}   the months of the cold season, all the others are the hot season
 *   profile          {"cold":{"<id>":pct,...},"hot":{...}}   shares in %, the rest is "unmeasured"
 *   profileThreshold kWh above which a day of an equipment is aberrant
 *   profileCatchup   1 = also replace the frozen and missing days of the imported days
 */
class energyProfile {

	const UNMEASURED = 'unmeasured';
	const CATEGORIES = array('other', 'light', 'multimedia', 'automatism', 'electrical', 'heating');
	const DEFAULT_THRESHOLD = 100;
	const FROZEN_EPSILON = 0.01;
	const DEFAULT_COLD = array(11, 12, 1, 2, 3, 4);

	public static function log($_level, $_message) {
		if ($_level == 'debug' && !energyLogTypes::enabled('profile')) {
			return;
		}
		log::add('energy', $_level, '[profile] ' . $_message);
	}

	/* ------------------------------------------------------------------ settings */

	/** Months of the cold season (1 to 12), the others are the hot season. */
	public static function coldMonths() {
		$raw = energyRates::jsonSetting('profileSeasons', '{}');
		$months = array();
		if (isset($raw['cold']) && is_array($raw['cold'])) {
			foreach ($raw['cold'] as $month) {
				$month = intval($month);
				if ($month >= 1 && $month <= 12) {
					$months[$month] = $month;
				}
			}
			if (count($months) > 0) {
				sort($months);
				return array_values($months);
			}
		}
		return self::DEFAULT_COLD;
	}

	/** 'cold' or 'hot' for a date (YYYY-MM-DD, or longer). */
	public static function seasonOf($_date) {
		return in_array(intval(substr(strval($_date), 5, 2)), self::coldMonths()) ? 'cold' : 'hot';
	}

	public static function threshold() {
		$value = floatval(str_replace(',', '.', strval(config::byKey('profileThreshold', 'energy', self::DEFAULT_THRESHOLD))));
		return $value > 0 ? $value : self::DEFAULT_THRESHOLD;
	}

	public static function replaceCatchup() {
		return config::byKey('profileCatchup', 'energy', 1) != 0;
	}

	/** Internal ids (as strings) of the equipments that exist and belong to the profile. */
	public static function knownIds() {
		$ids = array();
		foreach (self::equipments() as $equipment) {
			$ids[strval($equipment['id'])] = true;
		}
		return $ids;
	}

	/** The fixed equipments: {equipment id => constant power in W}. */
	public static function fixed() {
		$raw = energyRates::jsonSetting('profileFixed', '{}');
		$known = self::knownIds();
		$fixed = array();
		foreach ($raw as $id => $watts) {
			$number = floatval(str_replace(',', '.', strval($watts)));
			if ($number > 0 && isset($known[strval($id)])) {
				$fixed[strval($id)] = $number;
			}
		}
		return $fixed;
	}

	/** kWh per day of every fixed equipment: {id => kWh}. */
	public static function fixedKwh() {
		$kwh = array();
		foreach (self::fixed() as $id => $watts) {
			$kwh[$id] = $watts * 24 / 1000;
		}
		return $kwh;
	}

	/** The shares typed by the user: season => {equipment id => %}, only numbers >= 0, the fixed equipments have none. */
	public static function profile() {
		$raw = energyRates::jsonSetting('profile', '{}');
		$fixed = self::fixed();
		$known = self::knownIds();
		$profile = array('cold' => array(), 'hot' => array());
		foreach (array('cold', 'hot') as $season) {
			if (isset($raw[$season]) && is_array($raw[$season])) {
				foreach ($raw[$season] as $id => $share) {
					if ($id !== self::UNMEASURED && isset($known[strval($id)]) && !isset($fixed[strval($id)]) && is_numeric(str_replace(',', '.', strval($share))) && floatval(str_replace(',', '.', strval($share))) > 0) {
						$profile[$season][strval($id)] = floatval(str_replace(',', '.', strval($share)));
					}
				}
			}
		}
		return $profile;
	}

	/** True when at least one equipment has a share: without it the plugin behaves exactly as before. */
	public static function configured() {
		$profile = self::profile();
		return count($profile['cold']) + count($profile['hot']) + count(self::fixed()) > 0;
	}

	/**
	 * Shares of a season in %, of what the variable equipments share (the counter minus the fixed equipments), with the remainder as
	 * "unmeasured" (never below 0, the equipments are scaled down above 100).
	 */
	public static function shares($_season) {
		$profile = self::profile();
		$shares = isset($profile[$_season]) ? $profile[$_season] : array();
		$sum = array_sum($shares);
		if ($sum > 100) {
			foreach ($shares as $id => $share) {
				$shares[$id] = $share * 100 / $sum;
			}
			$sum = 100;
		}
		$shares[self::UNMEASURED] = max(0, 100 - $sum);
		return $shares;
	}

	/** The equipments of the profile: electricity equipments of the plugin that are not the total counter. */
	public static function equipments() {
		$list = array();
		foreach (energy::byType('energy') as $energy) {
			if ($energy->getConfiguration('type') == 'electricity' && $energy->getConfiguration('totalCounter') != 1) {
				$list[] = array('id' => $energy->getId(), 'name' => $energy->getHumanName());
			}
		}
		return $list;
	}

	/**
	 * Validates what the user typed and saves it.
	 *
	 * @param array $_cold months of the cold season
	 * @param array $_profile {cold: {id: %}, hot: {id: %}}
	 * @param array $_fixed {id: constant power in W}, the equipments of the fixed kind
	 * @throws Exception on an invalid value
	 */
	public static function save($_cold, $_profile, $_threshold, $_replaceCatchup, $_fixed = array()) {
		$cold = array();
		foreach ((array) $_cold as $month) {
			if (!is_numeric($month) || intval($month) < 1 || intval($month) > 12) {
				throw new Exception(__('Mois invalide : ', __FILE__) . strval($month));
			}
			$cold[intval($month)] = intval($month);
		}
		sort($cold);
		$threshold = str_replace(',', '.', strval($_threshold));
		if (!is_numeric($threshold) || floatval($threshold) < 10 || floatval($threshold) > 100000) {
			throw new Exception(__('Seuil invalide (10 à 100000 kWh) : ', __FILE__) . strval($_threshold));
		}
		$known = self::knownIds();
		$fixed = array();
		foreach ((array) $_fixed as $id => $watts) {
			$number = str_replace(',', '.', trim(strval($watts)));
			if ($number === '' || !isset($known[strval($id)])) {
				continue;
			}
			if (!is_numeric($number) || floatval($number) <= 0 || floatval($number) > 100000) {
				throw new Exception(__('Puissance invalide (0 à 100000 W) : ', __FILE__) . strval($watts));
			}
			$fixed[strval($id)] = round(floatval($number), 2);
		}
		$clean = array('cold' => array(), 'hot' => array());
		foreach (array('cold', 'hot') as $season) {
			$sum = 0;
			foreach (isset($_profile[$season]) ? (array) $_profile[$season] : array() as $id => $share) {
				if ($id === self::UNMEASURED || !isset($known[strval($id)]) || isset($fixed[strval($id)])) {
					continue;
				}
				$number = str_replace(',', '.', trim(strval($share)));
				if ($number === '') {
					continue;
				}
				if (!is_numeric($number) || floatval($number) < 0 || floatval($number) > 100) {
					throw new Exception(__('Part invalide (0 à 100 %) : ', __FILE__) . strval($share));
				}
				$sum += floatval($number);
				if (floatval($number) > 0) {
					$clean[$season][strval($id)] = round(floatval($number), 2);
				}
			}
			if ($sum > 100.05) {
				throw new Exception(__('La somme des parts dépasse 100 % : ', __FILE__) . round($sum, 2) . ' % (' . ($season == 'cold' ? __('saison froide', __FILE__) : __('saison chaude', __FILE__)) . ')');
			}
		}
		config::save('profileSeasons', json_encode(array('cold' => array_values($cold))), 'energy');
		config::save('profile', json_encode($clean), 'energy');
		config::save('profileFixed', json_encode($fixed), 'energy');
		config::save('profileThreshold', floatval($threshold), 'energy');
		config::save('profileCatchup', $_replaceCatchup ? 1 : 0, 'energy');
		self::log('info', 'profile saved: cold months ' . implode(',', $cold) . ', threshold ' . floatval($threshold) . ' kWh, imported days ' . ($_replaceCatchup ? 'replaced' : 'kept') . ', shares ' . json_encode($clean) . ', fixed (W) ' . json_encode($fixed));
	}

	/* ------------------------------------------------------------------ reading the days */

	/** {date => value} of the rows of a history (the rows are [date, value]). */
	private static function byDate($_rows) {
		$days = array();
		foreach ((array) $_rows as $row) {
			if (isset($row[0]) && isset($row[1]) && is_numeric($row[1])) {
				$days[substr(strval($row[0]), 0, 10)] = floatval($row[1]);
			}
		}
		ksort($days);
		return $days;
	}

	private static function previousDay($_date) {
		return energyCatchup::addDays($_date, -1);
	}

	/**
	 * Reads the total counter: its consumption and cost by day, and the days imported from Enedis.
	 *
	 * @return array|null {totals, costs, catchup} (each {date => ...}), null without a total counter
	 */
	private static function counterDays($_start, $_end) {
		$targets = energyCatchup::targets();
		if (count($targets) == 0) {
			return null;
		}
		$counter = eqLogic::byId($targets[0]['id']);
		if (!is_object($counter)) {
			return null;
		}
		$since = energy::counterSinceMs();
		$read = function ($logicalId) use ($counter, $_start, $_end, $since) {
			$cmd = $counter->getCmd(null, $logicalId);
			if (!is_object($cmd)) {
				return array();
			}
			$days = self::byDate($cmd->getHistoryEnergy($_start, $_end));
			if ($since !== null) {
				foreach (array_keys($days) as $date) {
					if (floatval(strtotime($date . ' UTC')) * 1000 < $since) {
						unset($days[$date]);
					}
				}
			}
			return $days;
		};
		$flags = $read('consumptionCatchup');
		$catchup = array();
		foreach ($flags as $date => $flag) {
			if ($flag >= 1) {
				$catchup[$date] = true;
			}
		}
		return array('totals' => $read('consumptionTotal'), 'costs' => $read('cost'), 'catchup' => $catchup);
	}

	/**
	 * The days of one equipment to recalculate.
	 *
	 * @param array $_series {date => kWh} consumption of the equipment
	 * @param array|null $_catchup {date => true} imported days, null = look for the frozen days everywhere and not for missing ones
	 * @param bool $_skipFrozen a fixed equipment: a constant value is normal, it is never frozen
	 * @return array {date => 'aberrant'|'frozen'|'missing'}
	 */
	public static function classify($_series, $_catchup, $_threshold, $_replaceCatchup = true, $_skipFrozen = false) {
		$marks = array();
		foreach ($_series as $date => $value) {
			if ($value > $_threshold) {
				$marks[$date] = 'aberrant';
			}
		}
		if ($_catchup === null) {
			foreach ($_skipFrozen ? array() : $_series as $date => $value) {
				$previous = self::previousDay($date);
				if (!isset($marks[$date]) && $value > 0 && isset($_series[$previous]) && abs($value - $_series[$previous]) < self::FROZEN_EPSILON) {
					$marks[$date] = 'frozen';
				}
			}
			ksort($marks);
			return $marks;
		}
		if ($_replaceCatchup) {
			foreach (array_keys($_catchup) as $date) {
				if (isset($marks[$date])) {
					continue;
				}
				$value = isset($_series[$date]) ? $_series[$date] : 0;
				if ($value <= 0) {
					$marks[$date] = 'missing';
				} elseif (!$_skipFrozen && isset($_series[self::previousDay($date)]) && abs($value - $_series[self::previousDay($date)]) < self::FROZEN_EPSILON) {
					$marks[$date] = 'frozen';
				}
			}
		}
		ksort($marks);
		return $marks;
	}

	/** The reading context of getGraph(): null (nothing to do) unless an electricity profile is configured and a counter exists. */
	public static function context($_start, $_end, $_energyType) {
		if ($_energyType != 'electricity' || !self::configured()) {
			return null;
		}
		$counter = self::counterDays($_start, $_end);
		if ($counter === null) {
			return null;
		}
		$counter['threshold'] = self::threshold();
		$counter['replaceCatchup'] = self::replaceCatchup();
		$counter['fixed'] = self::fixedKwh();
		$counter['fixedTotal'] = array_sum($counter['fixed']);
		return $counter;
	}

	/* ------------------------------------------------------------------ replacing the days */

	/** Categories of an equipment and their weights: its measured days (the ones not replaced), else its declared lines. */
	private static function weights($_energy, $_data, $_marks) {
		$sums = array();
		foreach (self::CATEGORIES as $category) {
			$sum = 0;
			foreach (isset($_data[$category]) ? (array) $_data[$category] : array() as $row) {
				if (isset($row[1]) && is_numeric($row[1]) && !isset($_marks[substr(strval($row[0]), 0, 10)])) {
					$sum += floatval($row[1]);
				}
			}
			if ($sum > 0) {
				$sums[$category] = $sum;
			}
		}
		if (count($sums) == 0) {
			foreach ((array) $_energy->getConfiguration('confEnergy') as $line) {
				if (isset($line['category']) && in_array($line['category'], self::CATEGORIES)) {
					$sums[$line['category']] = 1;
				}
			}
		}
		if (count($sums) == 0) {
			$sums['other'] = 1;
		}
		$total = array_sum($sums);
		foreach ($sums as $category => $sum) {
			$sums[$category] = $sum / $total;
		}
		return $sums;
	}

	/** The rows of a series without the given days. */
	private static function without($_rows, $_days) {
		$kept = array();
		foreach ((array) $_rows as $row) {
			if (!isset($_days[substr(strval($row[0]), 0, 10)])) {
				$kept[] = $row;
			}
		}
		return $kept;
	}

	/**
	 * Replaces the days to recalculate of an equipment by its share of the counter of the day.
	 *
	 * @param array $_data what energy::getData() returned
	 * @param array $_context what context() returned
	 * @return array {data, days} the data adjusted and the replaced days (YYYY-MM-DD => reason)
	 */
	public static function adjust($_energy, $_data, $_context) {
		$id = strval($_energy->getId());
		$isFixed = isset($_context['fixed'][$id]);
		$series = self::byDate(isset($_data['consumptionTotal']) ? $_data['consumptionTotal'] : array());
		$marks = self::classify($series, $_context['catchup'], $_context['threshold'], $_context['replaceCatchup'], $isFixed);
		if (count($marks) == 0) {
			return array('data' => $_data, 'days' => array());
		}
		$weights = self::weights($_energy, $_data, $marks);
		$data = $_data;
		foreach (array_merge(array('consumptionTotal', 'cost'), array_keys($weights)) as $key) {
			$data[$key] = self::without(isset($_data[$key]) ? $_data[$key] : array(), $marks);
		}
		foreach (self::CATEGORIES as $category) {
			if (!isset($weights[$category]) && isset($data[$category])) {
				$data[$category] = self::without($data[$category], $marks);
			}
		}
		$cache = array('cold' => self::shares('cold'), 'hot' => self::shares('hot'));
		foreach ($marks as $date => $reason) {
			$total = isset($_context['totals'][$date]) ? $_context['totals'][$date] : 0;
			$cost = isset($_context['costs'][$date]) ? $_context['costs'][$date] : 0;
			if ($isFixed) {
				// constant power: its day is the same every day, never more than the counter says
				$value = $total > 0 ? min($_context['fixed'][$id], $total) : $_context['fixed'][$id];
				$how = 'fixed power';
			} else {
				// a variable one takes its share of what the fixed equipments leave of the counter
				$share = (isset($cache[self::seasonOf($date)][$id]) ? $cache[self::seasonOf($date)][$id] : 0) / 100;
				$value = $share * max(0, $total - $_context['fixedTotal']);
				$how = 'share ' . round($share * 100, 2) . ' % of ' . round(max(0, $total - $_context['fixedTotal']), 3);
			}
			self::log('debug', 'equipment ' . $id . ' ' . $date . ' (' . $reason . '): ' . (isset($series[$date]) ? $series[$date] : 'no value') . ' kWh replaced by ' . round($value, 3) . ' kWh (' . $how . ', counter ' . $total . ')');
			if ($value <= 0) {
				continue;
			}
			$data['consumptionTotal'][] = array($date, $value);
			$data['cost'][] = array($date, $total > 0 ? $value / $total * $cost : 0);
			foreach ($weights as $category => $weight) {
				$data[$category][] = array($date, $value * $weight);
			}
		}
		return array('data' => $data, 'days' => $marks);
	}

	/* ------------------------------------------------------------------ analysis and overview */

	private static function checkRange($_from, $_to) {
		if (!energyCatchup::validDate($_from) || !energyCatchup::validDate($_to) || $_from > $_to) {
			throw new Exception(__('Plage de dates invalide : ', __FILE__) . $_from . ' → ' . $_to);
		}
		if (count(energyCatchup::daysBetween($_from, $_to)) >= energyCatchup::MAX_DAYS) {
			throw new Exception(__('Plage de dates trop longue.', __FILE__));
		}
	}

	/** A constant day: within this fraction of the median day. */
	const FIXED_TOLERANCE = 0.1;
	/** A fixed equipment: at least this share of its days are constant, on at least this many days. */
	const FIXED_SHARE = 0.8;
	const FIXED_MIN_DAYS = 3;

	/**
	 * Detects the equipments of the fixed kind over the days of a range: a constant daily value (the same within 10 % on at least
	 * 80 % of the days it consumed), on at least 3 days and on at least half of the days of the range. Its power is the median day.
	 *
	 * @param array $_series {id => {date => kWh}}
	 * @param array $_days the days kept (the counter recorded something)
	 * @return array {id => watts}
	 */
	public static function detectFixed($_series, $_days) {
		$fixed = array();
		$threshold = self::threshold();
		foreach ($_series as $id => $values) {
			$positive = array();
			foreach ($_days as $date) {
				if (isset($values[$date]) && $values[$date] > 0 && $values[$date] <= $threshold) {
					$positive[] = $values[$date];
				}
			}
			$count = count($positive);
			if ($count < self::FIXED_MIN_DAYS || $count < count($_days) / 2) {
				continue;
			}
			sort($positive);
			$median = $count % 2 ? $positive[($count - 1) / 2] : ($positive[$count / 2 - 1] + $positive[$count / 2]) / 2;
			$constant = 0;
			foreach ($positive as $value) {
				if (abs($value - $median) <= self::FIXED_TOLERANCE * $median) {
					$constant++;
				}
			}
			if ($constant >= self::FIXED_SHARE * $count) {
				$fixed[strval($id)] = round($median * 1000 / 24, 1);
			}
		}
		return $fixed;
	}

	/**
	 * Share of every variable equipment in what the counter records beyond the fixed equipments, over the healthy days of a range
	 * (no equipment aberrant, no variable equipment frozen). The equipments of the fixed kind are DETECTED from their constant days.
	 *
	 * @return array {from, to, days, dropped{date => reason}, shares{id => %}, fixed{id => W}, unmeasured, kwh, fixedKwh}
	 */
	public static function analyze($_from, $_to) {
		self::checkRange($_from, $_to);
		$counter = self::counterDays($_from, $_to);
		if ($counter === null) {
			throw new Exception(__('Aucun compteur total pour calculer le profil.', __FILE__));
		}
		$threshold = self::threshold();
		$series = array();
		foreach (self::equipments() as $equipment) {
			$energy = eqLogic::byId($equipment['id']);
			$cmd = is_object($energy) ? $energy->getCmd(null, 'consumptionTotal') : null;
			$series[strval($equipment['id'])] = is_object($cmd) ? self::byDate($cmd->getHistoryEnergy($_from, $_to)) : array();
		}
		$counterDays = array();
		foreach ($counter['totals'] as $date => $value) {
			if ($date >= $_from && $date <= $_to && $value > 0) {
				$counterDays[$date] = $value;
			}
		}
		// 1. the equipments of the fixed kind, whose constant days are normal and not a frozen series
		$fixed = self::detectFixed($series, array_keys($counterDays));
		$fixedTotal = 0;
		foreach ($fixed as $watts) {
			$fixedTotal += $watts * 24 / 1000;
		}
		// 2. the days an equipment measured badly are left out
		$marks = array();
		foreach ($series as $id => $values) {
			$marks[$id] = self::classify($values, null, $threshold, true, isset($fixed[$id]));
		}
		$sums = array_fill_keys(array_keys(array_diff_key($series, $fixed)), 0);
		$total = 0;
		$base = 0;
		$days = 0;
		$dropped = array();
		foreach ($counterDays as $date => $value) {
			$reason = '';
			foreach ($marks as $equipmentMarks) {
				if (isset($equipmentMarks[$date])) {
					$reason = $equipmentMarks[$date];
				}
			}
			if ($reason !== '') {
				$dropped[$date] = $reason;
				continue;
			}
			foreach ($sums as $id => $kwh) {
				$sums[$id] += isset($series[$id][$date]) ? $series[$id][$date] : 0;
			}
			$total += $value;
			// what the variable equipments share: the counter minus the fixed ones
			$base += max(0, $value - $fixedTotal);
			$days++;
		}
		$shares = array();
		$sum = 0;
		foreach ($sums as $id => $kwh) {
			$shares[strval($id)] = $base > 0 ? round($kwh * 100 / $base, 2) : 0;
			$sum += $shares[strval($id)];
		}
		if ($sum > 100) {
			// the equipments declare more than the counter: scaled down, nothing is left unmeasured
			foreach ($shares as $id => $share) {
				$shares[$id] = round($share * 100 / $sum, 2);
			}
			$sum = array_sum($shares);
		}
		self::log('info', 'analysis ' . $_from . ' → ' . $_to . ': ' . $days . ' day(s) kept, ' . count($dropped) . ' dropped, ' . round($total, 1) . ' kWh of which ' . round($fixedTotal * $days, 1) . ' fixed, fixed (W) ' . json_encode($fixed) . ', shares ' . json_encode($shares));
		return array('from' => $_from, 'to' => $_to, 'days' => $days, 'dropped' => $dropped, 'shares' => $shares, 'fixed' => $fixed, 'unmeasured' => round(max(0, 100 - $sum), 2), 'kwh' => round($total, 2), 'fixedKwh' => round($fixedTotal * $days, 2));
	}

	/**
	 * The days that the panel recalculates in a range, by equipment and reason.
	 *
	 * @return array {from, to, total, byReason{reason => n}, equipments[{id, name, aberrant[], frozen, missing}]}
	 */
	public static function daysToRecalc($_from, $_to) {
		self::checkRange($_from, $_to);
		$counter = self::counterDays($_from, $_to);
		$catchup = $counter === null ? array() : $counter['catchup'];
		$threshold = self::threshold();
		$fixed = self::fixedKwh();
		$result = array('from' => $_from, 'to' => $_to, 'total' => 0, 'byReason' => array('aberrant' => 0, 'frozen' => 0, 'missing' => 0), 'equipments' => array());
		foreach (self::equipments() as $equipment) {
			$energy = eqLogic::byId($equipment['id']);
			$cmd = is_object($energy) ? $energy->getCmd(null, 'consumptionTotal') : null;
			$series = is_object($cmd) ? self::byDate($cmd->getHistoryEnergy($_from, $_to)) : array();
			$line = array('id' => $equipment['id'], 'name' => $equipment['name'], 'aberrant' => array(), 'frozen' => 0, 'missing' => 0);
			foreach (self::classify($series, $catchup, $threshold, self::replaceCatchup(), isset($fixed[strval($equipment['id'])])) as $date => $reason) {
				if ($date < $_from || $date > $_to) {
					continue;
				}
				if ($reason == 'aberrant') {
					$line['aberrant'][] = $date;
				} else {
					$line[$reason]++;
				}
				$result['byReason'][$reason]++;
				$result['total']++;
			}
			$result['equipments'][] = $line;
		}
		return $result;
	}

	/**
	 * What the configuration page shows: the equipments, the seasons, the shares and what each season weighs (kWh and euros of
	 * the counter over the year before today), so that a share can be read as kWh and euros.
	 */
	public static function overview() {
		$to = date('Y-m-d', strtotime('-1 day'));
		$from = date('Y-m-d', strtotime('-365 day'));
		$seasons = array('cold' => array('days' => 0, 'kwh' => 0, 'cost' => 0), 'hot' => array('days' => 0, 'kwh' => 0, 'cost' => 0));
		$counter = self::counterDays($from, $to);
		if ($counter !== null) {
			foreach ($counter['totals'] as $date => $value) {
				$seasons[self::seasonOf($date)]['days']++;
				$seasons[self::seasonOf($date)]['kwh'] += $value;
				$seasons[self::seasonOf($date)]['cost'] += isset($counter['costs'][$date]) ? $counter['costs'][$date] : 0;
			}
		}
		foreach ($seasons as $season => $values) {
			$seasons[$season]['kwh'] = round($values['kwh'], 1);
			$seasons[$season]['cost'] = round($values['cost'], 2);
		}
		return array(
			'equipments' => self::equipments(),
			'fixed' => self::fixed(),
			'cold' => self::coldMonths(),
			'profile' => array('cold' => self::shares('cold'), 'hot' => self::shares('hot')),
			'typed' => self::profile(),
			'seasons' => $seasons,
			'threshold' => self::threshold(),
			'replaceCatchup' => self::replaceCatchup() ? 1 : 0,
			'from' => $from,
			'to' => $to,
		);
	}
}

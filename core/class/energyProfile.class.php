<?php
/*
 * CONSUMPTION PROFILES BY EQUIPMENT, PER SEASON
 *
 * The total counter (Enedis, or the index of the Linky) is the reference of the day, but the equipments measure badly on some days
 * (a counter that is not reset, a frozen series, no value at all). Two profiles, one per season (cold, hot), say how the total of the
 * house is shared between the equipments of the plugin (electricity, the total counter excluded). The recalculation that writes them
 * in the history is in energyProfileUpdate.
 *
 * Two kinds of equipment in a profile:
 *   FIXED    a value in kWh per day, the same every day of the season (a fridge, a box)
 *   VARIABLE a share in % of what is left of the total once the fixed equipments are taken off (a radiator)
 * What no equipment takes is "unmeasured": fixed + variable + unmeasured = total of the day.
 *
 * Tariff of an equipment (HC / HP / fixed rate): with an HP/HC tariff every equipment has the share of its consumption that falls in the
 * off-peak hours (HC), per season; the rest is HP. It is typed or deduced from the HC and HP history of the equipment over the range
 * analysed, and what is not typed is 0 (all in HP). Which tariff applies is decided by the tariff of the DAY, not by what is typed: with
 * an HP/HC tariff the columns are used, with a fixed tariff they are never read and the cost of a day is its consumption x the fixed rate.
 *
 * An equipment is identified by its internal id (eqLogic id), never by its name, which the user can change: the settings hold ids, the
 * name is only read to be displayed. The id of an equipment that no longer exists is ignored.
 *
 * The profile can be computed over a range of characteristic days (analyze): the equipments whose value hardly changes from one day
 * to the next are the fixed ones (their kWh is the median day), the others are variable and get their share. The result is only a
 * proposal: it is typed in the table, corrected by hand, then saved.
 *
 * Settings (plugin "energy"):
 *   profileSeasons            {"cold":[11,12,1,2,3,4]}   the months of the cold season, all the others are the hot season
 *   profile                   {"cold":{"<id>":pct,...},"hot":{...}}   shares of the variable equipments, in %
 *   profileFixedKwh           {"<id>":kWh per day}       the fixed equipments
 *   profileHc                 {"cold":{"<id>":pct},"hot":{...}}   share of the consumption of an equipment that falls in HC, in %
 *   profileToleranceFixed     % above its value of the profile from which the day of a fixed equipment is aberrant (50 by default)
 *   profileToleranceVariable  the same for a variable equipment (100 by default)
 */
class energyProfile {

	const UNMEASURED = 'unmeasured';
	const CATEGORIES = array('other', 'light', 'multimedia', 'automatism', 'electrical', 'heating');
	const DEFAULT_COLD = array(11, 12, 1, 2, 3, 4);
	const DEFAULT_TOLERANCE_FIXED = 50;
	const DEFAULT_TOLERANCE_VARIABLE = 100;
	/** A constant day: within this fraction of the median day. */
	const FIXED_TOLERANCE = 0.1;
	/** A fixed equipment: at least this share of its days are constant, on at least this many days. */
	const FIXED_SHARE = 0.8;
	const FIXED_MIN_DAYS = 3;

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

	private static function percent($_key, $_default) {
		$value = str_replace(',', '.', strval(config::byKey($_key, 'energy', $_default)));
		return is_numeric($value) && floatval($value) >= 0 ? floatval($value) : $_default;
	}

	/** Tolerance of a fixed equipment, as a fraction (50 % = 0.5). */
	public static function toleranceFixed() {
		return self::percent('profileToleranceFixed', self::DEFAULT_TOLERANCE_FIXED) / 100;
	}

	/** Tolerance of a variable equipment, as a fraction (100 % = 1). */
	public static function toleranceVariable() {
		return self::percent('profileToleranceVariable', self::DEFAULT_TOLERANCE_VARIABLE) / 100;
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

	/** Internal ids (as keys) of the equipments that exist and can belong to a profile. */
	public static function knownIds() {
		$ids = array();
		foreach (self::equipments() as $equipment) {
			$ids[strval($equipment['id'])] = true;
		}
		return $ids;
	}

	/** The fixed equipments: {equipment id => kWh per day}. */
	public static function fixed() {
		$raw = energyRates::jsonSetting('profileFixedKwh', '{}');
		$known = self::knownIds();
		$fixed = array();
		foreach ($raw as $id => $kwh) {
			$number = floatval(str_replace(',', '.', strval($kwh)));
			if ($number > 0 && isset($known[strval($id)])) {
				$fixed[strval($id)] = $number;
			}
		}
		return $fixed;
	}

	/** The share of HC (off-peak hours) in the consumption of each equipment, in %: season => {equipment id => %} (0 = all in HP). */
	public static function hc() {
		$raw = energyRates::jsonSetting('profileHc', '{}');
		$known = self::knownIds();
		$hc = array('cold' => array(), 'hot' => array());
		foreach (array('cold', 'hot') as $season) {
			if (isset($raw[$season]) && is_array($raw[$season])) {
				foreach ($raw[$season] as $id => $pct) {
					$number = str_replace(',', '.', strval($pct));
					if (isset($known[strval($id)]) && is_numeric($number) && floatval($number) >= 0 && floatval($number) <= 100) {
						$hc[$season][strval($id)] = floatval($number);
					}
				}
			}
		}
		return $hc;
	}

	/** The share of HC of an equipment on a date (the profile of its season), in %, or null when nothing is typed (that is 0). */
	public static function hcShareOf($_date, $_id) {
		$hc = self::hc();
		$season = self::seasonOf($_date);
		return isset($hc[$season][strval($_id)]) ? $hc[$season][strval($_id)] : null;
	}

	/** The name of an equipment, to show in a message (the internal id is what identifies it everywhere else). */
	public static function nameOf($_id) {
		$energy = eqLogic::byId($_id);
		return is_object($energy) ? $energy->getHumanName() : strval($_id);
	}

	/** The shares typed by the user: season => {equipment id => %}, a 0 included (it consumes nothing that season), the fixed equipments have none. */
	public static function profile() {
		$raw = energyRates::jsonSetting('profile', '{}');
		$fixed = self::fixed();
		$known = self::knownIds();
		$profile = array('cold' => array(), 'hot' => array());
		foreach (array('cold', 'hot') as $season) {
			if (isset($raw[$season]) && is_array($raw[$season])) {
				foreach ($raw[$season] as $id => $share) {
					$number = str_replace(',', '.', strval($share));
					if ($id !== self::UNMEASURED && isset($known[strval($id)]) && !isset($fixed[strval($id)]) && is_numeric($number) && floatval($number) >= 0) {
						$profile[$season][strval($id)] = floatval($number);
					}
				}
			}
		}
		return $profile;
	}

	/** True when a profile is saved (a share or a fixed equipment): nothing can be recalculated without one. */
	public static function configured() {
		$profile = self::profile();
		return count($profile['cold']) + count($profile['hot']) + count(self::fixed()) > 0;
	}

	/** Ids (strings) of the equipments of the profile: the fixed ones and the ones with a share in a season. */
	public static function profileIds() {
		$profile = self::profile();
		$ids = array_merge(array_keys(self::fixed()), array_keys($profile['cold']), array_keys($profile['hot']));
		return array_values(array_unique(array_map('strval', $ids)));
	}

	/**
	 * Shares of a season in %, of what the variable equipments share (the total minus the fixed equipments), with the remainder as
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

	/**
	 * What every equipment of the profile consumes on a day of total $_total, with the profile of the season of the day. The fixed
	 * ones take their value (scaled down together when they exceed the total), the variable ones their share of what is left.
	 * An equipment that has no part in the profile of that season is not in the answer.
	 *
	 * @return array {equipment id => kWh}
	 */
	public static function dayValues($_date, $_total) {
		$fixed = self::fixed();
		$fixedSum = array_sum($fixed);
		$scale = ($fixedSum > $_total && $fixedSum > 0) ? $_total / $fixedSum : 1;
		$values = array();
		foreach ($fixed as $id => $kwh) {
			$values[strval($id)] = $kwh * $scale;
		}
		$rest = max(0, $_total - $fixedSum);
		foreach (self::shares(self::seasonOf($_date)) as $id => $share) {
			if ($id !== self::UNMEASURED && $share >= 0) {
				$values[strval($id)] = $share / 100 * $rest;
			}
		}
		return $values;
	}

	/** Tolerance of an equipment, as a fraction: its day may exceed its value of the profile by that much. */
	public static function toleranceOf($_id) {
		$fixed = self::fixed();
		return isset($fixed[strval($_id)]) ? self::toleranceFixed() : self::toleranceVariable();
	}

	/**
	 * Validates what the user typed and saves it.
	 *
	 * @param array $_cold months of the cold season
	 * @param array $_profile {cold: {id: %}, hot: {id: %}} shares of the variable equipments
	 * @param array $_fixed {id: kWh per day} the equipments of the fixed kind
	 * @throws Exception on an invalid value
	 */
	public static function save($_cold, $_profile, $_fixed, $_toleranceFixed, $_toleranceVariable, $_hc = array()) {
		$cold = array();
		foreach ((array) $_cold as $month) {
			if (!is_numeric($month) || intval($month) < 1 || intval($month) > 12) {
				throw new Exception(__('Mois invalide : ', __FILE__) . strval($month));
			}
			$cold[intval($month)] = intval($month);
		}
		sort($cold);
		$tolerances = array();
		foreach (array('profileToleranceFixed' => $_toleranceFixed, 'profileToleranceVariable' => $_toleranceVariable) as $key => $raw) {
			$number = str_replace(',', '.', trim(strval($raw)));
			if (!is_numeric($number) || floatval($number) < 0 || floatval($number) > 1000) {
				throw new Exception(__('Tolérance invalide (0 à 1000 %) : ', __FILE__) . strval($raw));
			}
			$tolerances[$key] = floatval($number);
		}
		$known = self::knownIds();
		$fixed = array();
		foreach ((array) $_fixed as $id => $kwh) {
			$number = str_replace(',', '.', trim(strval($kwh)));
			if (!isset($known[strval($id)])) {
				continue;
			}
			// an equipment typed as fixed must carry its value: silently dropping it would turn it back into a variable one
			if ($number === '') {
				throw new Exception(__('Un équipement fixe a besoin de sa valeur en kWh par jour : ', __FILE__) . self::nameOf($id));
			}
			if (!is_numeric($number) || floatval($number) <= 0 || floatval($number) > 10000) {
				throw new Exception(__('Valeur fixe invalide (0 à 10000 kWh par jour) : ', __FILE__) . strval($kwh));
			}
			$fixed[strval($id)] = round(floatval($number), 3);
		}
		$hc = array('cold' => array(), 'hot' => array());
		foreach (array('cold', 'hot') as $season) {
			foreach (isset($_hc[$season]) ? (array) $_hc[$season] : array() as $id => $pct) {
				$number = str_replace(',', '.', trim(strval($pct)));
				if ($number === '' || !isset($known[strval($id)])) {
					continue;
				}
				if (!is_numeric($number) || floatval($number) < 0 || floatval($number) > 100) {
					throw new Exception(__('Part d\'heures creuses invalide (0 à 100 %) : ', __FILE__) . strval($pct));
				}
				$hc[$season][strval($id)] = round(floatval($number), 1);
			}
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
				// a 0 is kept: the equipment consumes nothing that season, which is not the same as having no share
				$clean[$season][strval($id)] = round(floatval($number), 2);
			}
			if ($sum > 100.05) {
				throw new Exception(__('La somme des parts dépasse 100 % : ', __FILE__) . round($sum, 2) . ' % (' . ($season == 'cold' ? __('saison froide', __FILE__) : __('saison chaude', __FILE__)) . ')');
			}
		}
		config::save('profileSeasons', json_encode(array('cold' => array_values($cold))), 'energy');
		config::save('profile', json_encode($clean), 'energy');
		config::save('profileFixedKwh', json_encode($fixed), 'energy');
		config::save('profileHc', json_encode($hc), 'energy');
		config::save('profileToleranceFixed', $tolerances['profileToleranceFixed'], 'energy');
		config::save('profileToleranceVariable', $tolerances['profileToleranceVariable'], 'energy');
		self::log('info', 'profile saved: cold months ' . implode(',', $cold) . ', shares ' . json_encode($clean) . ', fixed (kWh/day) ' . json_encode($fixed) . ', HC share ' . json_encode($hc) . ', tolerances ' . json_encode($tolerances));
	}

	/* ------------------------------------------------------------------ reading the days */

	/** {date => value} of the rows of a history (the rows are [date, value]). */
	public static function byDate($_rows) {
		$days = array();
		foreach ((array) $_rows as $row) {
			if (isset($row[0]) && isset($row[1]) && is_numeric($row[1])) {
				$days[substr(strval($row[0]), 0, 10)] = floatval($row[1]);
			}
		}
		ksort($days);
		return $days;
	}

	/**
	 * The total counter over a range: its consumption and its cost by day, the days before "counterReliableSince" left out.
	 *
	 * @return array|null {totals, costs} (each {date => value}), null without a total counter
	 */
	public static function counterDays($_start, $_end) {
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
		return array('totals' => $read('consumptionTotal'), 'costs' => $read('cost'), 'hc' => $read('consumptionHc'), 'hp' => $read('consumptionHp'));
	}

	/**
	 * Categories of an equipment and their weights: its measured days (the ones not in $_skip), else its declared lines.
	 *
	 * @param array $_data what energy::getData() returned
	 * @param array $_skip {date => true} the days that are going to be rewritten
	 * @return array {category => weight}, the weights add up to 1
	 */
	public static function weights($_energy, $_data, $_skip) {
		$sums = array();
		foreach (self::CATEGORIES as $category) {
			$sum = 0;
			foreach (isset($_data[$category]) ? (array) $_data[$category] : array() as $row) {
				if (isset($row[1]) && is_numeric($row[1]) && !isset($_skip[substr(strval($row[0]), 0, 10)])) {
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

	/* ------------------------------------------------------------------ analysis */

	public static function checkRange($_from, $_to) {
		if (!energyCatchup::validDate($_from) || !energyCatchup::validDate($_to) || $_from > $_to) {
			throw new Exception(__('Plage de dates invalide : ', __FILE__) . $_from . ' → ' . $_to);
		}
		if (count(energyCatchup::daysBetween($_from, $_to)) >= energyCatchup::MAX_DAYS) {
			throw new Exception(__('Plage de dates trop longue.', __FILE__));
		}
	}

	/**
	 * Detects the equipments of the fixed kind over the days of a range: a constant daily value (the same within 10 % on at least
	 * 80 % of the days it consumed), on at least 3 days and on at least half of the days of the range. Its value is the median day.
	 * A day above the total of the house is not a measure, it is left out.
	 *
	 * @param array $_series {id => {date => kWh}}
	 * @param array $_totals {date => total of the house}: the days kept
	 * @return array {id => kWh per day}
	 */
	public static function detectFixed($_series, $_totals) {
		$fixed = array();
		foreach ($_series as $id => $values) {
			$positive = array();
			foreach ($_totals as $date => $total) {
				if (isset($values[$date]) && $values[$date] > 0 && $values[$date] <= $total) {
					$positive[] = $values[$date];
				}
			}
			$count = count($positive);
			if ($count < self::FIXED_MIN_DAYS || $count < count($_totals) / 2) {
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
				$fixed[strval($id)] = round($median, 3);
			}
		}
		return $fixed;
	}

	/**
	 * Computes a profile over the days of a range: the fixed equipments are DETECTED (a constant day, their value in kWh), then the
	 * variable ones get their share of what the total records beyond the fixed ones. The days where the equipments add up to more
	 * than the total are left out (one of them measured badly, which one is not known).
	 *
	 * @return array {from, to, days, dropped{date => reason}, shares{id => %}, fixed{id => kWh}, hc{id => % of HC}, unmeasured, kwh, fixedKwh}
	 */
	public static function analyze($_from, $_to) {
		self::checkRange($_from, $_to);
		$counter = self::counterDays($_from, $_to);
		if ($counter === null) {
			throw new Exception(__('Aucun compteur total pour calculer le profil.', __FILE__));
		}
		$series = array();
		foreach (self::equipments() as $equipment) {
			$energy = eqLogic::byId($equipment['id']);
			$cmd = is_object($energy) ? $energy->getCmd(null, 'consumptionTotal') : null;
			$series[strval($equipment['id'])] = is_object($cmd) ? self::byDate($cmd->getHistoryEnergy($_from, $_to)) : array();
		}
		$totals = array();
		foreach ($counter['totals'] as $date => $value) {
			if ($date >= $_from && $date <= $_to && $value > 0) {
				$totals[$date] = $value;
			}
		}
		// 1. the days where the equipments add up to more than the total of the house are left out
		$dropped = array();
		$kept = array();
		foreach ($totals as $date => $total) {
			$sum = 0;
			foreach ($series as $values) {
				$sum += isset($values[$date]) ? $values[$date] : 0;
			}
			if ($sum > $total) {
				$dropped[$date] = 'aberrant';
			} else {
				$kept[$date] = $total;
			}
		}
		// 2. the equipments of the fixed kind, then the share of the others in what the fixed ones leave
		$fixed = self::detectFixed($series, $kept);
		$fixedTotal = array_sum($fixed);
		$sums = array_fill_keys(array_keys(array_diff_key($series, $fixed)), 0);
		$total = 0;
		$base = 0;
		foreach ($kept as $date => $value) {
			foreach ($sums as $id => $kwh) {
				$sums[$id] += isset($series[$id][$date]) ? $series[$id][$date] : 0;
			}
			$total += $value;
			$base += max(0, $value - $fixedTotal);
		}
		$days = count($kept);
		// the share of HC of every equipment, from its own HC and HP history over the days kept (none when it has neither)
		$hc = array();
		foreach (array_keys($series) as $id) {
			$energy = eqLogic::byId($id);
			$cmdHc = is_object($energy) ? $energy->getCmd(null, 'consumptionHc') : null;
			$cmdHp = is_object($energy) ? $energy->getCmd(null, 'consumptionHp') : null;
			$hcDays = is_object($cmdHc) ? self::byDate($cmdHc->getHistoryEnergy($_from, $_to)) : array();
			$hpDays = is_object($cmdHp) ? self::byDate($cmdHp->getHistoryEnergy($_from, $_to)) : array();
			$sumHc = 0;
			$sumHp = 0;
			foreach ($kept as $date => $value) {
				$sumHc += isset($hcDays[$date]) ? $hcDays[$date] : 0;
				$sumHp += isset($hpDays[$date]) ? $hpDays[$date] : 0;
			}
			if ($sumHc + $sumHp > 0) {
				$hc[strval($id)] = round(100 * $sumHc / ($sumHc + $sumHp), 1);
			}
		}
		$shares = array();
		$sum = 0;
		foreach ($sums as $id => $kwh) {
			$shares[strval($id)] = $base > 0 ? round($kwh * 100 / $base, 2) : 0;
			$sum += $shares[strval($id)];
		}
		if ($sum > 100) {
			// the equipments declare more than the total: scaled down, nothing is left unmeasured
			foreach ($shares as $id => $share) {
				$shares[$id] = round($share * 100 / $sum, 2);
			}
			$sum = array_sum($shares);
		}
		self::log('info', 'analysis ' . $_from . ' → ' . $_to . ': ' . $days . ' day(s) kept, ' . count($dropped) . ' dropped, ' . round($total, 1) . ' kWh of which ' . round($fixedTotal * $days, 1) . ' fixed, fixed (kWh/day) ' . json_encode($fixed) . ', shares ' . json_encode($shares) . ', HC share ' . json_encode($hc));
		return array('from' => $_from, 'to' => $_to, 'days' => $days, 'dropped' => $dropped, 'shares' => $shares, 'fixed' => $fixed, 'hc' => $hc, 'unmeasured' => round(max(0, 100 - $sum), 2), 'kwh' => round($total, 2), 'fixedKwh' => round($fixedTotal * $days, 2));
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
			'hc' => self::hc(),
			'variableRate' => energyRates::forDate(date('Y-m-d'))['variable'],
			'cold' => self::coldMonths(),
			'profile' => array('cold' => self::shares('cold'), 'hot' => self::shares('hot')),
			'typed' => self::profile(),
			'seasons' => $seasons,
			'toleranceFixed' => self::toleranceFixed() * 100,
			'toleranceVariable' => self::toleranceVariable() * 100,
			'from' => $from,
			'to' => $to,
		);
	}
}

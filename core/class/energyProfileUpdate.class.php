<?php
/*
 * RECALCULATION OF THE CONSUMPTIONS OF THE EQUIPMENTS WITH THE PROFILES
 *
 * Writes in the history of the equipments of the profile (see energyProfile) the consumption that the profile of the season gives them,
 * over a range of days. Two rules come first:
 *  - the TOTAL of the day (the total counter: Enedis, or the index of the Linky) must be known: a day without a total is left alone;
 *  - the profile of the day is the one of its season: hot or cold, day by day.
 * Then, for every equipment of the profile, the day is one of three kinds, and the user chooses which ones are rewritten:
 *  - blank     : no value, or 0
 *  - aberrant  : the value is above its value of the profile by more than the tolerance (50 % for a fixed equipment, 100 % for a
 *                variable one), OR the equipments of the profile add up to more than the total of the day (then it is aberrant for all
 *                of them: which one is wrong is not known)
 *  - filled    : a plausible value that already exists
 *
 * A rewritten day replaces the rows of the day by ONE row at 12:00:00 for the consumption, its categories (shared like the
 * equipment shares them over its measured days), its HC and HP and its cost, like the import from Enedis. The tariff of the day decides:
 *  - HP/HC tariff : the equipment has a share of HC in its profile (else the share of HC of the counter that day): its HC and HP are
 *                   written and its cost is HC x rate HC + HP x rate HP;
 *  - fixed tariff : no split, the cost is the consumption x the fixed rate;
 *  - no usable rate (0) : the cost is its share of the cost of the counter of the day.
 *
 * It uses the tools of energyCatchup: the same backups (data/backup, same format, restored from the same list of the configuration
 * page), the same states, the same log prefix, and one step per ajax call: check, backup (written and read back: nothing is written
 * without it), write, verify. A backup restored puts the history of the equipments back as it was.
 */
class energyProfileUpdate {

	const STEPS = array(
		'check' => 'Contrôle des paramètres',
		'backup' => 'Sauvegarde',
		'write' => 'Écriture',
		'verify' => 'Vérification',
	);

	/** The command of each category of consumption of an equipment. */
	const CATEGORY_COMMANDS = array(
		'other' => 'consumptionOther',
		'light' => 'consumptionLight',
		'multimedia' => 'consumptionMultimedia',
		'automatism' => 'consumptionAutomatism',
		'electrical' => 'consumptionElectrical',
		'heating' => 'consumptionHeating',
	);

	/** Under this gap (kWh) the equipments add up to the total: a rounding, not an excess. */
	const EPSILON = 0.001;

	/* ------------------------------------------------------------------ choices of the user */

	/**
	 * Which kinds of day are rewritten: blank and aberrant by default, filled only when asked.
	 *
	 * @return array {blank, aberrant, filled} (bool)
	 */
	public static function options($_params) {
		$params = (array) $_params;
		$flag = function ($key, $default) use ($params) {
			if (!isset($params[$key])) {
				return $default;
			}
			return $params[$key] === true || $params[$key] === 1 || $params[$key] === '1' || $params[$key] === 'true';
		};
		return array('blank' => $flag('blank', true), 'aberrant' => $flag('aberrant', true), 'filled' => $flag('filled', false));
	}

	/** The range must end yesterday at the latest: the cron writes the day of today. */
	public static function checkRange($_from, $_to) {
		energyProfile::checkRange($_from, $_to);
		$now = energyRuntime::$now !== null ? energyRuntime::$now : time();
		$yesterday = energyCatchup::addDays(date('Y-m-d', $now), -1);
		if ($_to > $yesterday) {
			throw new Exception(__('La plage doit se terminer hier au plus tard : ', __FILE__) . $yesterday);
		}
	}

	/** 'blank', 'aberrant' or 'filled' for a day of an equipment. */
	public static function status($_current, $_target, $_tolerance, $_sumExceeds) {
		if ($_current === null || $_current <= 0) {
			return 'blank';
		}
		if ($_sumExceeds || $_current > $_target * (1 + $_tolerance)) {
			return 'aberrant';
		}
		return 'filled';
	}

	/**
	 * HC, HP and cost of a rewritten day of an equipment, with the tariff of that day.
	 *
	 * @param float|null $_counterHc HC of the total counter that day, to fall back on when the profile has no share of HC
	 * @return array {cost, hc, hp} (hc and hp are null without a split: fixed tariff, or no share known)
	 */
	public static function split($_date, $_id, $_value, $_total, $_counterCost, $_counterHc, $_counterHp) {
		$tariff = energyRates::forDate($_date);
		$fallback = $_total > 0 ? $_value / $_total * $_counterCost : 0;
		if (!$tariff['variable']) {
			return array('cost' => $tariff['rate'] > 0 ? $_value * $tariff['rate'] : $fallback, 'hc' => null, 'hp' => null);
		}
		$pct = energyProfile::hcShareOf($_date, $_id);
		if ($pct === null && $_counterHc !== null && $_counterHp !== null && $_counterHc + $_counterHp > 0) {
			$pct = 100 * $_counterHc / ($_counterHc + $_counterHp);
		}
		if ($pct === null) {
			return array('cost' => $fallback, 'hc' => null, 'hp' => null);
		}
		$hc = $_value * $pct / 100;
		$hp = $_value - $hc;
		$cost = ($tariff['rateHc'] > 0 || $tariff['rateHp'] > 0) ? $hc * $tariff['rateHc'] + $hp * $tariff['rateHp'] : $fallback;
		return array('cost' => $cost, 'hc' => $hc, 'hp' => $hp);
	}

	/** The commands of an equipment that a rewritten day replaces: logicalId => command id. */
	private static function commandsOf($_energy) {
		$cmds = array();
		foreach (array_merge(array('consumptionTotal', 'cost', 'consumptionHc', 'consumptionHp'), array_values(self::CATEGORY_COMMANDS)) as $logicalId) {
			$cmd = $_energy->getCmd(null, $logicalId);
			if (is_object($cmd)) {
				$cmds[$logicalId] = $cmd->getId();
			}
		}
		return $cmds;
	}

	/* ------------------------------------------------------------------ analysis */

	/**
	 * The plan: for each day with a total and each equipment of the profile, what it holds now, what the profile gives, its kind
	 * and whether it is rewritten. Reads only.
	 *
	 * @return array {items[], written[], weights{}, summary{}, text}
	 */
	public static function preview($_from, $_to, $_options) {
		$options = self::options($_options);
		$lines = array();
		if (!energyProfile::configured()) {
			throw new Exception(__('Aucun profil enregistré : enregistrez les profils avant de recalculer.', __FILE__));
		}
		self::checkRange($_from, $_to);
		$counter = energyProfile::counterDays($_from, $_to);
		if ($counter === null) {
			throw new Exception(__('Aucun compteur total : le total du jour est nécessaire.', __FILE__));
		}
		$names = array();
		foreach (energyProfile::equipments() as $equipment) {
			$names[strval($equipment['id'])] = $equipment['name'];
		}
		$energies = array();
		$data = array();
		$series = array();
		foreach (energyProfile::profileIds() as $id) {
			$energy = eqLogic::byId($id);
			if (!is_object($energy) || !isset($names[$id])) {
				continue;
			}
			$energies[$id] = $energy;
			$data[$id] = $energy->getData($_from . ' 00:00:00', $_to);
			$series[$id] = energyProfile::byDate(isset($data[$id]['consumptionTotal']) ? $data[$id]['consumptionTotal'] : array());
		}
		energyCatchup::say($lines, 'info', '[profile] preview: ' . $_from . ' → ' . $_to . ', ' . count($energies) . ' equipment(s) of the profile, rewritten: ' . json_encode($options));
		$items = array();
		$summary = array('from' => $_from, 'to' => $_to, 'days' => 0, 'noTotal' => 0, 'blank' => 0, 'aberrant' => 0, 'filled' => 0, 'write' => 0, 'keep' => 0, 'before' => 0, 'after' => 0, 'daysWritten' => 0);
		$daysWritten = array();
		$written = array();
		foreach (energyCatchup::daysBetween($_from, $_to) as $date) {
			$total = isset($counter['totals'][$date]) ? $counter['totals'][$date] : 0;
			if ($total <= 0) {
				$summary['noTotal']++;
				energyCatchup::say($lines, 'debug', '[profile] ' . $date . ' ignored: no total for the day');
				continue;
			}
			$summary['days']++;
			$cost = isset($counter['costs'][$date]) ? $counter['costs'][$date] : 0;
			$sum = 0;
			foreach ($series as $values) {
				$sum += isset($values[$date]) ? $values[$date] : 0;
			}
			$exceeds = $sum > $total + self::EPSILON;
			foreach (energyProfile::dayValues($date, $total) as $id => $target) {
				if (!isset($energies[$id])) {
					continue;
				}
				$current = isset($series[$id][$date]) ? $series[$id][$date] : null;
				$status = self::status($current, $target, energyProfile::toleranceOf($id), $exceeds);
				$write = $options[$status];
				$summary[$status]++;
				$summary[$write ? 'write' : 'keep']++;
				if ($write) {
					$summary['before'] += $current === null ? 0 : $current;
					$summary['after'] += $target;
					$daysWritten[$date] = true;
					$written[$id][$date] = true;
				}
				$split = self::split($date, $id, $target, $total, $cost, isset($counter['hc'][$date]) ? $counter['hc'][$date] : null, isset($counter['hp'][$date]) ? $counter['hp'][$date] : null);
				$items[] = array('date' => $date, 'id' => $id, 'name' => $names[$id], 'current' => $current, 'target' => $target, 'status' => $status, 'write' => $write, 'total' => $total, 'cost' => $split['cost'], 'hc' => $split['hc'], 'hp' => $split['hp'], 'exceeds' => $exceeds);
				energyCatchup::say($lines, 'debug', '[profile] ' . $date . ' equipment ' . $id . ' ' . $status . ' now ' . ($current === null ? 'none' : $current) . ' profile ' . round($target, 4) . ' (total ' . $total . ($exceeds ? ', the equipments add up to ' . round($sum, 3) : '') . ') => ' . ($write ? 'rewritten' : 'kept'));
			}
		}
		$summary['daysWritten'] = count($daysWritten);
		$summary['before'] = round($summary['before'], 3);
		$summary['after'] = round($summary['after'], 3);
		$weights = array();
		foreach ($energies as $id => $energy) {
			$weights[$id] = energyProfile::weights($energy, $data[$id], isset($written[$id]) ? $written[$id] : array());
		}
		energyCatchup::say($lines, 'info', '[profile] preview done: ' . $summary['days'] . ' day(s) with a total, ' . $summary['noTotal'] . ' without, ' . $summary['write'] . ' equipment day(s) rewritten, ' . $summary['keep'] . ' kept (blank ' . $summary['blank'] . ', aberrant ' . $summary['aberrant'] . ', filled ' . $summary['filled'] . ')');
		$text = array('ANALYSE DU RECALCUL AVEC LES PROFILS', 'Plage : ' . $_from . ' → ' . $_to, 'Jours avec un total : ' . $summary['days'] . ' · sans total (ignorés) : ' . $summary['noTotal'],
			'Réécrits : jours vierges ' . ($options['blank'] ? 'oui' : 'non') . ', aberrants ' . ($options['aberrant'] ? 'oui' : 'non') . ', déjà renseignés ' . ($options['filled'] ? 'oui' : 'non'),
			'Équipements-jours : ' . $summary['write'] . ' réécrits, ' . $summary['keep'] . ' conservés (vierges ' . $summary['blank'] . ', aberrants ' . $summary['aberrant'] . ', renseignés ' . $summary['filled'] . ')',
			'Consommation réécrite : ' . $summary['before'] . ' kWh → ' . $summary['after'] . ' kWh', '',
			'JOURS (date | équipement | actuel | profil | HC | HP | coût | statut | action)');
		foreach ($items as $item) {
			$text[] = $item['date'] . ' | ' . $item['name'] . ' | ' . ($item['current'] === null ? '-' : round($item['current'], 3)) . ' | ' . round($item['target'], 3) . ' | ' . ($item['hc'] === null ? '-' : round($item['hc'], 3)) . ' | ' . ($item['hp'] === null ? '-' : round($item['hp'], 3)) . ' | ' . round($item['cost'], 4) . ' | ' . $item['status'] . ($item['exceeds'] && $item['status'] == 'aberrant' ? ' (somme > total)' : '') . ' | ' . ($item['write'] ? 'réécrit' : 'conservé');
		}
		$text[] = '';
		$text[] = 'JOURNAL';
		return array('items' => $items, 'weights' => $weights, 'summary' => $summary, 'text' => implode("\n", array_merge($text, $lines)));
	}

	/* ------------------------------------------------------------------ the steps */

	/**
	 * Runs ONE step. The first step records the choices and computes the plan; the next ones only read that state, never the browser.
	 *
	 * @return array {id, step, label, ok, done, next, message, text, backup}
	 */
	public static function runStep($_importId, $_step, $_from = '', $_to = '', $_options = array()) {
		if (!isset(self::STEPS[$_step])) {
			throw new Exception(__('Étape inconnue : ', __FILE__) . $_step);
		}
		if ($_step == 'check' && !energyCatchup::validId($_importId)) {
			// a new run: the identifier is made on the server
			$_importId = bin2hex(random_bytes(16));
		}
		if (!energyCatchup::validId($_importId)) {
			throw new Exception(__('Identifiant d\'import invalide.', __FILE__));
		}
		$names = array_keys(self::STEPS);
		$started = microtime(true);
		$lines = array();
		if ($_step == 'check') {
			$preview = self::preview($_from, $_to, $_options);
			$plan = array_values(array_filter($preview['items'], function ($item) {
				return $item['write'];
			}));
			$state = array('kind' => 'profile', 'from' => $_from, 'to' => $_to, 'options' => self::options($_options), 'plan' => $plan, 'weights' => $preview['weights'], 'summary' => $preview['summary'], 'done' => array(), 'log' => array(), 'created' => date('Y-m-d H:i:s'));
		} else {
			$state = energyCatchup::loadState($_importId);
			if ($state === null || !isset($state['kind']) || $state['kind'] != 'profile' || !in_array('check', $state['done'])) {
				throw new Exception(__('Le recalcul n\'a pas été contrôlé : recommencez.', __FILE__));
			}
			$previous = array_search($_step, $names) - 1;
			if (!in_array($names[$previous], $state['done']) || in_array($_step, $state['done'])) {
				throw new Exception(__('Étape hors ordre : ', __FILE__) . $_step);
			}
		}
		energyCatchup::say($lines, 'info', '[profile] step "' . $_step . '" start');
		try {
			$message = self::{'step' . ucfirst($_step)}($state, $lines);
		} catch (Exception $e) {
			energyCatchup::say($lines, 'error', '[profile] step "' . $_step . '" failed: ' . $e->getMessage());
			$state['log'] = array_merge($state['log'], $lines);
			if ($_step != 'check') {
				energyCatchup::saveState($_importId, $state);
			}
			return array('id' => $_importId, 'step' => $_step, 'label' => self::STEPS[$_step], 'ok' => false, 'done' => true, 'next' => null, 'message' => $e->getMessage(), 'text' => implode("\n", $state['log']), 'backup' => isset($state['backup']) ? $state['backup'] : null);
		}
		$state['done'][] = $_step;
		energyCatchup::say($lines, 'info', '[profile] step "' . $_step . '" done in ' . round((microtime(true) - $started) * 1000) . ' ms: ' . $message);
		$state['log'] = array_merge($state['log'], $lines);
		energyCatchup::saveState($_importId, $state);
		$index = array_search($_step, $names);
		$finished = !isset($names[$index + 1]);
		if ($finished) {
			energyCatchup::say($state['log'], 'info', '[profile] recalculation done: ' . count($state['plan']) . ' equipment day(s), backup ' . $state['backup']);
			energyCatchup::discard($_importId);
		}
		return array('id' => $_importId, 'step' => $_step, 'label' => self::STEPS[$_step], 'ok' => true, 'done' => $finished, 'next' => $finished ? null : $names[$index + 1], 'message' => $message, 'text' => implode("\n", $state['log']), 'backup' => isset($state['backup']) ? $state['backup'] : null);
	}

	private static function stepCheck(&$_state, &$_lines) {
		if (count($_state['plan']) == 0) {
			throw new Exception(__('Rien à réécrire : aucun jour de la plage n\'entre dans les cases choisies.', __FILE__));
		}
		return count($_state['plan']) . ' équipement(s)-jour(s) à réécrire sur ' . $_state['summary']['daysWritten'] . ' jour(s)';
	}

	private static function stepBackup(&$_state, &$_lines) {
		$cmds = array();
		$dates = array();
		foreach ($_state['plan'] as $item) {
			$energy = eqLogic::byId($item['id']);
			if (!is_object($energy)) {
				throw new Exception(__('Équipement introuvable : ', __FILE__) . $item['id']);
			}
			foreach (self::commandsOf($energy) as $logicalId => $cmdId) {
				$cmds[$item['id'] . ':' . $logicalId] = $cmdId;
			}
			$dates[$item['date']] = true;
		}
		ksort($dates);
		$from = key($dates);
		end($dates);
		$to = key($dates);
		$rows = energyCatchup::readRowsOf(array_values($cmds), $from, $to);
		$name = 'catchup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json';
		$data = array(
			'version' => 1,
			'kind' => 'profile',
			'created' => date('Y-m-d H:i:s'),
			// every command of every equipment written is saved, and so erased then restored
			'cmds' => $cmds,
			'from' => $from,
			'to' => $to,
			'reliableOld' => '',
			'reliableChanged' => false,
			'writtenDays' => array_keys($dates),
			// what made the new values: the profiles as they were at that moment
			'profile' => array('seasons' => energyProfile::coldMonths(), 'shares' => energyProfile::profile(), 'fixed' => energyProfile::fixed()),
			'rows' => $rows,
		);
		$path = energyCatchup::backupPath($name);
		$json = json_encode($data);
		if ($json === false || @file_put_contents($path, $json) === false) {
			throw new Exception(__('La sauvegarde n\'a pas pu être écrite : le recalcul est annulé, rien n\'a été modifié.', __FILE__));
		}
		// read it back: nothing is written without a backup that can be read
		$back = json_decode(strval(@file_get_contents($path)), true);
		if (!is_array($back) || count($back['rows']) != count($rows) || filesize($path) != strlen($json)) {
			@unlink($path);
			throw new Exception(__('La sauvegarde relue ne correspond pas : le recalcul est annulé, rien n\'a été modifié.', __FILE__));
		}
		$_state['backup'] = $name;
		energyCatchup::say($_lines, 'debug', '[profile] backup file ' . $name . ': ' . count($rows) . ' existing rows, ' . count($cmds) . ' command(s), ' . $from . ' → ' . $to);
		return $name . ' (' . count($rows) . ' ligne(s) existantes)';
	}

	private static function stepWrite(&$_state, &$_lines) {
		$cmdsOf = array();
		$count = 0;
		foreach ($_state['plan'] as $item) {
			$id = strval($item['id']);
			if (!isset($cmdsOf[$id])) {
				$energy = eqLogic::byId($id);
				if (!is_object($energy)) {
					throw new Exception(__('Équipement introuvable : ', __FILE__) . $id);
				}
				$cmdsOf[$id] = self::commandsOf($energy);
			}
			$cmds = $cmdsOf[$id];
			$at = $item['date'] . ' 12:00:00';
			// the rows of the day are replaced by one row at noon for each command
			energyCatchup::deleteRowsOf(array_values($cmds), $item['date'], $item['date']);
			if (isset($cmds['consumptionTotal'])) {
				energyCatchup::insertRow('history', $cmds['consumptionTotal'], $at, round($item['target'], 6));
			}
			if (isset($cmds['cost'])) {
				energyCatchup::insertRow('history', $cmds['cost'], $at, round($item['cost'], 6));
			}
			// the split of the day in off-peak (HC) and peak (HP) hours, with an HP/HC tariff
			if ($item['hc'] !== null && isset($cmds['consumptionHc']) && isset($cmds['consumptionHp'])) {
				energyCatchup::insertRow('history', $cmds['consumptionHc'], $at, round($item['hc'], 6));
				energyCatchup::insertRow('history', $cmds['consumptionHp'], $at, round($item['hp'], 6));
			}
			$weights = isset($_state['weights'][$id]) ? $_state['weights'][$id] : array();
			foreach ($weights as $category => $weight) {
				$logicalId = self::CATEGORY_COMMANDS[$category];
				if (isset($cmds[$logicalId])) {
					energyCatchup::insertRow('history', $cmds[$logicalId], $at, round($item['target'] * $weight, 6));
				}
			}
			$count++;
			energyCatchup::say($_lines, 'debug', '[profile] written ' . $item['date'] . ' equipment ' . $id . ': ' . ($item['current'] === null ? 'none' : round($item['current'], 4)) . ' → ' . round($item['target'], 4) . ' kWh');
		}
		return $count . ' équipement(s)-jour(s)';
	}

	private static function stepVerify(&$_state, &$_lines) {
		$bad = array();
		$byId = array();
		foreach ($_state['plan'] as $item) {
			$byId[$item['id']][] = $item;
		}
		$sum = 0;
		foreach ($byId as $id => $items) {
			$energy = eqLogic::byId($id);
			$cmd = is_object($energy) ? $energy->getCmd(null, 'consumptionTotal') : null;
			$read = is_object($cmd) ? energyProfile::byDate($cmd->getHistoryEnergy($_state['from'] . ' 00:00:00', $_state['to'])) : array();
			foreach ($items as $item) {
				$value = isset($read[$item['date']]) ? $read[$item['date']] : null;
				$sum += $item['target'];
				if ($value === null || abs($value - round($item['target'], 6)) > 0.0001) {
					$bad[] = $item['date'] . ' équipement ' . $id . ' (attendu ' . round($item['target'], 4) . ', lu ' . ($value === null ? 'rien' : round($value, 4)) . ')';
				}
			}
		}
		if (count($bad) > 0) {
			energyCatchup::say($_lines, 'error', '[profile] verification: ' . count($bad) . ' value(s) differ: ' . implode('; ', array_slice($bad, 0, 20)));
			throw new Exception(count($bad) . ' valeur(s) différentes après écriture : ' . implode(', ', array_slice($bad, 0, 5)) . '. Restaurez la sauvegarde ' . $_state['backup'] . '.');
		}
		return count($_state['plan']) . ' valeur(s) relues, ' . round($sum, 2) . ' kWh';
	}
}

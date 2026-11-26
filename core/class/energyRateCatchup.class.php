<?php
/*
 * COST CATCH-UP OF A TARIFF PERIOD
 *
 * Recomputes the stored cost of the days of a tariff period (see energyRates) from the HP and HC energy already in the
 * history: cost = HP x rate HP + HC x rate HC of the period (fixed tariff: total x rate). Only the command "cost" is
 * written; the energy, the HP/HC and the caught-up flag are never touched. The subscription is not part of the command
 * "cost" (the panel adds it by day), so it is not touched either.
 *
 * It uses the tools of energyCatchup: the same backups (data/backup, same format, restored from the same list), the same
 * log prefix and the same steps one per ajax call. The backup is written and read back before anything is written.
 */
class energyRateCatchup {

	const STEPS = array(
		'check' => 'Contrôle des paramètres',
		'backup' => 'Sauvegarde',
		'cost' => 'Coût',
		'verify' => 'Vérification',
	);

	/** The days to compute for a tariff period: its dates, ending the day before today at the latest. */
	public static function range($_from) {
		$period = energyRates::periodFor($_from);
		if ($period === null || $period['from'] !== $_from) {
			throw new Exception(__('Plage de tarification introuvable (enregistrez les plages avant de recalculer) : ', __FILE__) . $_from);
		}
		$yesterday = energyCatchup::addDays(date('Y-m-d', (energyRuntime::$now !== null) ? energyRuntime::$now : time()), -1);
		$last = $period['to'] === '' ? $yesterday : min(energyCatchup::addDays($period['to'], -1), $yesterday);
		if ($last < $period['from']) {
			throw new Exception(__('Cette plage ne contient encore aucun jour passé.', __FILE__));
		}
		return array($period['from'], $last);
	}

	/** Value of a command by day over [from, to]: date => value. */
	private static function valuesByDay($_energy, $_logicalId, $_from, $_to) {
		$cmd = $_energy->getCmd(null, $_logicalId);
		$days = array();
		if (is_object($cmd)) {
			foreach ((array) $cmd->getHistoryEnergy($_from . ' 00:00:00', $_to) as $row) {
				if (is_numeric($row[1])) {
					$days[substr($row[0], 0, 10)] = floatval($row[1]);
				}
			}
		}
		return $days;
	}

	/** The tariffs of a day, as shown in the list of the backups: {from, to, rateHp, rateHc, rate, subscriptionDaily, variable}. */
	public static function tariffSummary($_date) {
		$tariff = energyRates::forDate($_date);
		$period = energyRates::periodFor($_date);
		return array(
			'from' => $period === null ? '' : $period['from'],
			'to' => $period === null ? '' : (isset($period['to']) ? $period['to'] : ''),
			'variable' => $tariff['variable'],
			'rateHp' => $tariff['rateHp'],
			'rateHc' => $tariff['rateHc'],
			'rate' => $tariff['rate'],
			'subscriptionDaily' => $tariff['subscriptionDaily'],
		);
	}

	/** The rows of a command over [from, to]: date => list of [table, datetime, value], as stored. */
	private static function rowsByDay($_costId, $_from, $_to) {
		$days = array();
		foreach (energyCatchup::readRowsOf(array($_costId), $_from, $_to) as $row) {
			$days[substr($row[2], 0, 10)][] = $row;
		}
		return $days;
	}

	/**
	 * The plan: for each day with a total, the cost now stored, the new cost and the gap. Reads only.
	 *
	 * @return array {days[], summary{}, text}
	 */
	public static function preview($_energyId, $_from) {
		$lines = array();
		$energy = energyCatchup::target($_energyId);
		list($from, $to) = self::range($_from);
		$tariff = energyRates::forDate($from);
		energyCatchup::say($lines, 'info', '[rates] preview: equipment ' . $energy->getId() . ', period ' . $from . ' → ' . $to . ', tariffs ' . json_encode(array('variable' => $tariff['variable'], 'hp' => $tariff['rateHp'], 'hc' => $tariff['rateHc'], 'fixed' => $tariff['rate'])));
		$total = self::valuesByDay($energy, 'consumptionTotal', $from, $to);
		$hp = self::valuesByDay($energy, 'consumptionHp', $from, $to);
		$hc = self::valuesByDay($energy, 'consumptionHc', $from, $to);
		$stored = self::valuesByDay($energy, 'cost', $from, $to);
		$costId = energyCatchup::commands($energy)['cost'];
		$rowsOfDay = self::rowsByDay($costId, $from, $to);
		$share = null;
		foreach ($total as $date => $kwh) {
			if (isset($hp[$date]) && isset($hc[$date]) && $hp[$date] + $hc[$date] > 0) {
				$share = $hc[$date] / ($hp[$date] + $hc[$date]);
			}
		}
		$days = array();
		$sum = array('days' => 0, 'withoutSplit' => 0, 'current' => 0, 'new' => 0);
		foreach (energyCatchup::daysBetween($from, $to) as $date) {
			if (!isset($total[$date]) || $total[$date] <= 0) {
				continue;
			}
			$rates = energyCatchup::rates($date);
			$known = isset($hp[$date]) && isset($hc[$date]);
			$new = energyCatchup::dayCost($total[$date], $known ? $hc[$date] : null, $known ? $hp[$date] : null, $share, $rates);
			$current = isset($stored[$date]) ? $stored[$date] : 0;
			// a day measured by the plugin has several rows (a counter of the day, one per cycle): they are kept and scaled
			$measured = isset($rowsOfDay[$date]) && count($rowsOfDay[$date]) > 1 && $current > 0;
			$days[] = array('date' => $date, 'kwh' => $total[$date], 'hc' => $known ? $hc[$date] : null, 'hp' => $known ? $hp[$date] : null, 'current' => $current, 'new' => $new, 'origin' => $measured ? 'plugin' : 'import', 'points' => isset($rowsOfDay[$date]) ? count($rowsOfDay[$date]) : 0);
			$sum['days']++;
			$sum['withoutSplit'] += $known ? 0 : 1;
			$sum['current'] += $current;
			$sum['new'] += $new;
			energyCatchup::say($lines, 'debug', '[rates] ' . $date . ' ' . ($measured ? 'measured by the plugin, ' . count($rowsOfDay[$date]) . ' points scaled' : 'one point') . ' kwh=' . $total[$date] . ' hc=' . var_export($known ? $hc[$date] : null, true) . ' hp=' . var_export($known ? $hp[$date] : null, true) . ' cost ' . round($current, 4) . ' => ' . round($new, 4));
		}
		if ($sum['days'] == 0) {
			throw new Exception(__('Aucun jour avec une consommation sur cette plage : rien à recalculer.', __FILE__));
		}
		$sum['measured'] = count(array_filter($days, function ($d) {
			return $d['origin'] == 'plugin';
		}));
		$sum['current'] = round($sum['current'], 2);
		$sum['new'] = round($sum['new'], 2);
		$sum['gap'] = round($sum['new'] - $sum['current'], 2);
		$sum['gapPercent'] = $sum['current'] > 0 ? round(100 * ($sum['new'] - $sum['current']) / $sum['current'], 2) : null;
		$sum['from'] = $from;
		$sum['to'] = $to;
		energyCatchup::say($lines, 'info', '[rates] preview done: ' . $sum['days'] . ' day(s), cost ' . $sum['current'] . ' => ' . $sum['new'] . ' (' . $sum['gap'] . '), ' . $sum['withoutSplit'] . ' day(s) without HC/HP');
		$text = array('PRÉVISUALISATION DU RECALCUL DES COÛTS', 'Plage : ' . $from . ' → ' . $to . ' (' . $sum['days'] . ' jours)', 'Tarifs du premier jour : ' . json_encode($tariff), 'Coût actuel ' . $sum['current'] . ' → nouveau ' . $sum['new'] . ' (écart ' . $sum['gap'] . ', ' . ($sum['gapPercent'] === null ? '-' : $sum['gapPercent'] . ' %') . ')', 'Jours sans HC/HP : ' . $sum['withoutSplit'], 'Jours mesurés par le plugin (points du jour conservés et mis à l\'échelle) : ' . $sum['measured'] . ', jours importés (un point) : ' . ($sum['days'] - $sum['measured']), '', 'JOURS (date | kWh | HC | HP | coût actuel | nouveau coût | origine | points)');
		foreach ($days as $d) {
			$text[] = $d['date'] . ' | ' . $d['kwh'] . ' | ' . ($d['hc'] === null ? '-' : $d['hc']) . ' | ' . ($d['hp'] === null ? '-' : $d['hp']) . ' | ' . round($d['current'], 6) . ' | ' . round($d['new'], 6) . ' | ' . $d['origin'] . ' | ' . $d['points'];
		}
		$text[] = '';
		$text[] = 'JOURNAL';
		return array('days' => $days, 'summary' => $sum, 'text' => implode("\n", array_merge($text, $lines)));
	}

	/* ------------------------------------------------------------------ the steps */

	/**
	 * Runs ONE step. The first step records the choice (equipment, period) and computes the plan; the next ones only read
	 * that state, never the browser.
	 *
	 * @return array {step, label, ok, done, next, message, text, backup}
	 */
	public static function runStep($_importId, $_step, $_energyId = 0, $_from = '') {
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
			$preview = self::preview($_energyId, $_from);
			$state = array('kind' => 'rates', 'energy_id' => intval($_energyId), 'from' => $preview['summary']['from'], 'to' => $preview['summary']['to'], 'plan' => $preview['days'], 'done' => array(), 'log' => array(), 'created' => date('Y-m-d H:i:s'));
		} else {
			$state = energyCatchup::loadState($_importId);
			if ($state === null || !isset($state['kind']) || $state['kind'] != 'rates' || !in_array('check', $state['done'])) {
				throw new Exception(__('Le recalcul n\'a pas été contrôlé : recommencez.', __FILE__));
			}
			$previous = array_search($_step, $names) - 1;
			if (!in_array($names[$previous], $state['done']) || in_array($_step, $state['done'])) {
				throw new Exception(__('Étape hors ordre : ', __FILE__) . $_step);
			}
		}
		energyCatchup::say($lines, 'info', '[rates] step "' . $_step . '" start');
		try {
			$message = self::{'step' . ucfirst($_step)}($state, $lines);
		} catch (Exception $e) {
			energyCatchup::say($lines, 'error', '[rates] step "' . $_step . '" failed: ' . $e->getMessage());
			$state['log'] = array_merge($state['log'], $lines);
			if ($_step != 'check') {
				energyCatchup::saveState($_importId, $state);
			}
			return array('id' => $_importId, 'step' => $_step, 'label' => self::STEPS[$_step], 'ok' => false, 'done' => true, 'next' => null, 'message' => $e->getMessage(), 'text' => implode("\n", $state['log']), 'backup' => isset($state['backup']) ? $state['backup'] : null);
		}
		$state['done'][] = $_step;
		energyCatchup::say($lines, 'info', '[rates] step "' . $_step . '" done in ' . round((microtime(true) - $started) * 1000) . ' ms: ' . $message);
		$state['log'] = array_merge($state['log'], $lines);
		energyCatchup::saveState($_importId, $state);
		$index = array_search($_step, $names);
		$finished = !isset($names[$index + 1]);
		if ($finished) {
			energyCatchup::say($state['log'], 'info', '[rates] cost recomputed: ' . count($state['plan']) . ' day(s), backup ' . $state['backup']);
			self::journal($state);
			energyCatchup::discard($_importId);
		}
		return array('id' => $_importId, 'step' => $_step, 'label' => self::STEPS[$_step], 'ok' => true, 'done' => $finished, 'next' => $finished ? null : $names[$index + 1], 'message' => $message, 'text' => implode("\n", $state['log']), 'backup' => isset($state['backup']) ? $state['backup'] : null);
	}

	/** One line per recalculation: when, which days, which tariffs, which backup. Never purged. */
	public static function journal($_state) {
		$tariff = self::tariffSummary($_state['from']);
		$line = array(
			'date' => date('Y-m-d H:i:s'),
			'from' => $_state['from'],
			'to' => $_state['to'],
			'days' => count($_state['plan']),
			'tariff' => $tariff,
			'backup' => isset($_state['backup']) ? $_state['backup'] : '',
		);
		@file_put_contents(energyCatchup::dir('backup') . '/rates_journal.jsonl', json_encode($line) . "\n", FILE_APPEND);
		return $line;
	}

	/** The journal of the recalculations, the oldest first. */
	public static function journalLines() {
		$lines = array();
		foreach (explode("\n", strval(@file_get_contents(energyCatchup::dir('backup') . '/rates_journal.jsonl'))) as $raw) {
			$data = json_decode($raw, true);
			if (is_array($data)) {
				$lines[] = $data;
			}
		}
		return $lines;
	}

	private static function stepCheck(&$_state, &$_lines) {
		return count($_state['plan']) . ' jour(s) à recalculer';
	}

	private static function stepBackup(&$_state, &$_lines) {
		$energy = energyCatchup::target($_state['energy_id']);
		$cmds = energyCatchup::commands($energy);
		$costId = $cmds['cost'];
		$rows = energyCatchup::readRowsOf(array($costId), $_state['from'], $_state['to']);
		$name = 'catchup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json';
		$data = array(
			'version' => 1,
			'kind' => 'rates',
			'created' => date('Y-m-d H:i:s'),
			'energy_id' => $energy->getId(),
			// only the command "cost" is saved, and so only that one is erased then restored
			'cmds' => array('cost' => $costId),
			'from' => $_state['from'],
			'to' => $_state['to'],
			'reliableOld' => '',
			'reliableChanged' => false,
			'writtenDays' => array_column($_state['plan'], 'date'),
			// what made the new costs: the tariffs of the period, and the whole table of the periods as it was at that moment
			'tariff' => self::tariffSummary($_state['from']),
			'periods' => energyRates::periods(),
			'rows' => $rows,
		);
		$path = energyCatchup::backupPath($name);
		$json = json_encode($data);
		if ($json === false || file_put_contents($path, $json) === false) {
			throw new Exception(__('La sauvegarde n\'a pas pu être écrite : le recalcul est annulé, rien n\'a été modifié.', __FILE__));
		}
		// read it back: nothing is written without a backup that can be read
		$back = json_decode(strval(@file_get_contents($path)), true);
		if (!is_array($back) || count($back['rows']) != count($rows) || filesize($path) != strlen($json)) {
			@unlink($path);
			throw new Exception(__('La sauvegarde relue ne correspond pas : le recalcul est annulé, rien n\'a été modifié.', __FILE__));
		}
		$_state['backup'] = $name;
		energyCatchup::say($_lines, 'debug', '[rates] backup file ' . $name . ': ' . count($rows) . ' existing cost rows');
		return $name . ' (' . count($rows) . ' ligne(s) de coût)';
	}

	private static function stepCost(&$_state, &$_lines) {
		$energy = energyCatchup::target($_state['energy_id']);
		$costId = energyCatchup::commands($energy)['cost'];
		$written = 0;
		$scaled = 0;
		foreach ($_state['plan'] as $day) {
			$rows = self::rowsByDay($costId, $day['date'], $day['date']);
			$rows = isset($rows[$day['date']]) ? $rows[$day['date']] : array();
			if ($day['origin'] == 'plugin' && count($rows) > 1 && $day['current'] > 0) {
				// a day measured by the plugin: its points keep their date and their shape, scaled so that the last of the day
				// (the maximum, which the panel reads) becomes the new cost
				$ratio = $day['new'] / $day['current'];
				foreach ($rows as $row) {
					energyCatchup::insertRow($row[0], $row[1], $row[2], floatval($row[3]) * $ratio);
					$scaled++;
				}
				$written++;
				continue;
			}
			// an imported day: the old cost of the day is replaced by one value at noon
			energyCatchup::deleteRowsOf(array($costId), $day['date'], $day['date']);
			energyCatchup::insertRow('history', $costId, $day['date'] . ' 12:00:00', $day['new']);
			$written++;
		}
		energyCatchup::say($_lines, 'debug', '[rates] cost: ' . $written . ' day(s) written, ' . $scaled . ' point(s) scaled');
		return $written . ' jour(s)' . ($scaled > 0 ? ', ' . $scaled . ' point(s) mis à l\'échelle' : '');
	}

	private static function stepVerify(&$_state, &$_lines) {
		$energy = energyCatchup::target($_state['energy_id']);
		$read = self::valuesByDay($energy, 'cost', $_state['from'], $_state['to']);
		$bad = array();
		$sum = 0;
		foreach ($_state['plan'] as $day) {
			$value = isset($read[$day['date']]) ? $read[$day['date']] : null;
			$sum += $day['new'];
			if ($value === null || abs($value - $day['new']) > 0.0001) {
				$bad[] = $day['date'] . ' (attendu ' . round($day['new'], 4) . ', lu ' . ($value === null ? 'rien' : round($value, 4)) . ')';
			}
		}
		if (count($bad) > 0) {
			energyCatchup::say($_lines, 'error', '[rates] verification: ' . count($bad) . ' day(s) differ: ' . implode('; ', array_slice($bad, 0, 20)));
			throw new Exception(count($bad) . ' jour(s) différents après écriture : ' . implode(', ', array_slice($bad, 0, 5)) . '. Restaurez la sauvegarde ' . $_state['backup'] . '.');
		}
		return count($_state['plan']) . ' jour(s) relus, coût ' . round($sum, 2);
	}
}

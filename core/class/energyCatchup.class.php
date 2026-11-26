<?php
/*
 * CATCH-UP OF THE HISTORY FROM AN ENEDIS EXPORT (+ the HC/HP split of the EDF invoices typed by hand)
 *
 * The plugin only has reliable data since its total counter was installed. The daily export of Enedis (xlsx) gives the
 * energy of every day since the beginning of the year, and the invoice of EDF gives the HC/HP of each billing period.
 * This class reads the export, builds the plan day by day, backs up what it is going to touch and writes the days into
 * the history of the total counter equipment (total, HP, HC, cost and a "caught-up" flag), one step per ajax call.
 *
 * Nothing here is called by the cron: it only runs from the import modal of the configuration page (administrator).
 * Every ajax call goes through the validation of this class again: the browser only chooses (equipment, dates, option,
 * periods), the values that are written always come from the uploaded file kept on the server.
 *
 * Log lines carry the prefix [catchup]: INFO always, DEBUG when the log level of the plugin is debug and the box
 * "Rattrapage" of the configuration is on (see energyLogTypes).
 */
class energyCatchup {

	const MAX_FILE_BYTES = 2097152;
	const MAX_ENTRY_BYTES = 20971520;
	const MAX_KWH_PER_DAY = 400;
	const MAX_DAYS = 3700;
	const PURGE_SECONDS = 86400;

	/** Folder of the files of the plugin (backups, uploads), overridable for the unit tests. */
	public static $baseDir = null;

	/** The logical ids of the commands written for a day, in the order of the steps. */
	const COMMANDS = array('consumptionTotal', 'consumptionHp', 'consumptionHc', 'cost', 'consumptionCatchup');

	/** The steps of an import, in order: id => label shown in the progress bar. */
	const STEPS = array(
		'check' => 'Contrôle des paramètres',
		'backup' => 'Sauvegarde',
		'reliable' => 'Réglage « compteur fiable »',
		'total' => 'Total',
		'hphc' => 'HC / HP',
		'cost' => 'Coût',
		'flag' => 'Marqueur « rattrapé »',
		'verify' => 'Vérification',
	);

	/* ------------------------------------------------------------------ files and logs */

	public static function dir($_sub) {
		$base = self::$baseDir !== null ? self::$baseDir : dirname(__FILE__) . '/../../data';
		$dir = $base . '/' . $_sub;
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new Exception('Dossier impossible à créer : ' . $dir);
		}
		// the files hold the consumption of the house: never served by the web server
		if (!file_exists($base . '/.htaccess')) {
			@file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
		}
		return $dir;
	}

	public static function say(&$_lines, $_level, $_message) {
		$_lines[] = '[' . date('H:i:s') . '] ' . strtoupper($_level) . ' ' . $_message;
		if ($_level == 'debug' && !energyLogTypes::enabled('catchup')) {
			return;
		}
		log::add('energy', $_level, '[catchup] ' . $_message);
	}

	/** Deletes the uploads and import states older than a day. */
	public static function purge() {
		$dir = self::dir('catchup');
		foreach ((array) glob($dir . '/*') as $file) {
			if (is_file($file) && filemtime($file) < time() - self::PURGE_SECONDS) {
				@unlink($file);
			}
		}
	}

	public static function validId($_id) {
		return is_string($_id) && preg_match('/^[a-f0-9]{32}$/', $_id) === 1;
	}

	/* ------------------------------------------------------------------ dates */

	public static function validDate($_date) {
		if (!is_string($_date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $_date, $m)) {
			return false;
		}
		return checkdate(intval($m[2]), intval($m[3]), intval($m[1]));
	}

	public static function addDays($_date, $_days) {
		$d = new DateTime($_date . ' 00:00:00', new DateTimeZone('UTC'));
		$d->modify(($_days >= 0 ? '+' : '') . $_days . ' day');
		return $d->format('Y-m-d');
	}

	/** Days of [from, to] (both included). */
	public static function daysBetween($_from, $_to) {
		$days = array();
		for ($d = $_from, $guard = 0; $d <= $_to && $guard < self::MAX_DAYS; $d = self::addDays($d, 1), $guard++) {
			$days[] = $d;
		}
		return $days;
	}

	/* ------------------------------------------------------------------ reading the Enedis export */

	private static function number($_value) {
		$value = str_replace(array(' ', "\xc2\xa0"), '', trim(strval($_value)));
		$value = str_replace(',', '.', $value);
		return is_numeric($value) ? floatval($value) : null;
	}

	private static function columnOf($_ref) {
		return preg_replace('/[^A-Z]/', '', strtoupper($_ref));
	}

	/**
	 * Reads the daily consumption export of Enedis.
	 *
	 * @return array {prm, type, from, to, count, total, min, max, unavailable[], invalid[], gaps[], hasSplit, days{date: {kwh, hc, hp}}}
	 * @throws Exception when the file is not a usable export
	 */
	public static function parseXlsx($_path) {
		if (!class_exists('ZipArchive')) {
			throw new Exception(__('L\'extension PHP zip est absente : impossible de lire le fichier xlsx.', __FILE__));
		}
		$size = @filesize($_path);
		if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
			throw new Exception(__('Fichier vide ou trop gros (2 Mo au maximum).', __FILE__));
		}
		$zip = new ZipArchive();
		if ($zip->open($_path) !== true) {
			throw new Exception(__('Ce fichier n\'est pas un classeur xlsx.', __FILE__));
		}
		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			$total += $stat['size'];
			if ($stat['size'] > self::MAX_ENTRY_BYTES || $total > 3 * self::MAX_ENTRY_BYTES) {
				$zip->close();
				throw new Exception(__('Fichier xlsx refusé : contenu décompressé trop gros.', __FILE__));
			}
		}
		$shared = array();
		$xml = $zip->getFromName('xl/sharedStrings.xml');
		if ($xml !== false) {
			$sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
			if ($sx !== false) {
				foreach ($sx->si as $si) {
					$text = '';
					if (isset($si->t)) {
						$text = (string) $si->t;
					} else {
						foreach ($si->r as $run) {
							$text .= (string) $run->t;
						}
					}
					$shared[] = $text;
				}
			}
		}
		$result = null;
		for ($i = 0; $i < $zip->numFiles && $result === null; $i++) {
			$name = $zip->getNameIndex($i);
			if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
				continue;
			}
			$sx = @simplexml_load_string($zip->getFromIndex($i), 'SimpleXMLElement', LIBXML_NONET);
			if ($sx === false || !isset($sx->sheetData)) {
				continue;
			}
			$rows = array();
			foreach ($sx->sheetData->row as $row) {
				$cells = array();
				foreach ($row->c as $c) {
					$type = (string) $c['t'];
					if ($type == 's') {
						$value = isset($shared[intval((string) $c->v)]) ? $shared[intval((string) $c->v)] : '';
					} elseif ($type == 'inlineStr') {
						$value = isset($c->is->t) ? (string) $c->is->t : '';
					} else {
						$value = isset($c->v) ? (string) $c->v : '';
					}
					if ($value !== '') {
						$cells[self::columnOf((string) $c['r'])] = $value;
					}
				}
				if (count($cells) > 0) {
					$rows[] = $cells;
				}
			}
			$result = self::readRows($rows);
		}
		$zip->close();
		if ($result === null) {
			throw new Exception(__('Aucune donnée de consommation quotidienne trouvée dans ce fichier (export Enedis attendu).', __FILE__));
		}
		return $result;
	}

	/** The rows of a sheet (column => text) once the cells are resolved. Null when there is no daily data in it. */
	private static function readRows($_rows) {
		$prm = '';
		$type = '';
		$hcColumn = null;
		$hpColumn = null;
		$days = array();
		$unavailable = array();
		$invalid = array();
		foreach ($_rows as $cells) {
			foreach ($cells as $column => $text) {
				if ($prm == '' && preg_match('/^\d{14}$/', $text)) {
					$prm = $text;
				}
				if (stripos($text, 'Type de comptage') !== false) {
					$keys = array_keys($cells);
					$position = array_search($column, $keys);
					$type = isset($keys[$position + 1]) ? $cells[$keys[$position + 1]] : '';
				}
				if (stripos($text, 'creuse') !== false || preg_match('/\bHC\b/', $text)) {
					$hcColumn = $column;
				}
				if (stripos($text, 'pleine') !== false || preg_match('/\bHP\b/', $text)) {
					$hpColumn = $column;
				}
			}
			// a day is a row that STARTS with a date ("Date de début : 01/01/2026" in the header has a date, not first)
			$keys = array_keys($cells);
			$dateColumn = $keys[0];
			if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $cells[$dateColumn], $m) || !checkdate(intval($m[2]), intval($m[1]), intval($m[3]))) {
				continue;
			}
			$date = $m[3] . '-' . $m[2] . '-' . $m[1];
			$valueColumn = isset($keys[1]) ? $keys[1] : null;
			$kwh = $valueColumn !== null ? self::number($cells[$valueColumn]) : null;
			if ($kwh === null) {
				$unavailable[] = $date;
				continue;
			}
			if ($kwh < 0 || $kwh > self::MAX_KWH_PER_DAY) {
				$invalid[] = $date . ' (' . $kwh . ' kWh)';
				continue;
			}
			$hc = ($hcColumn !== null && isset($cells[$hcColumn])) ? self::number($cells[$hcColumn]) : null;
			$hp = ($hpColumn !== null && isset($cells[$hpColumn])) ? self::number($cells[$hpColumn]) : null;
			$days[$date] = array('kwh' => $kwh, 'hc' => $hc, 'hp' => $hp);
		}
		if (count($days) == 0) {
			return null;
		}
		ksort($days);
		$dates = array_keys($days);
		$from = $dates[0];
		$to = $dates[count($dates) - 1];
		$kwhs = array_column($days, 'kwh');
		$gaps = array();
		foreach (self::daysBetween($from, $to) as $day) {
			if (!isset($days[$day]) && !in_array($day, $unavailable)) {
				$gaps[] = $day;
			}
		}
		return array(
			'prm' => $prm,
			'type' => $type,
			'from' => $from,
			'to' => $to,
			'count' => count($days),
			'total' => round(array_sum($kwhs), 3),
			'min' => min($kwhs),
			'max' => max($kwhs),
			'unavailable' => $unavailable,
			'invalid' => $invalid,
			'gaps' => $gaps,
			'hasSplit' => count(array_filter($days, function ($d) {
				return $d['hc'] !== null && $d['hp'] !== null;
			})) > 0,
			'days' => $days,
		);
	}

	/* ------------------------------------------------------------------ the periods of the invoices */

	/**
	 * Checks and sorts the periods typed by the user: [{from, to (excluded), hc, hp}], hc and hp null when not given.
	 *
	 * @throws Exception on an invalid or overlapping period
	 */
	public static function normalizePeriods($_periods) {
		$periods = array();
		foreach ((array) $_periods as $index => $period) {
			$period = (array) $period;
			$from = isset($period['from']) ? $period['from'] : '';
			$to = isset($period['to']) ? $period['to'] : '';
			if (!self::validDate($from) || !self::validDate($to) || $from >= $to) {
				throw new Exception(__('Période invalide (ligne ', __FILE__) . ($index + 1) . ') : ' . $from . ' → ' . $to);
			}
			$values = array();
			foreach (array('hc', 'hp') as $key) {
				$raw = isset($period[$key]) ? trim(strval($period[$key])) : '';
				if ($raw === '') {
					$values[$key] = null;
					continue;
				}
				$number = self::number($raw);
				if ($number === null || $number < 0 || $number > 100000) {
					throw new Exception(__('Valeur HC/HP invalide (ligne ', __FILE__) . ($index + 1) . ') : ' . $raw);
				}
				$values[$key] = $number;
			}
			$periods[] = array('from' => $from, 'to' => $to, 'hc' => $values['hc'], 'hp' => $values['hp']);
		}
		usort($periods, function ($a, $b) {
			return strcmp($a['from'], $b['from']);
		});
		for ($i = 1; $i < count($periods); $i++) {
			if ($periods[$i]['from'] < $periods[$i - 1]['to']) {
				throw new Exception(__('Les périodes se chevauchent : ', __FILE__) . $periods[$i - 1]['from'] . ' → ' . $periods[$i - 1]['to'] . ' et ' . $periods[$i]['from'] . ' → ' . $periods[$i]['to']);
			}
		}
		return $periods;
	}

	/** Sum of the kWh of the days of the file inside [from, to). */
	public static function periodTotal($_days, $_period) {
		$total = 0;
		foreach ($_days as $date => $day) {
			if ($date >= $_period['from'] && $date < $_period['to']) {
				$total += $day['kwh'];
			}
		}
		return round($total, 3);
	}

	/**
	 * HC and HP of a day: the real ones when the file has them, else the proportional share of its period.
	 *
	 * @return array {hc, hp, source} source: file|period|null (no HC/HP known for this day)
	 */
	public static function splitDay($_date, $_day, $_periods, $_days) {
		if ($_day['hc'] !== null && $_day['hp'] !== null) {
			return array('hc' => $_day['hc'], 'hp' => $_day['hp'], 'source' => 'file');
		}
		foreach ($_periods as $period) {
			if ($_date < $period['from'] || $_date >= $period['to'] || ($period['hc'] === null && $period['hp'] === null)) {
				continue;
			}
			$total = self::periodTotal($_days, $period);
			$hc = $period['hc'];
			$hp = $period['hp'];
			if ($hc === null) {
				$hc = max(0, $total - $hp);
			}
			if ($hp === null) {
				$hp = max(0, $total - $hc);
			}
			if ($hc + $hp <= 0) {
				return array('hc' => 0, 'hp' => 0, 'source' => 'period');
			}
			$dayHc = round($_day['kwh'] * $hc / ($hc + $hp), 3);
			return array('hc' => $dayHc, 'hp' => round($_day['kwh'] - $dayHc, 3), 'source' => 'period');
		}
		return array('hc' => null, 'hp' => null, 'source' => null);
	}

	/** Share of HC of the last period that has a split, used for the days without any (null when no period has one). */
	public static function lastShare($_periods, $_days) {
		$share = null;
		foreach ($_periods as $period) {
			if ($period['hc'] === null && $period['hp'] === null) {
				continue;
			}
			$total = self::periodTotal($_days, $period);
			$hc = $period['hc'] !== null ? $period['hc'] : max(0, $total - $period['hp']);
			$hp = $period['hp'] !== null ? $period['hp'] : max(0, $total - $period['hc']);
			if ($hc + $hp > 0) {
				$share = $hc / ($hc + $hp);
			}
		}
		return $share;
	}

	/* ------------------------------------------------------------------ cost */

	/** The tariffs of a day (a tariff period if one holds it, the configuration otherwise). */
	public static function rates($_date = null) {
		$tariff = energyRates::forDate($_date === null ? date('Y-m-d') : $_date);
		return array(
			'variable' => $tariff['variable'],
			'hp' => $tariff['rateHp'],
			'hc' => $tariff['rateHc'],
			'fixed' => $tariff['rate'],
		);
	}

	/** Cost of a day: HP and HC at their rate, or the fixed rate; without a split, the share of the last known period. */
	public static function dayCost($_kwh, $_hc, $_hp, $_share, $_rates) {
		if (!$_rates['variable']) {
			return round($_kwh * $_rates['fixed'], 6);
		}
		if ($_hc !== null && $_hp !== null) {
			// from the HC/HP as written (3 decimals), unrounded: the panel rebuilds the same split from them
			return $_hc * $_rates['hc'] + $_hp * $_rates['hp'];
		}
		$share = $_share !== null ? $_share : 0;
		return round($_kwh * ($share * $_rates['hc'] + (1 - $share) * $_rates['hp']), 6);
	}

	/* ------------------------------------------------------------------ the equipment and its history */

	/** The total counter equipments of the plugin that a catch-up can write to. */
	public static function targets() {
		$list = array();
		foreach (energy::byType('energy') as $energy) {
			if ($energy->getConfiguration('type') == 'electricity' && $energy->getConfiguration('totalCounter') == 1) {
				$list[] = array('id' => $energy->getId(), 'name' => $energy->getHumanName());
			}
		}
		return $list;
	}

	public static function target($_id) {
		$energy = eqLogic::byId($_id);
		if (!is_object($energy) || $energy->getEqType_name() != 'energy' || $energy->getConfiguration('type') != 'electricity' || $energy->getConfiguration('totalCounter') != 1) {
			throw new Exception(__('Équipement de rattrapage invalide : ', __FILE__) . $_id);
		}
		return $energy;
	}

	/** Ids of the commands written by the catch-up: logicalId => cmd id (the flag is created by saving the equipment). */
	public static function commands($_energy) {
		$ids = array();
		foreach (self::COMMANDS as $logicalId) {
			$cmd = $_energy->getCmd(null, $logicalId);
			if (!is_object($cmd)) {
				$_energy->save();
				$cmd = $_energy->getCmd(null, $logicalId);
			}
			if (!is_object($cmd)) {
				throw new Exception(__('Commande manquante sur l\'équipement : ', __FILE__) . $logicalId);
			}
			$ids[$logicalId] = $cmd->getId();
		}
		return $ids;
	}

	/** Value of the total counter by day in [from, to] (days with a value above 0 only). */
	public static function pluginDays($_energy, $_from, $_to) {
		$cmd = $_energy->getCmd(null, 'consumptionTotal');
		$days = array();
		if (is_object($cmd)) {
			foreach ((array) $cmd->getHistoryEnergy($_from . ' 00:00:00', $_to) as $row) {
				if (is_numeric($row[1]) && $row[1] > 0) {
					$days[substr($row[0], 0, 10)] = floatval($row[1]);
				}
			}
		}
		return $days;
	}

	/**
	 * HC and HP of a period read from the history of the plugin, to prefill the table of the periods: only when every day
	 * of the period has data from the plugin. Null otherwise.
	 */
	public static function periodHint($_energyId, $_from, $_to) {
		if (!self::validDate($_from) || !self::validDate($_to) || $_from >= $_to) {
			throw new Exception(__('Période invalide : ', __FILE__) . $_from . ' → ' . $_to);
		}
		$energy = self::target($_energyId);
		$last = self::addDays($_to, -1);
		$existing = self::pluginDays($energy, $_from, $last);
		foreach (self::daysBetween($_from, $last) as $day) {
			if (!isset($existing[$day])) {
				return null;
			}
		}
		$sums = array();
		foreach (array('consumptionHc' => 'hc', 'consumptionHp' => 'hp') as $logicalId => $key) {
			$cmd = $energy->getCmd(null, $logicalId);
			$sums[$key] = 0;
			if (is_object($cmd)) {
				foreach ((array) $cmd->getHistoryEnergy($_from . ' 00:00:00', $last) as $row) {
					if (is_numeric($row[1])) {
						$sums[$key] += floatval($row[1]);
					}
				}
			}
		}
		if ($sums['hc'] + $sums['hp'] <= 0) {
			return null;
		}
		return array('hc' => round($sums['hc'], 3), 'hp' => round($sums['hp'], 3));
	}

	/* ------------------------------------------------------------------ upload (step 1) */

	/**
	 * Keeps an uploaded export and returns what is in it.
	 *
	 * @return array the description of the file (see parseXlsx), without the days, plus the id of the upload
	 */
	public static function storeUpload($_tmpPath, $_originalName, $_isUploaded = true) {
		self::purge();
		$lines = array();
		if (strtolower(pathinfo($_originalName, PATHINFO_EXTENSION)) != 'xlsx') {
			throw new Exception(__('Seuls les fichiers .xlsx sont acceptés.', __FILE__));
		}
		$id = bin2hex(random_bytes(16));
		$target = self::dir('catchup') . '/' . $id . '.xlsx';
		$moved = $_isUploaded ? move_uploaded_file($_tmpPath, $target) : copy($_tmpPath, $target);
		if (!$moved) {
			throw new Exception(__('Le fichier n\'a pas pu être enregistré sur le serveur.', __FILE__));
		}
		try {
			$file = self::parseXlsx($target);
		} catch (Exception $e) {
			@unlink($target);
			log::add('energy', 'error', '[catchup] file refused (' . $_originalName . '): ' . $e->getMessage());
			throw $e;
		}
		self::say($lines, 'info', 'file received: ' . $_originalName . ', ' . $file['count'] . ' days from ' . $file['from'] . ' to ' . $file['to'] . ', ' . $file['total'] . ' kWh, PRM ' . $file['prm']);
		self::say($lines, 'debug', 'unavailable: ' . count($file['unavailable']) . ', invalid: ' . count($file['invalid']) . ', gaps: ' . count($file['gaps']) . ', HC/HP columns: ' . ($file['hasSplit'] ? 'yes' : 'no'));
		unset($file['days']);
		$file['id'] = $id;
		$file['name'] = basename($_originalName);
		$file['sha256'] = hash_file('sha256', $target);
		return $file;
	}

	private static function loadFile($_id) {
		if (!self::validId($_id)) {
			throw new Exception(__('Identifiant d\'import invalide.', __FILE__));
		}
		$path = self::dir('catchup') . '/' . $_id . '.xlsx';
		if (!is_file($path)) {
			throw new Exception(__('Le fichier importé n\'existe plus sur le serveur, recommencez l\'étape 1.', __FILE__));
		}
		$file = self::parseXlsx($path);
		$file['sha256'] = hash_file('sha256', $path);
		return $file;
	}

	/** Deletes an upload and its import state (cancel, end of an import). */
	public static function discard($_id) {
		if (!self::validId($_id)) {
			return;
		}
		foreach (array('.xlsx', '.json') as $extension) {
			@unlink(self::dir('catchup') . '/' . $_id . $extension);
		}
	}

	/* ------------------------------------------------------------------ analysis (step 3) */

	/**
	 * Validates the choices of the user against the file and the equipment.
	 *
	 * @return array the normalized choices {energy, from, to, onlyEmpty, periods}
	 */
	public static function validateParams($_params, $_file) {
		$_params = (array) $_params;
		$energy = self::target(isset($_params['energy_id']) ? $_params['energy_id'] : 0);
		$from = isset($_params['from']) ? $_params['from'] : '';
		$to = isset($_params['to']) ? $_params['to'] : '';
		if (!self::validDate($from) || !self::validDate($to) || $from > $to) {
			throw new Exception(__('Plage de dates invalide : ', __FILE__) . $from . ' → ' . $to);
		}
		if ($from < $_file['from'] || $to > $_file['to']) {
			throw new Exception(__('La plage doit rester dans celle du fichier : ', __FILE__) . $_file['from'] . ' → ' . $_file['to']);
		}
		$today = date('Y-m-d', (energyRuntime::$now !== null) ? energyRuntime::$now : time());
		if ($to >= $today) {
			throw new Exception(__('La plage ne peut pas contenir aujourd\'hui ni le futur : ', __FILE__) . $to);
		}
		$onlyEmpty = !isset($_params['only_empty']) || !in_array($_params['only_empty'], array(0, '0', false, 'false'), true);
		return array(
			'energy' => $energy,
			'from' => $from,
			'to' => $to,
			'onlyEmpty' => $onlyEmpty,
			'periods' => self::normalizePeriods(isset($_params['periods']) ? $_params['periods'] : array()),
		);
	}

	/**
	 * The plan, day by day, and its summary. Reads only.
	 *
	 * @return array {days[], summary{}, periods[], text}
	 */
	public static function analyze($_params, $_fileId) {
		$lines = array();
		$file = self::loadFile($_fileId);
		$p = self::validateParams($_params, $file);
		self::say($lines, 'info', 'analysis: equipment ' . $p['energy']->getId() . ', ' . $p['from'] . ' → ' . $p['to'] . ', only days without plugin data: ' . ($p['onlyEmpty'] ? 'yes' : 'no') . ', ' . count($p['periods']) . ' period(s)');
		$existing = self::pluginDays($p['energy'], $p['from'], $p['to']);
		$ratesOfDay = array();
		$share = self::lastShare($p['periods'], $file['days']);
		self::say($lines, 'debug', 'rates of the first and last day: ' . json_encode(self::rates($p['from'])) . ' / ' . json_encode(self::rates($p['to'])) . ', share HC of the last period: ' . ($share === null ? 'none' : round($share, 4)));
		$days = array();
		$summary = array('write' => 0, 'keep' => 0, 'replace' => 0, 'kwh' => 0, 'cost' => 0, 'withoutSplit' => 0);
		foreach (self::daysBetween($p['from'], $p['to']) as $date) {
			if (!isset($file['days'][$date])) {
				continue;
			}
			$day = $file['days'][$date];
			$plugin = isset($existing[$date]) ? $existing[$date] : 0;
			$status = $plugin > 0 ? ($p['onlyEmpty'] ? 'keep' : 'replace') : 'write';
			$split = self::splitDay($date, $day, $p['periods'], $file['days']);
			if (!isset($ratesOfDay[$date])) {
				$ratesOfDay[$date] = self::rates($date);
			}
			$rates = $ratesOfDay[$date];
			$cost = self::dayCost($day['kwh'], $split['hc'], $split['hp'], $share, $rates);
			$summary[$status]++;
			if ($status != 'keep') {
				$summary['kwh'] += $day['kwh'];
				$summary['cost'] += $cost;
				if ($split['hc'] === null) {
					$summary['withoutSplit']++;
				}
			}
			$days[] = array('date' => $date, 'kwh' => $day['kwh'], 'plugin' => $plugin, 'status' => $status, 'hc' => $split['hc'], 'hp' => $split['hp'], 'source' => $split['source'], 'cost' => $cost);
			self::say($lines, 'debug', $date . ' enedis=' . $day['kwh'] . ' plugin=' . $plugin . ' ' . $status . ' hc=' . var_export($split['hc'], true) . ' hp=' . var_export($split['hp'], true) . ' cost=' . $cost);
		}
		$summary['kwh'] = round($summary['kwh'], 3);
		$summary['cost'] = round($summary['cost'], 2);
		$checks = array();
		foreach ($p['periods'] as $period) {
			$total = self::periodTotal($file['days'], $period);
			$invoice = ($period['hc'] !== null && $period['hp'] !== null) ? $period['hc'] + $period['hp'] : null;
			$gap = ($invoice !== null && $invoice > 0) ? round(100 * ($total - $invoice) / $invoice, 2) : null;
			$checks[] = array('from' => $period['from'], 'to' => $period['to'], 'enedis' => $total, 'invoice' => $invoice, 'gapPercent' => $gap, 'warning' => $gap !== null && abs($gap) > 1);
			self::say($lines, ($gap !== null && abs($gap) > 1) ? 'warning' : 'info', 'period ' . $period['from'] . ' → ' . $period['to'] . ': Enedis ' . $total . ' kWh, invoice ' . ($invoice === null ? 'n/a' : $invoice . ' kWh (gap ' . $gap . ' %)'));
		}
		$summary['reliableFrom'] = $p['from'];
		self::say($lines, 'info', 'analysis done: ' . $summary['write'] . ' to write, ' . $summary['replace'] . ' to replace, ' . $summary['keep'] . ' kept (plugin data), ' . $summary['kwh'] . ' kWh, cost about ' . $summary['cost'] . ', ' . $summary['withoutSplit'] . ' day(s) without HC/HP');
		return array('days' => $days, 'summary' => $summary, 'periods' => $checks, 'text' => self::reportText($file, $p, $days, $summary, $checks, $lines));
	}

	private static function reportText($_file, $_p, $_days, $_summary, $_checks, $_lines) {
		$text = array();
		$text[] = 'ANALYSE DU RATTRAPAGE ENEDIS / EDF';
		$text[] = 'Fichier : ' . $_file['count'] . ' jours du ' . $_file['from'] . ' au ' . $_file['to'] . ', ' . $_file['total'] . ' kWh, PRM ' . $_file['prm'] . ', sha256 ' . substr($_file['sha256'], 0, 16);
		$text[] = 'Plage importée : ' . $_p['from'] . ' → ' . $_p['to'] . ' ; seulement les jours sans donnée du plugin : ' . ($_p['onlyEmpty'] ? 'oui' : 'non');
		$text[] = 'Jours : ' . $_summary['write'] . ' à écrire, ' . $_summary['replace'] . ' remplacés, ' . $_summary['keep'] . ' conservés ; ' . $_summary['kwh'] . ' kWh ; coût estimé ' . $_summary['cost'] . ' ; sans HC/HP : ' . $_summary['withoutSplit'];
		$text[] = 'Date « compteur fiable » après import : ' . $_summary['reliableFrom'];
		$text[] = '';
		$text[] = 'PÉRIODES (Du inclus → Au exclu | total Enedis | facture HC+HP | écart %)';
		foreach ($_checks as $c) {
			$text[] = $c['from'] . ' → ' . $c['to'] . ' | ' . $c['enedis'] . ' | ' . ($c['invoice'] === null ? '-' : $c['invoice']) . ' | ' . ($c['gapPercent'] === null ? '-' : $c['gapPercent']) . ($c['warning'] ? ' ATTENTION' : '');
		}
		$text[] = '';
		$text[] = 'JOURS (date | Enedis | plugin | statut | HC | HP | source | coût)';
		foreach ($_days as $d) {
			$text[] = $d['date'] . ' | ' . $d['kwh'] . ' | ' . $d['plugin'] . ' | ' . $d['status'] . ' | ' . ($d['hc'] === null ? '-' : $d['hc']) . ' | ' . ($d['hp'] === null ? '-' : $d['hp']) . ' | ' . ($d['source'] === null ? '-' : $d['source']) . ' | ' . $d['cost'];
		}
		$text[] = '';
		$text[] = 'JOURNAL';
		return implode("\n", array_merge($text, $_lines));
	}

	/* ------------------------------------------------------------------ backups */

	private static function backupDir() {
		return self::dir('backup');
	}

	private static function dayStart($_date) {
		return $_date . ' 00:00:00';
	}

	private static function dayEnd($_date) {
		return $_date . ' 23:59:59';
	}

	/** Existing rows of the commands between two days, in both history tables. */
	public static function readRowsOf($_cmdIds, $_from, $_to) {
		$rows = array();
		$ids = implode(',', array_map('intval', $_cmdIds));
		foreach (array('history', 'historyArch') as $table) {
			$sql = 'SELECT cmd_id, `datetime`, value FROM ' . $table . ' WHERE cmd_id IN (' . $ids . ') AND `datetime`>=:start AND `datetime`<=:end ORDER BY cmd_id, `datetime`';
			$found = DB::Prepare($sql, array('start' => self::dayStart($_from), 'end' => self::dayEnd($_to)), DB::FETCH_TYPE_ALL);
			foreach ((array) $found as $row) {
				$rows[] = array($table, intval($row['cmd_id']), $row['datetime'], $row['value']);
			}
		}
		return $rows;
	}

	public static function deleteRowsOf($_cmdIds, $_from, $_to) {
		foreach (array('history', 'historyArch') as $table) {
			foreach ($_cmdIds as $id) {
				DB::Prepare('DELETE FROM ' . $table . ' WHERE cmd_id=:cmd_id AND `datetime`>=:start AND `datetime`<=:end', array('cmd_id' => intval($id), 'start' => self::dayStart($_from), 'end' => self::dayEnd($_to)), DB::FETCH_TYPE_ROW);
			}
		}
	}

	public static function insertRow($_table, $_cmdId, $_datetime, $_value) {
		if (!in_array($_table, array('history', 'historyArch'), true)) {
			throw new Exception('Table inconnue : ' . $_table);
		}
		DB::Prepare('REPLACE INTO ' . $_table . ' (cmd_id, `datetime`, value) VALUES (:cmd_id, :datetime, :value)', array('cmd_id' => intval($_cmdId), 'datetime' => $_datetime, 'value' => $_value), DB::FETCH_TYPE_ROW);
	}

	public static function listBackups() {
		$list = array();
		foreach ((array) glob(self::backupDir() . '/catchup_*.json') as $path) {
			$data = json_decode(strval(@file_get_contents($path)), true);
			if (!is_array($data)) {
				continue;
			}
			$list[] = array(
				'name' => basename($path),
				'created' => $data['created'],
				'from' => $data['from'],
				'to' => $data['to'],
				'days' => count($data['writtenDays']),
				'kind' => isset($data['kind']) ? $data['kind'] : 'import',
				'rows' => count($data['rows']),
				'restored' => !empty($data['restored']),
			);
		}
		usort($list, function ($a, $b) {
			return strcmp($b['created'], $a['created']);
		});
		return $list;
	}

	public static function backupPath($_name) {
		if (!is_string($_name) || !preg_match('/^catchup_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}\.json$/', $_name)) {
			throw new Exception(__('Nom de sauvegarde invalide.', __FILE__));
		}
		return self::backupDir() . '/' . $_name;
	}

	public static function deleteBackup($_name) {
		$path = self::backupPath($_name);
		if (is_file($path)) {
			unlink($path);
			log::add('energy', 'info', '[catchup] backup deleted: ' . $_name);
		}
	}

	/**
	 * Puts the history of the commands back as it was in a backup: the days written are erased, the rows saved are
	 * written again in the table they came from, and the setting "compteur fiable" gets its previous value.
	 */
	public static function restore($_name) {
		$lines = array();
		$path = self::backupPath($_name);
		$data = json_decode(strval(@file_get_contents($path)), true);
		if (!is_array($data) || !isset($data['rows'])) {
			throw new Exception(__('Sauvegarde illisible : ', __FILE__) . $_name);
		}
		self::say($lines, 'info', 'restore ' . $_name . ': ' . count($data['rows']) . ' rows, ' . $data['from'] . ' → ' . $data['to']);
		self::deleteRowsOf(array_values($data['cmds']), $data['from'], $data['to']);
		foreach ($data['rows'] as $row) {
			self::insertRow($row[0], $row[1], $row[2], $row[3]);
		}
		if (isset($data['reliableChanged']) && $data['reliableChanged']) {
			config::save('counterReliableSince', $data['reliableOld'], 'energy');
			self::say($lines, 'info', 'setting counterReliableSince back to "' . $data['reliableOld'] . '"');
		}
		$data['restored'] = date('Y-m-d H:i:s');
		file_put_contents($path, json_encode($data));
		self::say($lines, 'info', 'restore done');
		return implode("\n", $lines);
	}

	/* ------------------------------------------------------------------ import (step 4) */

	public static function statePath($_id) {
		return self::dir('catchup') . '/' . $_id . '.json';
	}

	public static function loadState($_id) {
		$state = json_decode(strval(@file_get_contents(self::statePath($_id))), true);
		return is_array($state) ? $state : null;
	}

	public static function saveState($_id, $_state) {
		file_put_contents(self::statePath($_id), json_encode($_state));
	}

	/**
	 * Runs ONE step of the import. The first step ("check") records the choices of the user and computes the plan; the
	 * next ones only read that state, never the browser.
	 *
	 * @return array {step, label, ok, done, next, message, text}
	 */
	public static function runStep($_fileId, $_step, $_params = array()) {
		if (!isset(self::STEPS[$_step])) {
			throw new Exception(__('Étape inconnue : ', __FILE__) . $_step);
		}
		$names = array_keys(self::STEPS);
		$started = microtime(true);
		if ($_step == 'check') {
			$analysis = self::analyze($_params, $_fileId);
			$file = self::loadFile($_fileId);
			$p = self::validateParams($_params, $file);
			$state = array(
				'energy_id' => $p['energy']->getId(),
				'from' => $p['from'],
				'to' => $p['to'],
				'onlyEmpty' => $p['onlyEmpty'],
				'sha256' => $file['sha256'],
				'plan' => array_values(array_filter($analysis['days'], function ($d) {
					return $d['status'] != 'keep';
				})),
				'done' => array(),
				'log' => array(),
				'created' => date('Y-m-d H:i:s'),
			);
			if (count($state['plan']) == 0) {
				throw new Exception(__('Rien à écrire : tous les jours de la plage ont déjà des données du plugin (décochez l\'option pour les remplacer).', __FILE__));
			}
		} else {
			if (!self::validId($_fileId)) {
				throw new Exception(__('Identifiant d\'import invalide.', __FILE__));
			}
			$state = self::loadState($_fileId);
			if ($state === null || !in_array('check', $state['done'])) {
				throw new Exception(__('L\'import n\'a pas été contrôlé : recommencez à l\'étape 3.', __FILE__));
			}
			$previous = array_search($_step, $names) - 1;
			if (!in_array($names[$previous], $state['done'])) {
				throw new Exception(__('L\'étape précédente n\'est pas terminée : ', __FILE__) . $names[$previous]);
			}
			if (in_array($_step, $state['done'])) {
				throw new Exception(__('Cette étape est déjà faite : ', __FILE__) . $_step);
			}
		}
		$lines = array();
		self::say($lines, 'info', 'step "' . $_step . '" start');
		try {
			$message = self::{'step' . ucfirst($_step)}($state, $lines);
		} catch (Exception $e) {
			self::say($lines, 'error', 'step "' . $_step . '" failed: ' . $e->getMessage());
			$state['log'] = array_merge($state['log'], $lines);
			$state['failed'] = $_step;
			if ($_step != 'check') {
				self::saveState($_fileId, $state);
			}
			return array('step' => $_step, 'label' => self::STEPS[$_step], 'ok' => false, 'done' => true, 'next' => null, 'message' => $e->getMessage(), 'text' => implode("\n", $state['log']), 'backup' => isset($state['backup']) ? $state['backup'] : null);
		}
		$state['done'][] = $_step;
		self::say($lines, 'info', 'step "' . $_step . '" done in ' . round((microtime(true) - $started) * 1000) . ' ms: ' . $message);
		$state['log'] = array_merge($state['log'], $lines);
		self::saveState($_fileId, $state);
		$index = array_search($_step, $names);
		$finished = !isset($names[$index + 1]);
		if ($finished) {
			self::say($state['log'], 'info', 'import finished: ' . count($state['plan']) . ' day(s), backup ' . $state['backup']);
			self::discard($_fileId);
		}
		return array('step' => $_step, 'label' => self::STEPS[$_step], 'ok' => true, 'done' => $finished, 'next' => $finished ? null : $names[$index + 1], 'message' => $message, 'text' => implode("\n", $state['log']), 'backup' => isset($state['backup']) ? $state['backup'] : null);
	}

	private static function stepCheck(&$_state, &$_lines) {
		self::say($_lines, 'debug', 'plan: ' . count($_state['plan']) . ' day(s) kept for writing');
		return count($_state['plan']) . ' jour(s) à écrire';
	}

	private static function stepBackup(&$_state, &$_lines) {
		$energy = self::target($_state['energy_id']);
		$cmds = self::commands($energy);
		$rows = self::readRowsOf(array_values($cmds), $_state['from'], $_state['to']);
		$name = 'catchup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json';
		$data = array(
			'version' => 1,
			'created' => date('Y-m-d H:i:s'),
			'energy_id' => $energy->getId(),
			'cmds' => $cmds,
			'from' => $_state['from'],
			'to' => $_state['to'],
			'sha256' => $_state['sha256'],
			'reliableOld' => strval(config::byKey('counterReliableSince', 'energy', '')),
			'reliableChanged' => false,
			'writtenDays' => array_column($_state['plan'], 'date'),
			'rows' => $rows,
		);
		$path = self::backupPath($name);
		$json = json_encode($data);
		if ($json === false || file_put_contents($path, $json) === false) {
			throw new Exception(__('La sauvegarde n\'a pas pu être écrite : l\'import est annulé, rien n\'a été modifié.', __FILE__));
		}
		// read it back: no write is allowed without a backup that can be read
		$back = json_decode(strval(@file_get_contents($path)), true);
		if (!is_array($back) || count($back['rows']) != count($rows) || filesize($path) != strlen($json)) {
			@unlink($path);
			throw new Exception(__('La sauvegarde relue ne correspond pas : l\'import est annulé, rien n\'a été modifié.', __FILE__));
		}
		$_state['backup'] = $name;
		self::say($_lines, 'debug', 'backup file ' . $name . ': ' . count($rows) . ' existing rows, ' . strlen($json) . ' bytes');
		return $name . ' (' . count($rows) . ' ligne(s) existantes)';
	}

	private static function updateBackup($_state, $_changes) {
		$path = self::backupPath($_state['backup']);
		$data = json_decode(strval(file_get_contents($path)), true);
		file_put_contents($path, json_encode(array_merge($data, $_changes)));
	}

	private static function stepReliable(&$_state, &$_lines) {
		$current = trim(strval(config::byKey('counterReliableSince', 'energy', '')));
		$first = $_state['plan'][0]['date'];
		if ($current != '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $current) && $current > $first) {
			config::save('counterReliableSince', $first, 'energy');
			self::updateBackup($_state, array('reliableChanged' => true));
			self::say($_lines, 'debug', 'counterReliableSince "' . $current . '" => "' . $first . '"');
			return 'reculé de ' . $current . ' à ' . $first;
		}
		return 'inchangé (' . ($current == '' ? 'pas de limite' : $current) . ')';
	}

	/** Erases the rows of the days to write, then writes the values of one command. */
	private static function writeCommand($_state, $_logicalId, $_valueOf, &$_lines) {
		$energy = self::target($_state['energy_id']);
		$cmds = self::commands($energy);
		$written = 0;
		foreach ($_state['plan'] as $day) {
			$value = $_valueOf($day);
			if ($value === null) {
				continue;
			}
			self::deleteRowsOf(array($cmds[$_logicalId]), $day['date'], $day['date']);
			self::insertRow('history', $cmds[$_logicalId], $day['date'] . ' 12:00:00', $value);
			$written++;
		}
		self::say($_lines, 'debug', $_logicalId . ': ' . $written . ' row(s) written');
		return $written;
	}

	private static function stepTotal(&$_state, &$_lines) {
		// every command of the days to write is emptied first, so that a replaced day keeps no old value
		$energy = self::target($_state['energy_id']);
		$cmds = self::commands($energy);
		foreach ($_state['plan'] as $day) {
			self::deleteRowsOf(array_values($cmds), $day['date'], $day['date']);
		}
		$n = self::writeCommand($_state, 'consumptionTotal', function ($d) {
			return $d['kwh'];
		}, $_lines);
		return $n . ' jour(s)';
	}

	private static function stepHphc(&$_state, &$_lines) {
		$hc = self::writeCommand($_state, 'consumptionHc', function ($d) {
			return $d['hc'];
		}, $_lines);
		$hp = self::writeCommand($_state, 'consumptionHp', function ($d) {
			return $d['hp'];
		}, $_lines);
		return $hc . ' HC, ' . $hp . ' HP';
	}

	private static function stepCost(&$_state, &$_lines) {
		$n = self::writeCommand($_state, 'cost', function ($d) {
			return $d['cost'];
		}, $_lines);
		return $n . ' jour(s)';
	}

	private static function stepFlag(&$_state, &$_lines) {
		$n = self::writeCommand($_state, 'consumptionCatchup', function ($d) {
			return 1;
		}, $_lines);
		return $n . ' jour(s) marqués';
	}

	private static function stepVerify(&$_state, &$_lines) {
		$energy = self::target($_state['energy_id']);
		$found = self::pluginDays($energy, $_state['from'], $_state['to']);
		$bad = array();
		$sum = 0;
		foreach ($_state['plan'] as $day) {
			$read = isset($found[$day['date']]) ? $found[$day['date']] : null;
			$sum += $day['kwh'];
			if ($read === null || abs($read - $day['kwh']) > 0.001) {
				$bad[] = $day['date'] . ' (attendu ' . $day['kwh'] . ', lu ' . ($read === null ? 'rien' : $read) . ')';
			}
		}
		if (count($bad) > 0) {
			self::say($_lines, 'error', 'verification: ' . count($bad) . ' day(s) differ: ' . implode('; ', array_slice($bad, 0, 20)));
			throw new Exception(count($bad) . ' jour(s) différents après écriture : ' . implode(', ', array_slice($bad, 0, 5)) . '. Restaurez la sauvegarde ' . $_state['backup'] . '.');
		}
		return count($_state['plan']) . ' jour(s) relus, ' . round($sum, 3) . ' kWh';
	}
}

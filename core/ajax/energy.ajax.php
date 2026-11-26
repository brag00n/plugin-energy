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

try {
	require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
	include_file('core', 'authentification', 'php');

	if (!isConnect()) {
		throw new Exception(__('401 - Accès non autorisé', __FILE__));
	}

	if (init('action') == 'getData') {
		if (init('object_id') == '') {
			$_GET['object_id'] = $_SESSION['user']->getOptions('defaultDashboardObject');
		}
		$object = jeeObject::byId(init('object_id'));
		if (!is_object($object)) {
			$object = jeeObject::rootObject();
		}
		if (!is_object($object)) {
			throw new Exception('{{Aucun objet racine trouvé}}');
		}
		$date = array(
			'start' => init('dateStart'),
			'end' => init('dateEnd'),
		);
		foreach ($date as $key => $value) {
			if ($value != '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
				throw new Exception(__('Format de date invalide (attendu AAAA-MM-JJ) : ', __FILE__) . $value);
			}
		}
		if ($date['start'] == '') {
			$date['start'] = date('Y-m-d', strtotime('-7 days'));
			if (init('groupBy', 'day') == 'month') {
				$date['start'] = date('Y-m-d', strtotime('-1 year'));
			}
		}
		if ($date['end'] == '') {
			$date['end'] = date('Y-m-d');
		}
		$energyType = init('energyType', 'electricity');
		if (!in_array($energyType, array('electricity', 'water', 'gas'))) {
			throw new Exception(__('Type d\'énergie invalide : ', __FILE__) . $energyType);
		}
		if (energy::isPerfEnabled()) {
			// ajax::success() ends the script: log the total duration of the request when PHP shuts down.
			// Compared with the time felt in the browser, it tells whether the delay is on the server or in the page.
			$perfRequestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
			register_shutdown_function(function () use ($perfRequestStart, $object) {
				log::add('energy', 'debug', '[perf] ajax getData for object ' . $object->getId() . ' finished: ' . round((microtime(true) - $perfRequestStart) * 1000) . ' ms since the request started, peak memory ' . round(memory_get_peak_usage(true) / 1048576) . ' MB');
			});
		}
		$energy = energy::getGraph($date['start'] . ' 00:00:00', $date['end'], $object->getId(), $energyType);
		ajax::success(array(
			'datas' => $energy,
			'date' => $date,
			'object' => utils::o2a($object),
			'currency' => config::byKey('currency', 'energy', '€'),
			'enableElectricity' => config::byKey('enableElectricity', 'energy'),
			'enableWater' => config::byKey('enableWater', 'energy'),
			'enableGas' => config::byKey('enableGas', 'energy'),
		));
	}

	if (init('action') == 'emptyHistory') {
		if (!isConnect('admin')) {
			throw new Exception(__('401 - Accès non autorisé', __FILE__));
		}
		$eqLogic = eqLogic::byId(init('id'));
		if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'energy') {
			throw new Exception(__('Equipement introuvable : ', __FILE__) . init('id'));
		}
		$eqLogic->emptyHistory();
		ajax::success();
	}

	/* CATCH-UP of the history from an Enedis export: administrator only, see core/class/energyCatchup.class.php */
	if (strpos(init('action'), 'catchup') === 0) {
		if (!isConnect('admin')) {
			throw new Exception(__('401 - Accès non autorisé', __FILE__));
		}
		// The Jeedom autoloader only knows the class named like the plugin (energy): energy.class.php loads the helper classes
		require_once dirname(__FILE__) . '/../class/energy.class.php';
		if (init('action') == 'catchupInit') {
			ajax::success(array('targets' => energyCatchup::targets(), 'backups' => energyCatchup::listBackups(), 'periods' => energyRates::jsonSetting('catchupPeriods'), 'steps' => energyCatchup::STEPS));
		}
		if (init('action') == 'catchupUpload') {
			if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
				throw new Exception(__('Aucun fichier reçu (ou fichier trop gros pour le serveur).', __FILE__));
			}
			ajax::success(energyCatchup::storeUpload($_FILES['file']['tmp_name'], $_FILES['file']['name']));
		}
		if (init('action') == 'catchupPeriodHint') {
			ajax::success(energyCatchup::periodHint(init('energy_id'), init('from'), init('to')));
		}
		if (init('action') == 'catchupSavePeriods') {
			$periods = energyCatchup::normalizePeriods(json_decode(init('periods'), true));
			config::save('catchupPeriods', json_encode($periods), 'energy');
			ajax::success($periods);
		}
		if (init('action') == 'catchupAnalyze') {
			ajax::success(energyCatchup::analyze(json_decode(init('params'), true), init('id')));
		}
		if (init('action') == 'catchupStep') {
			ajax::success(energyCatchup::runStep(init('id'), init('step'), json_decode(init('params'), true)));
		}
		if (init('action') == 'catchupDiscard') {
			energyCatchup::discard(init('id'));
			ajax::success();
		}
		if (init('action') == 'catchupRestore') {
			ajax::success(energyCatchup::restore(init('name')));
		}
		/* Tariff periods and the cost catch-up of one of them (see energyRates and energyRateCatchup) */
		if (init('action') == 'catchupRatePeriods') {
			ajax::success(array('periods' => energyRates::periods(), 'targets' => energyCatchup::targets(), 'steps' => energyRateCatchup::STEPS));
		}
		if (init('action') == 'catchupRateSave') {
			ajax::success(energyRates::save(json_decode(init('periods'), true)));
		}
		if (init('action') == 'catchupRatePreview') {
			ajax::success(energyRateCatchup::preview(init('energy_id'), init('from')));
		}
		if (init('action') == 'catchupRateStep') {
			ajax::success(energyRateCatchup::runStep(init('id'), init('step'), init('energy_id'), init('from')));
		}
		if (init('action') == 'catchupDelete') {
			energyCatchup::deleteBackup(init('name'));
			ajax::success();
		}
	}

	throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
	/*     * *********Catch exeption*************** */
} catch (Exception $e) {
	ajax::error(displayException($e), $e->getCode());
}
?>

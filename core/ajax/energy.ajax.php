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

	throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
	/*     * *********Catch exeption*************** */
} catch (Exception $e) {
	ajax::error(displayExeption($e), $e->getCode());
}
?>

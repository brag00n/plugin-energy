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

	if (init('action') == 'applyCostTo') {
		if (!isConnect('admin')) {
			throw new Exception(__('401 - Accès non autorisé', __FILE__));
		}
		if (init('cost') == '') {
			throw new Exception(__('Vous devez d\'abord enregistrer le coût avant de pouvoir l\'appliquer : ', __FILE__) . init('cost'));
		}
		$cost = config::byKey(init('cost'), 'energy');
		if (!is_array($cost)) {
			throw new Exception(__('Aucun coût trouvé : ', __FILE__) . init('cost'));
		}
		$ids = json_decode(init('ids'), true);
		foreach ($ids as $id) {
			$eqLogic = eqLogic::byId($id);
			if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'energy') {
				continue;
			}
			$eqLogic->setConfiguration('rateMode', $cost['rateMode']);
			$eqLogic->setConfiguration('rate', $cost['rate']);
			$eqLogic->setConfiguration('rateHp', $cost['rateHp']);
			$eqLogic->setConfiguration('rateHc', $cost['rateHc']);
			$eqLogic->setConfiguration('startHc', $cost['startHc']);
			$eqLogic->setConfiguration('endHc', $cost['endHc']);
			$eqLogic->setConfiguration('startHc2', $cost['startHc2']);
			$eqLogic->setConfiguration('endHc2', $cost['endHc2']);
			$eqLogic->setConfiguration('startHc3', $cost['startHc3']);
			$eqLogic->setConfiguration('endHc3', $cost['endHc3']);
			$eqLogic->save();
		}
		ajax::success();
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
		if ($date['start'] == '') {
			$date['start'] = date('Y-m-d', strtotime('-1 month'));
			if (init('groupBy', 'day') == 'month') {
				$date['start'] = date('Y-m-d', strtotime('-1 year'));
			}
		}
		if ($date['end'] == '') {
			$date['end'] = date('Y-m-d');
		}
		$energy = energy::getGraph($date['start'] . ' 00:00:00', $date['end'], $object->getId(), init('energyType'));
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

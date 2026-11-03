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

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class energy extends eqLogic {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Méthodes statiques*************************** */
	public static function getConfEnergyKey($_confEnergy, $_position = 0) {
		$key = '';
		if (isset($_confEnergy['power']) && $_confEnergy['power'] != '') {
			preg_match_all("/#([0-9]*)#/", $_confEnergy['power'], $matches);
			foreach ($matches[1] as $cmd_id) {
				$key .= '#' . $cmd_id . '#';
			}
		}
		if (isset($_confEnergy['consumption']) && $_confEnergy['consumption'] != '') {
			preg_match_all("/#([0-9]*)#/", $_confEnergy['consumption'], $matches);
			foreach ($matches[1] as $cmd_id) {
				$key .= '#' . $cmd_id . '#';
			}
		}
		if ($key === '') {
			$key = $_position;
		}
		return $key;
	}

	/**
	 * Tells if an hour (format Gi, e.g. 2230) is inside a range, handling ranges crossing midnight (e.g. 2200 -> 0600).
	 * An unset range (negative value, or start equal to end) never matches.
	 */
	public static function isInHourRange($_hourtime, $_start, $_end) {
		$hourtime = intval($_hourtime);
		$start = intval($_start);
		$end = intval($_end);
		if ($start < 0 || $end < 0 || $start == $end) {
			return false;
		}
		if ($start < $end) {
			return ($hourtime >= $start && $hourtime <= $end);
		}
		return ($hourtime >= $start || $hourtime <= $end);
	}

	public static function cron15() {
		$datetime = date('Y-m-d H:i:00');
		$powerTotal = 0;
		foreach (self::byType('energy',true) as $energy) {
			if ($energy->getConfiguration('type') == 'electricity') {
				$totalConsumption = 0;
				$power = 0;
				$consumptions = array('other' => 0, 'light' => 0, 'multimedia' => 0, 'heating' => 0, 'electrical' => 0, 'automatism' => 0, 'hc' => 0, 'hp' => 0);
				$position = 0;
				foreach ($energy->getConfiguration('confEnergy') as $confEnergy) {
					$key = self::getConfEnergyKey($confEnergy, $position);
					if ($confEnergy['power'] != '') {
						$value = floatval(jeedom::evaluateExpression($confEnergy['power']));
						$power += $value;
						if ($confEnergy['consumption'] == '') {
							$nowtime = strtotime('now');
							$lastChange = $energy->getConfiguration('lastChangeTime' . $key);
							$lastValue = $energy->getConfiguration('lastValue' . $key);
							$consumptions[$confEnergy['category']] += (($lastValue * ($nowtime - $lastChange)) / 3600) / 1000;
							$energy->setConfiguration('lastChangeTime' . $key, $nowtime);
							$energy->setConfiguration('lastValue' . $key, $value);
						}
					}
					if ($confEnergy['consumption'] != '') {
						$previous = $energy->getConfiguration('previous' . $key, 0);
						$value = floatval(jeedom::evaluateExpression($confEnergy['consumption']));
						if (($value - $previous) >= 0) {
							$consumptions[$confEnergy['category']] += ($value - $previous);
							$energy->setConfiguration('previous' . $key, $value);
						}
					}
					$position++;
				}
				$energy->save();
				foreach ($consumptions as $name => $consumption) {
					if ($energy->getConfiguration('totalCounter') == 0 && $consumption > 0) {
						$cmd = $energy->getCmd(null, 'consumption' . ucfirst($name));
						if (is_object($cmd)) {
							$previous = $cmd->execCmd();
							$cmd->setCollectDate($datetime);
							$cmd->event($consumption + $previous);
						}
					}
					$totalConsumption += $consumption;
				}
				if ($consumptions['hc'] > 0) {
					$cmd = $energy->getCmd(null, 'consumptionHc');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						log::add('energy', 'debug', 'HC : ' . $consumptions['hc'] . '+' . $previous);
						$cmd->event($consumptions['hc'] + $previous);
					}
				}
				if ($consumptions['hp'] > 0) {
					$cmd = $energy->getCmd(null, 'consumptionHp');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						log::add('energy', 'debug', 'HP : ' . $consumptions['hp'] . '+' . $previous);
						$cmd->event($consumptions['hp'] + $previous);
					}
				}
				if ($totalConsumption > 0) {
					$cmd = $energy->getCmd(null, 'consumptionTotal');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						log::add('energy', 'debug', 'Total : ' . $totalConsumption . '+' . $previous);
						$cmd->event($totalConsumption + $previous);
					}
					$calculCost = 0;
					if (config::byKey('rateMode', 'energy') == 'variable') {
						if ($consumptions['hp'] != 0 || $consumptions['hc'] != 0) {
							$calculCost = $consumptions['hp'] * str_replace(',', '.', config::byKey('rateHp', 'energy')) + $consumptions['hc'] * str_replace(',', '.', config::byKey('rateHc', 'energy'));
						} else {
							$rateHour = array(
								'startHp' => intval(ltrim(str_replace(':', '', config::byKey('startHc', 'energy', -1)), '0')),
								'endHp' => intval(ltrim(str_replace(':', '', config::byKey('endHc', 'energy', -1)), '0')),
								'startHp2' => intval(ltrim(str_replace(':', '', config::byKey('startHc2', 'energy',-1)), '0')),
								'endHp2' => intval(ltrim(str_replace(':', '', config::byKey('endHc2', 'energy', -1)), '0')),
								'startHp3' => intval(ltrim(str_replace(':', '', config::byKey('startHc3', 'energy', -1)), '0')),
								'endHp3' => intval(ltrim(str_replace(':', '', config::byKey('endHc3', 'energy', -1)), '0')),
								'rateHp' => str_replace(',', '.', config::byKey('rateHp', 'energy', 0)),
								'rateHc' => str_replace(',', '.', config::byKey('rateHc', 'energy', 0)),
							);
							$hourtime = date('Gi', strtotime($datetime));
							if (self::isInHourRange($hourtime, $rateHour['startHp'], $rateHour['endHp'])) {
								$calculCost = $totalConsumption * $rateHour['rateHc'];
							}

							if (self::isInHourRange($hourtime, $rateHour['startHp2'], $rateHour['endHp2'])) {
								$calculCost = $totalConsumption * $rateHour['rateHc'];
							}

							if (self::isInHourRange($hourtime, $rateHour['startHp3'], $rateHour['endHp3'])) {
								$calculCost = $totalConsumption * $rateHour['rateHc'];
							}
							if ($calculCost == 0) {
								$calculCost = $totalConsumption * $rateHour['rateHp'];
							}
						}
					} else {
						$cost = jeedom::evaluateExpression(str_replace(',', '.', config::byKey('rate', 'energy', 0)));
						$calculCost = $totalConsumption * $cost;
					}
					$cmd = $energy->getCmd(null, 'cost');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						$cmd->event($calculCost + $previous);
					}
				}
				$cmd = $energy->getCmd(null, 'power');
				if (is_object($cmd)) {
					$cmd->setCollectDate($datetime);
					$cmd->event($power);
				}
			} else {
				$totalConsumption = 0;
				$position = 0;
				foreach ($energy->getConfiguration('confEnergy') as $confEnergy) {
					$key = self::getConfEnergyKey($confEnergy, $position);
					$previous = $energy->getConfiguration('previous' . $key, 0);
					$value = floatval(evaluate(cmd::cmdToValue($confEnergy['consumption'])));
					if (($value - $previous) >= 0) {
						$totalConsumption += ($value - $previous);
						$energy->setConfiguration('previous' . $key, $value);
					}
					$position++;
				}
				$energy->save();
				if ($totalConsumption > 0) {
					$cmd = $energy->getCmd(null, 'consumptionTotal');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						$cmd->event($totalConsumption + $previous);
					}
					$cost = $cost = jeedom::evaluateExpression(str_replace(',', '.', config::byKey('rate' . ucfirst($energy->getConfiguration('type')), 'energy', 0)));
					$calculCost = $totalConsumption * $cost;
					$cmd = $energy->getCmd(null, 'cost');
					if (is_object($cmd)) {
						$previous = $cmd->execCmd();
						$cmd->setCollectDate($datetime);
						$cmd->event($calculCost + $previous);
					}
				}
			}
		}
	}

	public static function cronDaily() {
		sleep(60);
		$datetime = date('Y-m-d 00:00:00');
		foreach (self::byType('energy',true) as $energy) {
			$cmd = $energy->getCmd(null, 'cost');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionOther');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionLight');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionMultimedia');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionAutomatism');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionElectrical');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionHeating');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionTotal');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionHp');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
			$cmd = $energy->getCmd(null, 'consumptionHc');
			if (is_object($cmd)) {
				$cmd->setCollectDate($datetime);
				$cmd->event(0);
			}
		}
	}

	public static function getGraph($_startTime = null, $_endTime = null, $_object_id, $_energyType = 'electricity') {
		$return = array(
			'category' => array('other' => array(), 'light' => array(), 'multimedia' => array(), 'heating' => array(), 'electrical' => array(), 'automatism' => array()),
			'cost' => array(),
			'totalConsumption' => array(),
			'translation' => array('other' => __('Autre', __FILE__), 'light' => __('Lumière', __FILE__), 'multimedia' => __('Multimedia', __FILE__), 'heating' => __('Chauffage', __FILE__), 'electrical' => __('Electroménager', __FILE__), 'automatism' => __('Automatisme', __FILE__)),
			'object' => array(),
			'total' => array('power' => 0, 'consumption' => 0, 'cost' => 0),
			'consumptionUnite' => 'kWh',
			'power' => array(),
		);
		if ($_energyType == 'water') {
			$return['consumptionUnite'] = 'L';
		}
		$object = jeeObject::byId($_object_id);
		if (!is_object($object)) {
			throw new Exception(__('Objet non trouvé. Vérifiez l\'id : ', __FILE__) . $_object_id);
		}
		$objects = $object->getChilds();
		$objects[] = $object;

		foreach ($objects as $object) {
			$return['object'][$object->getName()] = array();
			$return['power'][$object->getName()] = array();
			foreach ($object->getEqLogic(true, false, 'energy') as $energy) {
				if ($energy->getConfiguration('type') != $_energyType) {
					continue;
				}
				$cmd = $energy->getCmd(null, 'power');
				if (is_object($cmd)) {
					$return['total']['power'] += $cmd->execCmd();
					foreach ($cmd->getHistory($_startTime, date('Y-m-d 23:59:59', strtotime($_endTime))) as $value) {
						$datetime = floatval(strtotime($value->getDatetime() . " UTC")) * 1000;
						if (isset($return['power'][$object->getName()][$datetime])) {
							$return['power'][$object->getName()][$datetime][1] += floatval($value->getValue());
						} else {
							$return['power'][$object->getName()][$datetime] = array($datetime, floatval($value->getValue()));
						}
					}
				}




				//log::add('energy', 'debug', '-------TOTAL CONSOMMATION------- => ' . $object->getName());
				foreach ($energy->getData($_startTime, $_endTime) as $key => $values) {
					
					
					if ($key != 'consumptionTotal') {
						continue;
					}
					foreach ($values as $value) {
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						//log::add('energy', 'debug', '   > date -' . $value[0]);
						if (isset($return['totalConsumption'][$value[0]])) {
							$return['totalConsumption'][$value[0]][1] += floatval($value[1]);
							//log::add('energy', 'debug', '   > update -' . strval($return['totalConsumption'][$value[0]][1]));
						} else {
							$return['totalConsumption'][$value[0]] = array($value[0], floatval($value[1]));
							//log::add('energy', 'debug', '   > New -' . strval($value[1]));
						}
					}
				}
				//log::add('energy', 'debug', '______________________________________');

				
				if ($energy->getConfiguration('totalCounter') == 1) {
					continue;
				}
				foreach ($energy->getData($_startTime, $_endTime) as $key => $values) {
					if ($key == 'consumptionTotal') {
						continue;
					}
					foreach ($values as $value) {
						//log::add('energy', 'debug', '- key : ' . $object->getName() . ' ' . $key);
						
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;

						$date = new DateTime();
						$date->setTimestamp($value[0]/1000);
						$date->setTimezone(new DateTimeZone('UTC'));

						if ($key == 'cost') {
							$return['total']['cost'] += floatval($value[1]);
							if (isset($return['cost'][$value[0]])) {
								$return['cost'][$value[0]][1] += floatval($value[1]);
							} else {
								$return['cost'][$value[0]] = array($value[0], floatval($value[1]));
							}
							continue;
						}

						//log::add('energy', 'debug', '- ' . $object->getName() . ' - date : ' . $date->format('Y-m-d H:i:s') . ' conso : ' . strval($value[1]));

						$return['total']['consumption'] += floatval($value[1]);
						if (isset($return['category'][$key][$value[0]])) {
							$return['category'][$key][$value[0]][1] += floatval($value[1]);
						} else {
							$return['category'][$key][$value[0]] = array($value[0], floatval($value[1]));
						}
						if (isset($return['object'][$object->getName()][$value[0]])) {
							$return['object'][$object->getName()][$value[0]][1] += floatval($value[1]);
						} else {
							$return['object'][$object->getName()][$value[0]] = array($value[0], floatval($value[1]));
						}
					}

				}
			}
		}

		foreach ($object->getEqLogic(true, false, 'energy') as $energy) {
			if ($energy->getConfiguration('type') == $_energyType && $energy->getConfiguration('totalCounter') == 1) {
				$return['totalConsumption'] = array();
				$return['cost'] = array();
				$cmd = $energy->getCmd(null, 'consumptionTotal');
				if (is_object($cmd)) {
					$return['total']['consumption'] = 0;
					foreach ($cmd->getHistoryEnergy($_startTime, $_endTime) as $value) {
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;
						if (isset($return['totalConsumption'][$value[0]])) {
							$return['totalConsumption'][$value[0]][1] += floatval($value[1]);
						} else {
							$return['totalConsumption'][$value[0]] = array($value[0], floatval($value[1]));
						}
						$return['total']['consumption'] += floatval($value[1]);
					}
				}
				$cmd = $energy->getCmd(null, 'cost');
				if (is_object($cmd)) {
					$return['total']['cost'] = 0;
					foreach ($cmd->getHistoryEnergy($_startTime, $_endTime) as $value) {
						if (!is_numeric($value[1]) || $value[1] == 0) {
							continue;
						}
						$value[0] = floatval(strtotime($value[0] . " UTC")) * 1000;
						if (isset($return['cost'][$value[0]])) {
							$return['cost'][$value[0]][1] += floatval($value[1]);
						} else {
							$return['cost'][$value[0]] = array($value[0], floatval($value[1]));
						}
						$return['total']['cost'] += floatval($value[1]);
					}
				}
				break;
			}
		}
		if (isset($return['totalConsumption'])) {
			$return['totalConsumption'] = array_values($return['totalConsumption']);
		}

		if (isset($return['cost'])) {
			sort($return['cost']);
			$return['cost'] = array_values($return['cost']);
		}
		foreach ($return['power'] as &$value) {
			sort($value);
			$value = array_values($value);
		}
		foreach ($return['category'] as &$value) {
			sort($value);
			$value = array_values($value);
		}
		foreach ($return['object'] as &$value) {
			sort($value);
			$value = array_values($value);
		}
		$return['total']['consumption'] = round($return['total']['consumption'], 2);
		$return['total']['cost'] = round($return['total']['cost'], 2);
		return $return;
	}

/*     * *********************Méthodes d'instance************************* */

	public function getData($_startTime = null, $_endTime = null) {
		$return = array('total' => array(),'other' => array(), 'light' => array(), 'multimedia' => array(), 'heating' => array(), 'electrical' => array(), 'automatism' => array(), 'cost' => array());
		
		//ajout consumptionTotal by Frixo
		$cmd = $this->getCmd(null, 'consumptionTotal');
		if (is_object($cmd)) {
			$return['consumptionTotal'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}

		$cmd = $this->getCmd(null, 'consumptionOther');
		if (is_object($cmd)) {
			$return['other'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionLight');
		if (is_object($cmd)) {
			$return['light'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionMultimedia');
		if (is_object($cmd)) {
			$return['multimedia'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionAutomatism');
		if (is_object($cmd)) {
			$return['automatism'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionElectrical');
		if (is_object($cmd)) {
			$return['electrical'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'consumptionHeating');
		if (is_object($cmd)) {
			$return['heating'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		$cmd = $this->getCmd(null, 'cost');
		if (is_object($cmd)) {
			$return['cost'] = $cmd->getHistoryEnergy($_startTime, $_endTime);
		}
		return $return;
	}

	public function emptyHistory() {
		foreach ($this->getCmd('info') as $cmd) {
			history::emptyHistory($cmd->getId());
			$cmd->save();
			$cmd->event(0);
		}
		foreach ($this->getConfiguration('confEnergy') as $confEnergy) {
			$key = self::getConfEnergyKey($confEnergy);
			if ($this->getConfiguration('type') == 'electricity') {
				$this->setConfiguration('lastChangeTime' . $key, strtotime('now'));
				$this->setConfiguration('lastValue' . $key, 0);
				if ($confEnergy['consumption'] != '') {
					$this->setConfiguration('previous' . $key, floatval(evaluate(cmd::cmdToValue($confEnergy['consumption']))));
				}
			} else {
				if ($confEnergy['consumption'] != '') {
					$this->setConfiguration('previous' . $key, floatval(evaluate(cmd::cmdToValue($confEnergy['consumption']))));
				}
			}
		}
		$this->save();
	}

	public function preInsert() {
		$this->setConfiguration('visiblePower', 1);
		$this->setConfiguration('visibleCost', 1);
		$this->setConfiguration('visibleConsumptionTotal', 1);
	}

	public function preUpdate() {
		if ($this->getConfiguration('type') == '') {
			throw new Exception('Le type ne peut pas être vide');
		}
		$this->setCategory('energy', 1);
	}

	public function postSave() {
		$cmd = $this->getCmd(null, 'cost');
		if (!is_object($cmd)) {
			$cmd = new energyCmd();
			$cmd->setLogicalId('cost');
			$cmd->setName(__('Coût', __FILE__));
			$cmd->setTemplate('dashboard', 'tile');
		}
		$cmd->setEqLogic_id($this->getId());
		$cmd->setType('info');
		$cmd->setSubType('numeric');
		$cmd->setUnite(config::byKey('currency', 'energy', '€'));
		$cmd->setIsVisible($this->getConfiguration('visibleCost', 0));
		$cmd->setIsHistorized(1);
		$cmd->save();

		$unite = '';
		if ($this->getConfiguration('type') == 'electricity') {
			$unite = 'kWh';
		} elseif ($this->getConfiguration('type') == 'water') {
			$unite = 'L';
		} elseif ($this->getConfiguration('type') == 'gas') {
			$unite = 'kWh';
		}

		$cmd = $this->getCmd(null, 'consumptionTotal');
		if (!is_object($cmd)) {
			$cmd = new energyCmd();
			$cmd->setLogicalId('consumptionTotal');
			$cmd->setTemplate('dashboard', 'tile');
			$cmd->setConfiguration('historizeMode', 'max');
			$cmd->setIsHistorized(1);
		}
		$cmd->setName(__('Consommation', __FILE__));
		$cmd->setEqLogic_id($this->getId());
		$cmd->setType('info');
		$cmd->setSubType('numeric');
		$cmd->setUnite($unite);
		$cmd->setIsVisible($this->getConfiguration('visibleConsumptionTotal', 0));
		$cmd->save();

		if ($this->getConfiguration('type') == 'electricity') {
			$cmd = $this->getCmd(null, 'power');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('power');
				$cmd->setName(__('Puissance', __FILE__));
				$cmd->setTemplate('dashboard', 'tile');
			}
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite('W');
			$cmd->setIsHistorized(1);
			$cmd->setIsVisible($this->getConfiguration('visiblePower', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionLight');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionLight');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation Lumière', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionLight', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionOther');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionOther');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation Autre', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionOther', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionMultimedia');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionMultimedia');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation Multimedia', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionMultimedia', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionAutomatism');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionAutomatism');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation Automatisme', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionAutomatism', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionElectrical');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionElectrical');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation Electromenager', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionElectrical', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionHeating');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionHeating');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation Chauffage', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionHeating', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionHp');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionHP');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation HP', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionHP', 0));
			$cmd->save();

			$cmd = $this->getCmd(null, 'consumptionHc');
			if (!is_object($cmd)) {
				$cmd = new energyCmd();
				$cmd->setLogicalId('consumptionHC');
				$cmd->setTemplate('dashboard', 'tile');
				$cmd->setConfiguration('historizeMode', 'max');
				$cmd->setIsHistorized(1);
			}
			$cmd->setName(__('Consommation HC', __FILE__));
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('numeric');
			$cmd->setUnite($unite);
			$cmd->setIsVisible($this->getConfiguration('visibleConsumptionHC', 0));
			$cmd->save();
		} else {
			$this->setConfiguration('totalCounter', 1);
		}

	}

/*     * **********************Getteur Setteur*************************** */
}

class energyCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Méthodes statiques*************************** */

	/*     * *********************Méthodes d'instance************************* */

	public function formatValueWidget($_value) {
		if ($this->getType() == 'info' && $this->getSubType() == 'numeric') {
			return round($_value, 2);
		}
		return $_value;
	}

	public function getHistoryEnergy($_startTime = null, $_endTime = null) {
		$values = array(
			'cmd_id' => $this->getId(),
		);
		if ($_startTime != null) {
			$values['startTime'] = date('Y-m-d H:i:s', strtotime('+1 hours ' . $_startTime));
		}
		if ($_endTime != null) {
			$values['endTime'] = date('Y-m-d H:i:s', strtotime('+1 day ' . $_endTime));
		}
		$sql = '
			SELECT date(`datetime`) as "0",  MAX(CAST(value as DOUBLE)) as "1"
		FROM (
			SELECT (`datetime`  - INTERVAL 240 SECOND) as `datetime`,value
			FROM history
			WHERE cmd_id=:cmd_id ';
		if ($_startTime != null) {
			$sql .= ' AND `datetime`>=:startTime';
		}
		if ($_endTime != null) {
			$sql .= ' AND `datetime`<=:endTime';
		}
		$sql .= ' UNION ALL
			SELECT (`datetime`  - INTERVAL 240 SECOND) as `datetime`,value
			FROM historyArch
			WHERE cmd_id=:cmd_id';
		if ($_startTime != null) {
			$sql .= ' AND `datetime`>=:startTime';
		}
		if ($_endTime != null) {
			$sql .= ' AND `datetime`<=:endTime';
		}
		$sql .= ' ) as dt
		GROUP BY date(`datetime`)
		ORDER BY `datetime` ASC;';
		try{
			return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL);
		}catch(Exception $e) {
			$sql = 'SET sql_mode=(SELECT REPLACE(@@sql_mode, "ONLY_FULL_GROUP_BY", ""));' . $sql . 'SET sql_mode=(SELECT CONCAT(@@sql_mode,",ONLY_FULL_GROUP_BY"))';
			return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL);
		}
	}

	public function dontRemoveCmd() {
		return true;
	}

	public function execute($_options = array()) {
		return;
	}

/*     * **********************Getteur Setteur*************************** */
	
}

?>

<?php

use PHPUnit\Framework\TestCase;

/** Base class: fresh Jeedom doubles for every test and helpers to build equipments. */
abstract class EnergyTestCase extends TestCase {

	protected function setUp(): void {
		eqLogic::reset();
		energy::$nowOverride = null;
		// A fixed zone keeps date('Gi', ...) based tests deterministic
		date_default_timezone_set('Europe/Paris');
	}

	/**
	 * Builds and saves an equipment the way the core does (preInsert, postSave create the commands).
	 *
	 * @param string $type electricity|water|gas
	 * @param array $confEnergy the confEnergy rows (category, power, consumption)
	 */
	protected function makeEnergy(string $type, array $confEnergy = array(), array $configuration = array()): energy {
		$energy = new energy();
		$energy->name = 'eq_' . $type;
		$energy->objectName = 'home';
		$energy->setConfiguration('type', $type);
		$energy->setConfiguration('confEnergy', $confEnergy);
		foreach ($configuration as $key => $value) {
			$energy->setConfiguration($key, $value);
		}
		$energy->save();
		return $energy;
	}

	protected function cmdValue(energy $energy, string $logicalId) {
		$cmd = $energy->getCmd(null, $logicalId);
		$this->assertNotNull($cmd, 'Missing command ' . $logicalId);
		return $cmd->execCmd();
	}

	/** Sets the value returned for a #cmd# expression of a confEnergy row. */
	protected function setExpression(string $expression, $value): void {
		jeedom::$expressions[$expression] = $value;
	}

	protected function setConfig(string $key, $value): void {
		config::$values['energy::' . $key] = $value;
	}
}

<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

/**
 * The types of debug traces (performance, consumption anomaly) can be switched on and off one by one.
 * This file only covers the mechanism and what depends on the panel; what depends on the anomaly detection is in
 * Anomaly/EnergyAnomalyLogTypesTest.php, so that removing the detection removes those tests with it.
 */
final class EnergyLogTypesTest extends EnergyTestCase {

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
	}

	private function lines(string $prefix): array {
		return array_values(array_filter(array_map(function ($line) {
			return $line[2];
		}, log::$lines), function ($text) use ($prefix) {
			return strpos($text, $prefix) === 0;
		}));
	}

	private function runPanel(): void {
		$object = new jeeObject(1, 'home');
		$energy = $this->makeEnergy('electricity');
		$energy->cmds = array();
		$energy->addCmdDouble(new GraphCmdDouble('consumptionTotal', array(array('2026-11-01', '5'))));
		$energy->addCmdDouble(new GraphCmdDouble('cost', array(array('2026-11-01', '1'))));
		$object->eqLogics[] = $energy;
		energy::getGraph('2026-11-01 00:00:00', '2026-11-03', 1);
	}

	public function testPerformanceTracesNeedTheDebugLevelAndTheirBox(): void {
		log::$level = 400;
		$this->runPanel();
		$this->assertSame(array(), $this->lines('[perf]'), 'not at the debug level');

		log::$lines = array();
		log::$level = 100;
		$this->runPanel();
		$this->assertNotEmpty($this->lines('[perf]'), 'debug level and the box on by default');

		log::$lines = array();
		$this->setConfig('logPerf', '0');
		$this->runPanel();
		$this->assertSame(array(), $this->lines('[perf]'), 'the box is off');
	}

	public function testAnUncheckedBoxHasNoEffectBelowTheDebugLevel(): void {
		log::$level = 300;
		$this->setConfig('logPerf', '1');
		$this->runPanel();
		$this->assertSame(array(), $this->lines('[perf]'));
	}

	public function testTheBoxOnlyChangesWhatIsWritten(): void {
		$results = array();
		foreach (array('1', '0') as $box) {
			jeeObject::reset();
			eqLogic::reset();
			log::$level = 100;
			$this->setConfig('logPerf', $box);
			$object = new jeeObject(1, 'home');
			$energy = $this->makeEnergy('electricity');
			$energy->cmds = array();
			$energy->addCmdDouble(new GraphCmdDouble('consumptionTotal', array(array('2026-11-01', '5'))));
			$object->eqLogics[] = $energy;
			$results[$box] = energy::getGraph('2026-11-01 00:00:00', '2026-11-03', 1);
		}
		$this->assertSame($results['1'], $results['0']);
	}

	// ---- the class

	public function testTheTypesAreDeclaredInOnePlace(): void {
		$this->assertSame(array('perf' => 'logPerf', 'anomaly' => 'logAnomaly'), energyLogTypes::TYPES);
	}

	public function testAnUnknownTypeIsNotBlocked(): void {
		log::$level = 100;
		$this->assertTrue(energyLogTypes::enabled('something'));
		$this->assertTrue(energyLogTypes::isTypeOn('something'));
	}

	public function testBoxesAreOnByDefaultWhenNothingIsStored(): void {
		$this->assertTrue(energyLogTypes::isTypeOn('perf'));
		$this->assertTrue(energyLogTypes::isTypeOn('anomaly'));
	}

	public function testOnlyAnExplicitZeroSwitchesATypeOff(): void {
		foreach (array('0', 0, false) as $off) {
			$this->setConfig('logPerf', $off);
			$this->assertFalse(energyLogTypes::isTypeOn('perf'), var_export($off, true));
		}
		foreach (array('1', 1, true, '') as $on) {
			$this->setConfig('logPerf', $on);
			$this->assertTrue(energyLogTypes::isTypeOn('perf'), var_export($on, true));
		}
		// a value that was never stored: Jeedom then returns the default given to byKey(), which is 1
		config::$values = array();
		$this->assertTrue(energyLogTypes::isTypeOn('perf'));
	}

	public function testTheLogLevelDecidesFirst(): void {
		log::$level = 200;
		$this->assertFalse(energyLogTypes::enabled('perf'));
		$this->assertFalse(energyLogTypes::enabled('anomaly'));
		log::$level = 100;
		$this->assertTrue(energyLogTypes::enabled('perf'));
		$this->assertTrue(energyLogTypes::enabled('anomaly'));
	}

	// ---- the configuration screen

	public function testEveryTypeIsOnByDefaultInTheDefaultConfiguration(): void {
		$ini = parse_ini_file(__DIR__ . '/../../core/config/energy.config.ini', true);
		foreach (energyLogTypes::TYPES as $type => $setting) {
			$this->assertArrayHasKey($setting, $ini['energy'], $type);
			$this->assertSame('1', $ini['energy'][$setting], $type . ' must be on by default so that nothing changes until a box is unchecked');
		}
	}

	public function testEveryTypeHasItsCheckboxInTheConfigurationScreen(): void {
		$screen = file_get_contents(__DIR__ . '/../../plugin_info/configuration.php');
		foreach (energyLogTypes::TYPES as $type => $setting) {
			$this->assertMatchesRegularExpression('/<input type="checkbox" class="configKey" data-l1key="' . $setting . '"/', $screen, 'no checkbox for the ' . $type . ' type');
		}
	}

	public function testTheScreenOnlyListsTheCheckboxesOfKnownTypes(): void {
		$screen = file_get_contents(__DIR__ . '/../../plugin_info/configuration.php');
		preg_match_all('/data-l1key="(log[A-Za-z]+)"/', $screen, $matches);
		$this->assertEqualsCanonicalizing(array_values(energyLogTypes::TYPES), $matches[1]);
	}
}

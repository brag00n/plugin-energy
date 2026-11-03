<?php

/**
 * The structure of the anomaly detection: rules grouped and described, per-energy handling, and above all
 * the fact that it can be removed or disabled without touching anything else.
 */
final class EnergyAnomalyStructureTest extends EnergyTestCase {

	private const CLASS_DIR = __DIR__ . '/../../../core/class';

	private function mainClassSource(): string {
		return file_get_contents(self::CLASS_DIR . '/energy.class.php');
	}

	private function anomalySource(string $file): string {
		return file_get_contents(self::CLASS_DIR . '/anomaly/' . $file);
	}

	// ---- 1. the rules are grouped and described

	public function testEveryRuleOfTheIndexPointsToAnExistingMethod(): void {
		$header = $this->anomalySource('energyAnomalyRules.class.php');
		preg_match_all('/^\s\*\s+(R\d+)\s+.+?\s+([A-Za-z]+)\(\)/m', $header, $matches, PREG_SET_ORDER);
		$this->assertGreaterThanOrEqual(11, count($matches), 'the index of the rules must list R1 to R11');
		foreach ($matches as $match) {
			$this->assertTrue(method_exists('energyAnomalyRules', $match[2]), $match[1] . ' points to ' . $match[2] . '() which does not exist');
		}
	}

	public function testEveryRuleIsDescribedInTheCodeAndInTheIndex(): void {
		$source = $this->anomalySource('energyAnomalyRules.class.php');
		for ($i = 1; $i <= 11; $i++) {
			$this->assertGreaterThanOrEqual(2, substr_count($source, 'R' . $i . ' '), 'R' . $i . ' must appear in the index and above its method');
		}
	}

	public function testEveryParameterOfTheIndexHasADefaultConstant(): void {
		$source = $this->anomalySource('energyAnomalyRules.class.php');
		foreach (array('WINDOW_DAYS', 'MIN_ACTIVE', 'MARGIN', 'LEARN_STEP', 'REGIME_COUNT', 'REGIME_HOURS') as $constant) {
			$this->assertTrue(defined('energyAnomalyRules::' . $constant), $constant);
		}
	}

	public function testRulesNeverLogNorTouchTheDatabase(): void {
		$source = $this->anomalySource('energyAnomalyRules.class.php');
		$this->assertStringNotContainsString('log::add', $source, 'the rules are pure: the log is energyAnomalyReport');
		$this->assertStringNotContainsString('DB::', $source);
		$this->assertStringNotContainsString('setConfiguration', $source, 'the storage is energyAnomalyProfile');
	}

	public function testOnlyTheReportWritesLogs(): void {
		foreach (glob(self::CLASS_DIR . '/anomaly/*.php') as $file) {
			if (basename($file) === 'energyAnomalyReport.class.php') {
				continue;
			}
			$this->assertStringNotContainsString("log::add", file_get_contents($file), basename($file) . ' must not write logs, only energyAnomalyReport does');
		}
	}

	// ---- 2. it can be removed

	public function testTheMainClassOnlyTalksToTheEntryPoint(): void {
		$source = $this->mainClassSource();
		// the import loop names the parts; outside of it, only energyAnomaly:: may appear
		$withoutImport = preg_replace('#/\* ANOMALY: begin.*?ANOMALY: end \*/#s', '', $source);
		foreach (array('energyAnomalyRules', 'energyAnomalyKind', 'energyAnomalyElectricity', 'energyAnomalyWater', 'energyAnomalyGas', 'energyAnomalyProfile', 'energyAnomalyCron', 'energyAnomalyReport') as $internal) {
			$this->assertStringNotContainsString($internal, $withoutImport, 'energy.class.php must only use energyAnomaly, not ' . $internal);
		}
	}

	public function testTheMainClassHasExactlyTheThreeMarkedCallPoints(): void {
		$source = $this->mainClassSource();
		$this->assertSame(1, substr_count($source, 'ANOMALY (1/3)'));
		$this->assertSame(2, substr_count($source, 'ANOMALY (2/3)'), 'electricity and water/gas');
		$this->assertSame(2, substr_count($source, 'ANOMALY (3/3)'), 'electricity and water/gas');
		$this->assertSame(1, substr_count($source, 'ANOMALY: begin'));
		$this->assertSame(1, substr_count($source, 'ANOMALY: end'));
	}

	public function testRemovingTheDetectionLeavesAValidPlugin(): void {
		// Apply the removal procedure written in energyAnomaly.class.php to a copy of the main class and lint it
		$source = $this->mainClassSource();
		$source = preg_replace('#/\* ANOMALY: begin.*?ANOMALY: end \*/\s*#s', '', $source);
		$source = preg_replace('#^[ \t]*// ANOMALY \(\d/3\)[^\n]*\n[^\n]*\n#m', '', $source);
		$source = preg_replace('#^[^\n]*energyAnomaly::line\([^\n]*\n#m', '', $source);
		$this->assertDoesNotMatchRegularExpression('/anomaly/i', $source, 'nothing of the detection must remain in the main class');
		$file = tempnam(sys_get_temp_dir(), 'energy_removed_');
		file_put_contents($file, $source);
		exec('"' . PHP_BINARY . '" -n -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
		@unlink($file);
		$this->assertSame(0, $code, implode(' ', $output));
	}

	public function testTheDetectionDependsOnTheMainClassOnlyThroughOneMethod(): void {
		$uses = array();
		foreach (glob(self::CLASS_DIR . '/anomaly/*.php') as $file) {
			preg_match_all('/\benergy::([A-Za-z_]+)/', preg_replace('#/\*.*?\*/#s', '', file_get_contents($file)), $m);
			foreach ($m[1] as $method) {
				$uses[$method] = true;
			}
		}
		$this->assertSame(array('integrationMaxGap'), array_keys($uses), 'the detection must only need energy::integrationMaxGap()');
	}

	// ---- 3. it can be disabled

	public function testItIsEnabledByDefault(): void {
		$this->assertTrue(energyAnomaly::isEnabled());
	}

	public function testItCanBeDisabledWithoutRemovingIt(): void {
		$this->setConfig('energyAnomalyEnabled', '0');
		$this->assertFalse(energyAnomaly::isEnabled());
		log::$level = 100;
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '', 'consumption' => '#9#'),
		), array('previous#9#' => 0));
		$now = mktime(12, 0, 0);
		for ($i = 0; $i < 30; $i++) {
			$this->setExpression('#9#', 0.5 * ($i + 1));
			energy::$nowOverride = $now + $i * 900;
			energy::cron15();
		}
		$this->setExpression('#9#', 500);
		energy::$nowOverride = $now + 30 * 900;
		energy::cron15();
		$traces = array_filter(log::$lines, function ($line) {
			return strpos($line[2], '[jump]') === 0 || strpos($line[2], '[cron]') === 0 || strpos($line[2], 'Abnormal consumption') === 0;
		});
		$this->assertSame(array(), $traces, 'disabled: no trace, no warning');
		$this->assertSame('', $energy->getConfiguration('jumpProfile'), 'disabled: nothing is learned');
		$this->assertSame('', config::byKey('cronHistory', 'energy', ''), 'disabled: the cron is not remembered');
		$this->assertEqualsWithDelta(500, $this->cmdValue($energy, 'consumptionHeating'), 1.0, 'disabled: what is credited is unchanged');
	}

	public function testDisabledDetectionCreditsExactlyWhatEnabledDetectionCredits(): void {
		$credited = array();
		foreach (array('1', '0') as $enabled) {
			eqLogic::reset();
			energy::$nowOverride = null;
			$this->setConfig('energyAnomalyEnabled', $enabled);
			$energy = $this->makeEnergy('electricity', array(
				array('category' => 'heating', 'power' => '', 'consumption' => '#9#'),
			), array('previous#9#' => 0));
			$now = mktime(12, 0, 0);
			foreach (array(0.5, 1.0, 1.5, 51.5, 52.0) as $i => $index) {
				$this->setExpression('#9#', $index);
				energy::$nowOverride = $now + $i * 900;
				energy::cron15();
			}
			$credited[$enabled] = array($this->cmdValue($energy, 'consumptionHeating'), $this->cmdValue($energy, 'consumptionTotal'), $this->cmdValue($energy, 'cost'));
		}
		$this->assertEquals($credited['1'], $credited['0'], 'the detection only reports');
	}

	// ---- 4. electricity, water and gas are handled separately

	public function testEachEnergyHasItsOwnClassAndFile(): void {
		$this->assertInstanceOf('energyAnomalyElectricity', energyAnomaly::kindFor('electricity'));
		$this->assertInstanceOf('energyAnomalyWater', energyAnomaly::kindFor('water'));
		$this->assertInstanceOf('energyAnomalyGas', energyAnomaly::kindFor('gas'));
		foreach (array('Electricity', 'Water', 'Gas') as $energy) {
			$this->assertFileExists(self::CLASS_DIR . '/anomaly/energyAnomaly' . $energy . '.class.php');
			$this->assertInstanceOf('energyAnomalyKind', new ('energyAnomaly' . $energy)());
		}
	}

	public function testAnUnknownEnergyIsHandledAsElectricity(): void {
		$this->assertInstanceOf('energyAnomalyElectricity', energyAnomaly::kindFor('hydrogen'));
		$this->assertInstanceOf('energyAnomalyElectricity', energyAnomaly::kindFor(''));
	}

	public function testTheEnergiesDoNotKnowEachOther(): void {
		foreach (array('Electricity' => array('Water', 'Gas'), 'Water' => array('Electricity', 'Gas'), 'Gas' => array('Electricity', 'Water')) as $energy => $others) {
			$source = $this->anomalySource('energyAnomaly' . $energy . '.class.php');
			foreach ($others as $other) {
				$this->assertStringNotContainsString('energyAnomaly' . $other, $source, $energy . ' must not depend on ' . $other);
			}
		}
	}

	public function testTheRulesDoNotKnowAnyEnergy(): void {
		$source = preg_replace('#/\*.*?\*/#s', '', $this->anomalySource('energyAnomalyRules.class.php'));
		foreach (array('energyAnomalyElectricity', 'energyAnomalyWater', 'energyAnomalyGas') as $kind) {
			$this->assertStringNotContainsString($kind, $source, 'the rules are the same for every energy');
		}
	}

	public function testSettingsAreProfileSpecificToAnEnergy(): void {
		$water = energyAnomaly::kindFor('water');
		$electricity = energyAnomaly::kindFor('electricity');
		$this->setConfig('energyJumpWaterMargin', '3');
		$this->assertEquals(3, energyAnomalyRules::param($water, 'Margin', energyAnomalyRules::MARGIN));
		$this->assertEquals(1.5, energyAnomalyRules::param($electricity, 'Margin', energyAnomalyRules::MARGIN), 'the water setting must not leak to electricity');
	}

	public function testAnEnergySettingWinsOverTheGlobalSetting(): void {
		$water = energyAnomaly::kindFor('water');
		$this->setConfig('energyJumpMargin', '2');
		$this->assertEquals(2, energyAnomalyRules::param($water, 'Margin', 1.5), 'global setting');
		$this->setConfig('energyJumpWaterMargin', '4');
		$this->assertEquals(4, energyAnomalyRules::param($water, 'Margin', 1.5), 'energy setting');
		$this->assertEquals(2, energyAnomalyRules::param(energyAnomaly::kindFor('gas'), 'Margin', 1.5), 'the other energies keep the global one');
	}

	public function testAnEnergyCanCarryItsOwnDefault(): void {
		$kind = new class extends energyAnomalyElectricity {
			public function defaults() {
				return array('Margin' => 2.5);
			}
		};
		$this->assertEquals(2.5, energyAnomalyRules::param($kind, 'Margin', 1.5));
		$this->setConfig('energyJumpMargin', '4');
		$this->assertEquals(4, energyAnomalyRules::param($kind, 'Margin', 1.5), 'a setting still wins over the default of the energy');
	}

	public function testInvalidSettingsAreIgnored(): void {
		$kind = energyAnomaly::kindFor('water');
		foreach (array('0', '-1', 'abc', '') as $invalid) {
			$this->setConfig('energyJumpWaterMargin', $invalid);
			$this->setConfig('energyJumpMargin', $invalid);
			$this->assertEquals(1.5, energyAnomalyRules::param($kind, 'Margin', 1.5), var_export($invalid, true));
		}
	}

	public function testWaterAndElectricityAreJudgedWithTheirOwnMarginInTheSameRun(): void {
		$this->setConfig('energyJumpWaterMargin', '100');
		$water = $this->makeEnergy('water', array(array('category' => '', 'power' => '', 'consumption' => '#8#')), array('previous#8#' => 0));
		$electricity = $this->makeEnergy('electricity', array(array('category' => 'heating', 'power' => '', 'consumption' => '#9#')), array('previous#9#' => 0));
		$now = mktime(0, 0, 0, 11, 1, 2026);
		for ($i = 0; $i < 40; $i++) {
			$this->setExpression('#8#', 30 * ($i + 1));
			$this->setExpression('#9#', 0.5 * ($i + 1));
			energy::$nowOverride = $now + $i * 900;
			energy::cron15();
		}
		$this->setExpression('#8#', 30 * 40 + 300);   // 10x the water rate
		$this->setExpression('#9#', 0.5 * 40 + 5);    // 10x the electricity rate
		energy::$nowOverride = $now + 40 * 900;
		energy::cron15();
		$warnings = implode("\n", array_map(function ($l) {
			return $l[2];
		}, array_filter(log::$lines, function ($l) {
			return $l[1] === 'warning' && strpos($l[2], 'Abnormal consumption jump') === 0;
		})));
		$this->assertStringContainsString('eq_electricity', $warnings, 'electricity: 10x is over its margin of 1.5');
		$this->assertStringNotContainsString('eq_water', $warnings, 'water: 10x is under its margin of 100');
	}
}

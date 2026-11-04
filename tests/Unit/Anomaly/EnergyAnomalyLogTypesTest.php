<?php

/** The "consumption anomaly" debug traces can be switched off on their own, the warnings never are. */
final class EnergyAnomalyLogTypesTest extends EnergyTestCase {

	private function lines(string $prefix): array {
		return array_values(array_filter(array_map(function ($line) {
			return $line[2];
		}, log::$lines), function ($text) use ($prefix) {
			return strpos($text, $prefix) === 0;
		}));
	}

	private function warnings(): array {
		return array_values(array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[1] === 'warning';
		})));
	}

	/** Runs `$count` regular cycles of 0.5 kWh each, then optionally a jump. */
	private function runCycles(int $count, float $jumpKwh = 0): energy {
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '', 'consumption' => '#9#'),
		), array('previous#9#' => 0));
		$now = mktime(0, 0, 0, 11, 1, 2026);
		for ($i = 0; $i < $count; $i++) {
			$this->setExpression('#9#', 0.5 * ($i + 1));
			energy::$nowOverride = $now + $i * 900;
			energy::cron15();
		}
		if ($jumpKwh > 0) {
			$this->setExpression('#9#', 0.5 * $count + $jumpKwh);
			energy::$nowOverride = $now + $count * 900;
			energy::cron15();
		}
		return $energy;
	}

	public function testNothingIsWrittenOutsideTheDebugLevelWhateverTheBox(): void {
		log::$level = 400;
		$this->runCycles(3);
		$this->assertSame(array(), $this->lines('[jump]'));
		$this->assertSame(array(), $this->lines('[cron]'));
	}

	public function testTheTracesAreWrittenByDefaultAtTheDebugLevel(): void {
		log::$level = 100;
		$this->runCycles(3);
		$this->assertNotEmpty($this->lines('[jump]'));
		$this->assertNotEmpty($this->lines('[cron]'));
	}

	public function testUncheckingTheAnomalyBoxSilencesTheTracesOnly(): void {
		log::$level = 100;
		$this->setConfig('logAnomaly', '0');
		$this->runCycles(3);
		$this->assertSame(array(), $this->lines('[jump]'));
		$this->assertSame(array(), $this->lines('[cron]'));
	}

	public function testTheAnomalyBoxIsIndependentOfThePerformanceBox(): void {
		log::$level = 100;
		$this->setConfig('logPerf', '0');
		$this->runCycles(3);
		$this->assertNotEmpty($this->lines('[jump]'), 'the performance box off must not silence the anomaly traces');

		log::$lines = array();
		eqLogic::reset();
		$this->setConfig('logPerf', '1');
		$this->setConfig('logAnomaly', '0');
		$this->runCycles(3);
		$this->assertSame(array(), $this->lines('[jump]'), 'the anomaly box off silences them');
	}

	public function testAnUncheckedBoxHasNoEffectBelowTheDebugLevel(): void {
		log::$level = 300;
		$this->setConfig('logAnomaly', '1');
		$this->runCycles(3);
		$this->assertSame(array(), $this->lines('[jump]'));
	}

	public function testTheJumpWarningIsWrittenWhateverTheBoxes(): void {
		log::$level = 300;
		$this->setConfig('logAnomaly', '0');
		$this->setConfig('logPerf', '0');
		$this->runCycles(40, 50);
		$this->assertStringContainsString('Abnormal consumption jump', implode("\n", $this->warnings()), 'a jump is reported with the anomaly box off');
	}

	public function testTheLateCronWarningIsWrittenWhateverTheBoxes(): void {
		log::$level = 300;
		$this->setConfig('logAnomaly', '0');
		energy::$nowOverride = mktime(12, 0, 0);
		energy::cron15();
		energy::$nowOverride = mktime(12, 0, 0) + 25 * 3600;
		energy::cron15();
		$this->assertStringContainsString('cron15 ran late', implode("\n", $this->warnings()));
	}

	public function testTheBoxesNeverChangeWhatIsLearnedNorCredited(): void {
		$results = array();
		foreach (array('1', '0') as $box) {
			eqLogic::reset();
			energy::$nowOverride = null;
			log::$level = 100;
			$this->setConfig('logAnomaly', $box);
			$energy = $this->runCycles(30);
			$results[$box] = array($this->cmdValue($energy, 'consumptionTotal'), $energy->getConfiguration('jumpProfile'));
		}
		$this->assertEquals($results['1'], $results['0'], 'the box only decides what is written in the log');
	}
}

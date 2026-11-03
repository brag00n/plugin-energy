<?php

/** The adaptive jump detection also covers water (L) and gas (kWh), in the unit of the equipment. */
final class EnergyJumpWaterGasTest extends EnergyTestCase {

	private $index = 0;

	private function meter(string $type): energy {
		$this->index = 0;
		return $this->makeEnergy($type, array(
			array('category' => '', 'power' => '', 'consumption' => '#8#'),
		), array('previous#8#' => 0));
	}

	private function cycle(float $quantity, int $at): void {
		$this->index += $quantity;
		$this->setExpression('#8#', $this->index);
		energy::$nowOverride = $at;
		energy::cron15();
	}

	private function learn(float $quantity, int $count, int $start): int {
		$at = $start;
		for ($i = 0; $i < $count; $i++) {
			$this->cycle($quantity, $at);
			$at += 900;
		}
		return $at;
	}

	private function warnings(): array {
		return array_values(array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[1] === 'warning' && strpos($line[2], 'Abnormal consumption jump') === 0;
		})));
	}

	private function debugJump(): string {
		return implode("\n", array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[1] === 'debug' && strpos($line[2], '[jump]') === 0;
		})));
	}

	public function testRateUnits(): void {
		$this->assertSame('W', energy::jumpRateUnit('kWh'));
		$this->assertSame('L/h', energy::jumpRateUnit('L'));
		$this->assertEqualsWithDelta(2000, energy::jumpRate(0.5, 900, 'kWh'), 0.001, '0.5 kWh in 15 min is 2 kW');
		$this->assertEqualsWithDelta(120, energy::jumpRate(30, 900, 'L'), 0.001, '30 L in 15 min is 120 L/h');
	}

	public function testWaterJumpIsDetectedAndReportedInLitres(): void {
		$this->meter('water');
		$end = $this->learn(30, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(3000, $end);
		$warnings = $this->warnings();
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('3000 L credited in 900 s = 12000 L/h', $warnings[0]);
		$this->assertStringContainsString('above the threshold of 180 L/h', $warnings[0]);
		$this->assertStringContainsString('1.5 x the highest normal rate of 120 L/h', $warnings[0]);
		$this->assertStringContainsString('[water, index delta, #8#] 3000 L (index 1800 => 4800)', $warnings[0]);
		$this->assertStringContainsString('the cron intervals are normal', $warnings[0]);
	}

	public function testGasJumpIsDetectedAndReportedInKwh(): void {
		$this->meter('gas');
		$end = $this->learn(2, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(200, $end);
		$warnings = $this->warnings();
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('200 kWh credited in 900 s', $warnings[0]);
		$this->assertStringContainsString('above the threshold of 12000 W', $warnings[0]);
	}

	/** @dataProvider quantities */
	public static function quantities(): array {
		return array(
			'a drip 0.2 L per cycle' => array('water', 0.2),
			'a house 30 L per cycle' => array('water', 30),
			'an industrial process 20000 L per cycle' => array('water', 20000),
			'a small gas boiler' => array('gas', 1),
			'a large gas installation' => array('gas', 500),
		);
	}

	/** @dataProvider quantities */
	public function testNormalCyclesNeverWarnWhateverTheSize(string $type, float $quantity): void {
		$this->meter($type);
		$this->learn($quantity, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->assertSame(array(), $this->warnings());
	}

	/** @dataProvider quantities */
	public function testAJumpIsDetectedWhateverTheSize(string $type, float $quantity): void {
		$this->meter($type);
		$end = $this->learn($quantity, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle($quantity * 6, $end);
		$this->assertCount(1, $this->warnings());
	}

	public function testALeakIsNotJudgedBeforeTheProfileIsEstablished(): void {
		log::$level = 100;
		$this->meter('water');
		$end = $this->learn(30, 5, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(3000, $end);
		$this->assertSame(array(), $this->warnings());
		$this->assertStringContainsString('profile not established yet', $this->debugJump());
	}

	public function testAMeterAtZeroAllWeekIsNotAJumpWhenWaterStartsRunning(): void {
		// A garden tap used only in summer: zero for weeks, then the first watering
		$this->meter('water');
		$at = mktime(0, 0, 0, 10, 1, 2026);
		for ($i = 0; $i < 96 * 20; $i++) {
			energy::$nowOverride = $at;
			$this->setExpression('#8#', 0);
			energy::cron15();
			$at += 900;
		}
		$this->index = 0;
		$this->cycle(200, $at);
		$this->assertSame(array(), $this->warnings());
	}

	public function testDecisionsAreTracedInDebugWithTheUnit(): void {
		log::$level = 100;
		$this->meter('water');
		$end = $this->learn(30, 30, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(3000, $end);
		$text = $this->debugJump();
		$this->assertStringContainsString('30 L over 900 s = 120 L/h', $text);
		$this->assertStringContainsString('reference 120 L/h over 1 day(s), threshold 180 L/h -> normal', $text);
		$this->assertStringContainsString('-> ABNORMAL', $text);
		$this->assertStringContainsString('learned 120 L/h', $text);
	}

	public function testCounterResetIsNotAJump(): void {
		// A replaced meter goes back to a low index: nothing is credited, so there is nothing to judge
		$this->meter('water');
		$end = $this->learn(30, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->index = 0;
		$this->setExpression('#8#', 5);
		energy::$nowOverride = $end;
		energy::cron15();
		$this->assertSame(array(), $this->warnings());
	}

	public function testWaterAndElectricityKeepSeparateProfiles(): void {
		$water = $this->meter('water');
		$this->learn(30, 60, mktime(0, 0, 0, 11, 1, 2026));
		$waterProfile = energy::jumpProfile($water);
		$this->assertEqualsWithDelta(120.0, max($waterProfile['days']), 0.01);
		$electricity = $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '', 'consumption' => '#9#'),
		), array('previous#9#' => 0));
		$this->assertSame(array(), energy::jumpProfile($electricity)['days'], 'a new equipment starts with an empty profile');
	}

	public function testJumpDoesNotChangeWhatIsRecordedForWater(): void {
		$water = $this->meter('water');
		$end = $this->learn(30, 60, mktime(0, 0, 0, 11, 1, 2026));
		$before = $this->cmdValue($water, 'consumptionTotal');
		$this->cycle(3000, $end);
		$this->assertEqualsWithDelta($before + 3000, $this->cmdValue($water, 'consumptionTotal'), 0.001);
	}
}

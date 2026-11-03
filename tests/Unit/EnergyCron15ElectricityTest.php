<?php

final class EnergyCron15ElectricityTest extends EnergyTestCase {

	private function counterRow(string $category, string $expression): array {
		return array('category' => $category, 'power' => '', 'consumption' => $expression);
	}

	public function testConsumptionIsTheDeltaOfTheIndex(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#10#')), array('previous#10#' => 100));
		$this->setExpression('#10#', 102.5);
		energy::cron15();
		$this->assertEqualsWithDelta(2.5, $this->cmdValue($energy, 'consumptionLight'), 0.0001);
		$this->assertEqualsWithDelta(2.5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertSame(102.5, $energy->getConfiguration('previous#10#'));
	}

	public function testConsumptionAccumulatesAcrossCycles(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#10#')), array('previous#10#' => 100));
		$this->setExpression('#10#', 101);
		energy::cron15();
		$this->setExpression('#10#', 103);
		energy::cron15();
		$this->assertEqualsWithDelta(3.0, $this->cmdValue($energy, 'consumptionLight'), 0.0001);
		$this->assertEqualsWithDelta(3.0, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testNoChangeCountsNothing(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#10#')), array('previous#10#' => 100));
		$this->setExpression('#10#', 100);
		energy::cron15();
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionTotal'));
		$this->assertSame(array(), $energy->getCmd(null, 'consumptionTotal')->events);
	}

	public function testConsumptionIsSplitByCategory(): void {
		$energy = $this->makeEnergy('electricity', array(
			$this->counterRow('light', '#1#'),
			$this->counterRow('heating', '#2#'),
			$this->counterRow('multimedia', '#3#'),
		), array('previous#1#' => 0, 'previous#2#' => 0, 'previous#3#' => 0));
		$this->setExpression('#1#', 1);
		$this->setExpression('#2#', 4);
		$this->setExpression('#3#', 0.5);
		energy::cron15();
		$this->assertEqualsWithDelta(1, $this->cmdValue($energy, 'consumptionLight'), 0.0001);
		$this->assertEqualsWithDelta(4, $this->cmdValue($energy, 'consumptionHeating'), 0.0001);
		$this->assertEqualsWithDelta(0.5, $this->cmdValue($energy, 'consumptionMultimedia'), 0.0001);
		$this->assertEqualsWithDelta(5.5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testCounterResetRealignsTheIndexAndCountsNothing(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#10#')), array('previous#10#' => 5000));
		$this->setExpression('#10#', 12);
		energy::cron15();
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionTotal'));
		$this->assertSame(12.0, $energy->getConfiguration('previous#10#'));
		$this->assertNotEmpty(array_filter(log::$lines, function ($line) {
			return strpos($line[2], 'Counter reset detected') === 0;
		}));
	}

	public function testConsumptionResumesAfterACounterReset(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#10#')), array('previous#10#' => 5000));
		$this->setExpression('#10#', 12);
		energy::cron15();
		$this->setExpression('#10#', 15);
		energy::cron15();
		$this->assertEqualsWithDelta(3.0, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testNonNumericReadingIsNotMistakenForAReset(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#10#')), array('previous#10#' => 5000));
		$this->setExpression('#10#', 'unavailable');
		energy::cron15();
		$this->assertSame(5000, $energy->getConfiguration('previous#10#'));
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionTotal'));
	}

	public function testEmptyReadingIsNotMistakenForAReset(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#10#')), array('previous#10#' => 5000));
		$this->setExpression('#10#', '');
		energy::cron15();
		$this->assertSame(5000, $energy->getConfiguration('previous#10#'));
	}

	public function testPowerIsSummedAcrossRows(): void {
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'light', 'power' => '#1#', 'consumption' => '#11#'),
			array('category' => 'heating', 'power' => '#2#', 'consumption' => '#12#'),
		), array('previous#1##11#' => 0, 'previous#2##12#' => 0));
		$this->setExpression('#1#', 100);
		$this->setExpression('#2#', 250);
		$this->setExpression('#11#', 0);
		$this->setExpression('#12#', 0);
		energy::cron15();
		$this->assertEqualsWithDelta(350, $this->cmdValue($energy, 'power'), 0.0001);
	}

	public function testPowerWithoutCounterIsIntegratedOverTime(): void {
		$now = mktime(12, 0, 0);
		energy::$nowOverride = $now;
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'light', 'power' => '#5#', 'consumption' => ''),
		), array('lastChangeTime#5#' => $now - 900, 'lastValue#5#' => 2000));
		$this->setExpression('#5#', 2000);
		energy::cron15();
		// One normal cycle: 2000 W for 15 min = 0.5 kWh (a gap above the 30 min cap is credited only up to the cap)
		$this->assertEqualsWithDelta(0.5, $this->cmdValue($energy, 'consumptionLight'), 0.01);
		$this->assertEqualsWithDelta(0.5, $this->cmdValue($energy, 'consumptionTotal'), 0.01);
		$this->assertSame(2000.0, $energy->getConfiguration('lastValue#5#'));
	}

	private function integratedHeater(int $secondsSinceLastCycle, float $lastPower, float $nowPower, array $configuration = array()): energy {
		$now = mktime(12, 0, 0);
		energy::$nowOverride = $now;
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '#5#', 'consumption' => ''),
		), array_merge(array('lastChangeTime#5#' => $now - $secondsSinceLastCycle, 'lastValue#5#' => $lastPower), $configuration));
		$this->setExpression('#5#', $nowPower);
		return $energy;
	}

	public function testLongCronStopDoesNotCreditTheWholeGap(): void {
		// The production case: a 2000 W radiator last seen "on", cron15 silent for 25 h.
		// It used to credit 2000 W x 25 h = 50 kWh in one cycle; at most the 30 minute cap is credited now.
		$energy = $this->integratedHeater(25 * 3600, 2000, 2000);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'consumptionHeating'), 0.0001, '2000 W x 30 min');
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testLongCronStopDoesNotInflateTheCost(): void {
		$this->setConfig('rate', '0.2228');
		$energy = $this->integratedHeater(25 * 3600, 2000, 2000);
		energy::cron15();
		// 1 kWh x 0.2228, not 50 kWh x 0.2228 = 11.14 EUR
		$this->assertEqualsWithDelta(0.2228, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testNormalCycleIsStillFullyIntegrated(): void {
		// 15 minutes at 2000 W = 0.5 kWh, untouched by the cap
		$energy = $this->integratedHeater(900, 2000, 2000);
		energy::cron15();
		$this->assertEqualsWithDelta(0.5, $this->cmdValue($energy, 'consumptionHeating'), 0.0001);
		$this->assertSame(array(), array_filter(log::$lines, function ($line) {
			return $line[1] === 'warning';
		}));
	}

	public function testOneMissedCycleIsStillCredited(): void {
		// 30 minutes = exactly one missed cycle: tolerated, fully credited
		$energy = $this->integratedHeater(1800, 2000, 2000);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'consumptionHeating'), 0.0001);
	}

	public function testGapJustOverTheCapIsCapped(): void {
		$energy = $this->integratedHeater(1801, 2000, 2000);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'consumptionHeating'), 0.0001);
	}

	public function testCappedGapIsLoggedAsAWarning(): void {
		$this->integratedHeater(25 * 3600, 2000, 2000);
		energy::cron15();
		$warnings = array_values(array_filter(log::$lines, function ($line) {
			return $line[1] === 'warning';
		}));
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('Power integration gap capped', $warnings[0][2]);
		$this->assertStringContainsString('90000 s since the last cycle', $warnings[0][2]);
		$this->assertStringContainsString('only 1800 s counted at 2000 W', $warnings[0][2]);
	}

	public function testStateIsRealignedAfterACappedGap(): void {
		$energy = $this->integratedHeater(25 * 3600, 2000, 0);
		energy::cron15();
		$this->assertSame(mktime(12, 0, 0), $energy->getConfiguration('lastChangeTime#5#'));
		$this->assertSame(0.0, $energy->getConfiguration('lastValue#5#'));
		// the next normal cycle starts from the new reference: the radiator is now off, nothing more is credited
		$before = $this->cmdValue($energy, 'consumptionHeating');
		energy::$nowOverride = mktime(12, 15, 0);
		energy::cron15();
		$this->assertEqualsWithDelta($before, $this->cmdValue($energy, 'consumptionHeating'), 0.0001);
	}

	public function testUnknownPreviousCycleCreditsNothing(): void {
		// First run of a line, or its state was lost: no reference time, so nothing can be integrated
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '#5#', 'consumption' => ''),
		), array('lastValue#5#' => 2000));
		energy::$nowOverride = mktime(12, 0, 0);
		$this->setExpression('#5#', 2000);
		energy::cron15();
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionHeating'));
	}

	public function testClockGoingBackwardsCreditsNothing(): void {
		$energy = $this->integratedHeater(-600, 2000, 2000);
		energy::cron15();
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionHeating'));
	}

	public function testIntegrationMaxGapCanBeConfigured(): void {
		$this->setConfig('energyMaxIntegrationGap', '3600');
		$energy = $this->integratedHeater(25 * 3600, 2000, 2000);
		energy::cron15();
		$this->assertEqualsWithDelta(2.0, $this->cmdValue($energy, 'consumptionHeating'), 0.0001, '2000 W x 1 h');
	}

	public function testInvalidConfiguredMaxGapFallsBackToTheDefault(): void {
		foreach (array('0', '-5', 'abc', '') as $invalid) {
			$this->setConfig('energyMaxIntegrationGap', $invalid);
			$this->assertSame(1800, energy::integrationMaxGap(), 'value: ' . var_export($invalid, true));
		}
	}

	public function testIntegrationElapsedTable(): void {
		$cases = array(
			'normal cycle' => array(1000, 1900, 900, false),
			'exactly the cap' => array(1000, 2800, 1800, false),
			'over the cap' => array(1000, 5000, 1800, true),
			'no previous cycle (empty)' => array('', 5000, 0, false),
			'no previous cycle (null)' => array(null, 5000, 0, false),
			'no previous cycle (zero)' => array(0, 5000, 0, false),
			'same second' => array(1000, 1000, 0, false),
			'clock backwards' => array(2000, 1000, 0, false),
		);
		foreach ($cases as $name => [$last, $now, $seconds, $capped]) {
			$result = energy::integrationElapsed($last, $now);
			$this->assertSame($seconds, $result['seconds'], $name . ' (seconds)');
			$this->assertSame($capped, $result['capped'], $name . ' (capped)');
		}
	}

	public function testTotalCounterOnlyFeedsTheTotalAndHpHcCommands(): void {
		$energy = $this->makeEnergy('electricity', array(
			$this->counterRow('hp', '#1#'),
			$this->counterRow('hc', '#2#'),
		), array('totalCounter' => 1, 'previous#1#' => 0, 'previous#2#' => 0));
		$this->setExpression('#1#', 3);
		$this->setExpression('#2#', 2);
		energy::cron15();
		$this->assertEqualsWithDelta(3, $this->cmdValue($energy, 'consumptionHp'), 0.0001);
		$this->assertEqualsWithDelta(2, $this->cmdValue($energy, 'consumptionHc'), 0.0001);
		$this->assertEqualsWithDelta(5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionLight'));
	}

	public function testEquipmentsOfOtherTypesDoNotInterfere(): void {
		$electricity = $this->makeEnergy('electricity', array($this->counterRow('light', '#1#')), array('previous#1#' => 0));
		$water = $this->makeEnergy('water', array(array('category' => '', 'power' => '', 'consumption' => '#2#')), array('previous#2#' => 0));
		$this->setExpression('#1#', 2);
		$this->setExpression('#2#', 30);
		energy::cron15();
		$this->assertEqualsWithDelta(2, $this->cmdValue($electricity, 'consumptionTotal'), 0.0001);
		$this->assertEqualsWithDelta(30, $this->cmdValue($water, 'consumptionTotal'), 0.0001);
	}

	public function testDisabledEquipmentsAreIgnored(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#1#')), array('previous#1#' => 0));
		$energy->isEnable = 0;
		$this->setExpression('#1#', 9);
		energy::cron15();
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionTotal'));
	}

	public function testCollectDateIsTheCycleDate(): void {
		$energy = $this->makeEnergy('electricity', array($this->counterRow('light', '#1#')), array('previous#1#' => 0));
		$this->setExpression('#1#', 1);
		energy::cron15();
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $energy->getCmd(null, 'consumptionTotal')->collectDate);
	}
}

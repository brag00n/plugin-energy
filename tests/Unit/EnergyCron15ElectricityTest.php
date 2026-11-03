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
		), array('lastChangeTime#5#' => $now - 3600, 'lastValue#5#' => 2000));
		$this->setExpression('#5#', 2000);
		energy::cron15();
		// 2000 W for 1 h = 2 kWh
		$this->assertEqualsWithDelta(2.0, $this->cmdValue($energy, 'consumptionLight'), 0.01);
		$this->assertEqualsWithDelta(2.0, $this->cmdValue($energy, 'consumptionTotal'), 0.01);
		$this->assertSame(2000.0, $energy->getConfiguration('lastValue#5#'));
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

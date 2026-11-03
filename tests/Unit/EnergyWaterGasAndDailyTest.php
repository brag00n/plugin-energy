<?php

final class EnergyWaterGasAndDailyTest extends EnergyTestCase {

	private function meterRow(string $expression): array {
		return array('category' => '', 'power' => '', 'consumption' => $expression);
	}

	public function testWaterConsumptionIsTheIndexDelta(): void {
		$energy = $this->makeEnergy('water', array($this->meterRow('#2#')), array('previous#2#' => 1000));
		$this->setExpression('#2#', 1042);
		energy::cron15();
		$this->assertEqualsWithDelta(42, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertSame(1042.0, $energy->getConfiguration('previous#2#'));
	}

	public function testSeveralMetersAreSummed(): void {
		$energy = $this->makeEnergy('water', array($this->meterRow('#2#'), $this->meterRow('#3#')), array('previous#2#' => 0, 'previous#3#' => 0));
		$this->setExpression('#2#', 10);
		$this->setExpression('#3#', 5);
		energy::cron15();
		$this->assertEqualsWithDelta(15, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testWaterCounterResetRealignsTheIndex(): void {
		$energy = $this->makeEnergy('water', array($this->meterRow('#2#')), array('previous#2#' => 90000));
		$this->setExpression('#2#', 3);
		energy::cron15();
		$this->assertSame(3.0, $energy->getConfiguration('previous#2#'));
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionTotal'));
		$this->setExpression('#2#', 8);
		energy::cron15();
		$this->assertEqualsWithDelta(5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testWaterNonNumericReadingKeepsTheIndex(): void {
		$energy = $this->makeEnergy('water', array($this->meterRow('#2#')), array('previous#2#' => 90000));
		$this->setExpression('#2#', 'n/a');
		energy::cron15();
		$this->assertSame(90000, $energy->getConfiguration('previous#2#'));
	}

	public function testMissingPreviousIndexStartsFromZero(): void {
		$energy = $this->makeEnergy('gas', array($this->meterRow('#4#')));
		$this->setExpression('#4#', 7);
		energy::cron15();
		$this->assertEqualsWithDelta(7, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testDailyResetZeroesEveryConsumptionAndCost(): void {
		$energy = $this->makeEnergy('electricity', array(array('category' => 'light', 'power' => '', 'consumption' => '#1#')), array('previous#1#' => 0));
		$this->setExpression('#1#', 5);
		$this->setConfig('rate', '0.2');
		energy::cron15();
		$this->assertGreaterThan(0, $this->cmdValue($energy, 'consumptionTotal'));
		energy::resetDailyValues();
		foreach (array('cost', 'consumptionTotal', 'consumptionLight', 'consumptionOther', 'consumptionMultimedia', 'consumptionAutomatism', 'consumptionElectrical', 'consumptionHeating', 'consumptionHp', 'consumptionHc') as $logicalId) {
			$this->assertSame(0, $this->cmdValue($energy, $logicalId), $logicalId);
		}
	}

	public function testDailyResetDatesTheValuesAtMidnight(): void {
		$energy = $this->makeEnergy('electricity');
		energy::resetDailyValues();
		$this->assertSame(date('Y-m-d 00:00:00'), $energy->getCmd(null, 'consumptionTotal')->collectDate);
	}

	public function testDailyResetDoesNotTouchThePowerNorTheIndexes(): void {
		$energy = $this->makeEnergy('electricity', array(), array('previous#1#' => 42));
		$energy->getCmd(null, 'power')->event(300);
		energy::resetDailyValues();
		$this->assertSame(300, $this->cmdValue($energy, 'power'));
		$this->assertSame(42, $energy->getConfiguration('previous#1#'));
	}

	public function testDailyResetSkipsDisabledEquipments(): void {
		$energy = $this->makeEnergy('electricity');
		$energy->getCmd(null, 'consumptionTotal')->event(8);
		$energy->isEnable = 0;
		energy::resetDailyValues();
		$this->assertSame(8, $this->cmdValue($energy, 'consumptionTotal'));
	}

	public function testDailyResetOnWaterOnlyTouchesExistingCommands(): void {
		$energy = $this->makeEnergy('water');
		$energy->getCmd(null, 'consumptionTotal')->event(8);
		energy::resetDailyValues();
		$this->assertSame(0, $this->cmdValue($energy, 'consumptionTotal'));
		$this->assertNull($energy->getCmd(null, 'consumptionLight'));
	}
}

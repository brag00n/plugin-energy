<?php

final class EnergyCostTest extends EnergyTestCase {

	private function lightEquipment(float $previous = 0): energy {
		return $this->makeEnergy('electricity', array(
			array('category' => 'light', 'power' => '', 'consumption' => '#1#'),
		), array('previous#1#' => $previous));
	}

	public function testFixedRate(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.20');
		$energy = $this->lightEquipment();
		$this->setExpression('#1#', 10);
		energy::cron15();
		$this->assertEqualsWithDelta(2.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testFixedRateAcceptsADecimalComma(): void {
		$this->setConfig('rate', '0,25');
		$energy = $this->lightEquipment();
		$this->setExpression('#1#', 4);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testCostAccumulatesAcrossCycles(): void {
		$this->setConfig('rate', '0.10');
		$energy = $this->lightEquipment();
		$this->setExpression('#1#', 10);
		energy::cron15();
		$this->setExpression('#1#', 30);
		energy::cron15();
		$this->assertEqualsWithDelta(3.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testNoConsumptionMeansNoCostEvent(): void {
		$this->setConfig('rate', '0.10');
		$energy = $this->lightEquipment(10);
		$this->setExpression('#1#', 10);
		energy::cron15();
		$this->assertSame(array(), $energy->getCmd(null, 'cost')->events);
	}

	public function testHpHcCountersAreBilledWithTheirOwnRates(): void {
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.20');
		$this->setConfig('rateHc', '0.10');
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'hp', 'power' => '', 'consumption' => '#1#'),
			array('category' => 'hc', 'power' => '', 'consumption' => '#2#'),
		), array('totalCounter' => 1, 'previous#1#' => 0, 'previous#2#' => 0));
		$this->setExpression('#1#', 10);
		$this->setExpression('#2#', 10);
		energy::cron15();
		// 10 * 0.20 + 10 * 0.10
		$this->assertEqualsWithDelta(3.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testOnlyTheHcCounterMovingUsesTheHpHcRates(): void {
		// Regression: the condition used to test hp twice and ignored hc
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.20');
		$this->setConfig('rateHc', '0.10');
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'hp', 'power' => '', 'consumption' => '#1#'),
			array('category' => 'hc', 'power' => '', 'consumption' => '#2#'),
		), array('totalCounter' => 1, 'previous#1#' => 5, 'previous#2#' => 0));
		$this->setExpression('#1#', 5);
		$this->setExpression('#2#', 10);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testVariableRateWithoutCountersUsesTheOffPeakRangeAtNight(): void {
		$this->freezeHour(23, 30);
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.20');
		$this->setConfig('rateHc', '0.10');
		$this->setConfig('startHc', '22:00');
		$this->setConfig('endHc', '06:00');
		$energy = $this->lightEquipment();
		$this->setExpression('#1#', 10);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testVariableRateWithoutCountersUsesThePeakRateInTheDay(): void {
		$this->freezeHour(14, 0);
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.20');
		$this->setConfig('rateHc', '0.10');
		$this->setConfig('startHc', '22:00');
		$this->setConfig('endHc', '06:00');
		$energy = $this->lightEquipment();
		$this->setExpression('#1#', 10);
		energy::cron15();
		$this->assertEqualsWithDelta(2.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testSecondOffPeakRangeIsUsed(): void {
		$this->freezeHour(13, 30);
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.20');
		$this->setConfig('rateHc', '0.10');
		$this->setConfig('startHc', '22:00');
		$this->setConfig('endHc', '06:00');
		$this->setConfig('startHc2', '13:00');
		$this->setConfig('endHc2', '15:00');
		$energy = $this->lightEquipment();
		$this->setExpression('#1#', 10);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testWaterCostUsesTheWaterRate(): void {
		$this->setConfig('rateWater', '0.004');
		$energy = $this->makeEnergy('water', array(array('category' => '', 'power' => '', 'consumption' => '#2#')), array('previous#2#' => 0));
		$this->setExpression('#2#', 1000);
		energy::cron15();
		$this->assertEqualsWithDelta(4.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testGasCostUsesTheGasRate(): void {
		$this->setConfig('rateGas', '0.08');
		$energy = $this->makeEnergy('gas', array(array('category' => '', 'power' => '', 'consumption' => '#3#')), array('previous#3#' => 100));
		$this->setExpression('#3#', 150);
		energy::cron15();
		$this->assertEqualsWithDelta(4.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	/** Pins the clock cron15 reads (see energy::$nowOverride) to a given time today. */
	private function freezeHour(int $hour, int $minute): void {
		energy::$nowOverride = mktime($hour, $minute, 0);
	}

	protected function tearDown(): void {
		energy::$nowOverride = null;
	}
}

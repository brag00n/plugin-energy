<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

/** The cost bars are split into peak hours (HP), off-peak hours (HC) and unknown (no HP/HC information). */
final class EnergyCostDetailTest extends EnergyTestCase {

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
	}

	private function ms(string $date): float {
		return floatval(strtotime($date . ' UTC')) * 1000;
	}

	private function build(array $cmds, array $conf = array(), array $configuration = array()): energy {
		$energy = $this->makeEnergy('electricity', $conf, $configuration);
		$energy->cmds = array();
		foreach ($cmds as $cmd) {
			$energy->addCmdDouble($cmd);
		}
		return $energy;
	}

	private function graph(energy ...$energies): array {
		$object = new jeeObject(1, 'home');
		foreach ($energies as $energy) {
			$object->eqLogics[] = $energy;
		}
		return energy::getGraph('2026-11-01 00:00:00', '2026-11-30', 1);
	}

	private function lines(): array {
		return array(array('category' => 'hp', 'power' => '', 'consumption' => '#1#'), array('category' => 'hc', 'power' => '', 'consumption' => '#2#'));
	}

	public function testFixedTariffSplitsTheDayIntoHpHcAndUnknown(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$meter = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-04', '3'))),
			new GraphCmdDouble('consumptionHp', array(array('2026-11-04', '10'))),
			new GraphCmdDouble('consumptionHc', array(array('2026-11-04', '5'))),
		), $this->lines());
		$detail = $this->graph($meter)['costDetail'];
		$this->assertEqualsWithDelta(2.0, $detail['hp'][0][1], 0.0001);
		$this->assertEqualsWithDelta(1.0, $detail['hc'][0][1], 0.0001);
		$this->assertSame(array(), $detail['unknown'], 'everything is explained by the indexes');
	}

	public function testWhatTheIndexesDoNotExplainIsUnknown(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$meter = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-04', '5'))),
			new GraphCmdDouble('consumptionHp', array(array('2026-11-04', '10'))),
			new GraphCmdDouble('consumptionHc', array()),
		), $this->lines());
		$detail = $this->graph($meter)['costDetail'];
		$this->assertEqualsWithDelta(2.0, $detail['hp'][0][1], 0.0001);
		$this->assertSame(array(), $detail['hc']);
		$this->assertEqualsWithDelta(3.0, $detail['unknown'][0][1], 0.0001);
	}

	public function testVariableTariffUsesTheHpAndHcRates(): void {
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0,25');
		$this->setConfig('rateHc', '0.15');
		$meter = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-04', '3.25'))),
			new GraphCmdDouble('consumptionHp', array(array('2026-11-04', '10'))),
			new GraphCmdDouble('consumptionHc', array(array('2026-11-04', '5'))),
		), $this->lines());
		$detail = $this->graph($meter)['costDetail'];
		$this->assertEqualsWithDelta(2.5, $detail['hp'][0][1], 0.0001);
		$this->assertEqualsWithDelta(0.75, $detail['hc'][0][1], 0.0001);
		$this->assertSame(array(), $detail['unknown']);
	}

	public function testTheSplitNeverMakesTheBarLargerThanTheCost(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '1');
		$meter = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-04', '3'))),
			new GraphCmdDouble('consumptionHp', array(array('2026-11-04', '4'))),
			new GraphCmdDouble('consumptionHc', array(array('2026-11-04', '2'))),
		), $this->lines());
		$detail = $this->graph($meter)['costDetail'];
		$sum = $detail['hp'][0][1] + $detail['hc'][0][1] + (isset($detail['unknown'][0]) ? $detail['unknown'][0][1] : 0);
		$this->assertEqualsWithDelta(3.0, $sum, 0.0001);
		$this->assertEqualsWithDelta(2.0, $detail['hp'][0][1], 0.0001, 'scaled down in proportion');
	}

	public function testAnEquipmentWithoutHpHcLinesIsAllUnknown(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$heater = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-04', '2'))),
			new GraphCmdDouble('consumptionHeating', array(array('2026-11-04', '10'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$detail = $this->graph($heater)['costDetail'];
		$this->assertSame(array(), $detail['hp']);
		$this->assertSame(array(), $detail['hc']);
		$this->assertEqualsWithDelta(2.0, $detail['unknown'][0][1], 0.0001);
	}

	public function testTheTotalCounterDrivesTheSplitAndTheHeatersAreIgnored(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$counter = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-04', '15'))),
			new GraphCmdDouble('cost', array(array('2026-11-04', '3'))),
			new GraphCmdDouble('consumptionHp', array(array('2026-11-04', '15'))),
			new GraphCmdDouble('consumptionHc', array()),
		), $this->lines(), array('totalCounter' => 1));
		$heater = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-04', '9'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$result = $this->graph($counter, $heater);
		$this->assertSame(3.0, $result['total']['cost']);
		$this->assertEqualsWithDelta(3.0, $result['costDetail']['hp'][0][1], 0.0001);
		$this->assertSame(array(), $result['costDetail']['unknown']);
	}

	public function testDaysAreKeptSeparateAndSorted(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '1');
		$meter = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-03', '2'), array('2026-11-04', '4'))),
			new GraphCmdDouble('consumptionHp', array(array('2026-11-04', '1'))),
			new GraphCmdDouble('consumptionHc', array()),
		), $this->lines());
		$detail = $this->graph($meter)['costDetail'];
		$this->assertSame(array(array($this->ms('2026-11-04'), 1.0)), $detail['hp']);
		$this->assertEqualsWithDelta(2.0, $detail['unknown'][0][1], 0.0001);
		$this->assertEqualsWithDelta(3.0, $detail['unknown'][1][1], 0.0001);
		$this->assertSame($this->ms('2026-11-03'), $detail['unknown'][0][0]);
	}

	public function testNothingRecordedGivesThreeEmptySeries(): void {
		$result = $this->graph();
		$this->assertSame(array('hp' => array(), 'hc' => array(), 'unknown' => array(), 'subscription' => array()), $result['costDetail']);
	}

	public function testADayTheCounterDidNotRecordKeepsTheSumOfTheEquipments(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$counter = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-04', '1.5'))),
			new GraphCmdDouble('cost', array(array('2026-11-04', '0.3'))),
			new GraphCmdDouble('consumptionHp', array(array('2026-11-04', '1.5'))),
			new GraphCmdDouble('consumptionHc', array()),
		), $this->lines(), array('totalCounter' => 1));
		$heater = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-03', '50'), array('2026-11-04', '40'))),
			new GraphCmdDouble('cost', array(array('2026-11-03', '10'), array('2026-11-04', '8'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$result = $this->graph($counter, $heater);
		// 3 November: the counter recorded nothing, the equipments' estimate stays; 4 November: the counter wins
		$this->assertSame(array(array($this->ms('2026-11-03'), 50.0), array($this->ms('2026-11-04'), 1.5)), $result['totalConsumption']);
		$this->assertSame(51.5, $result['total']['consumption']);
		$this->assertSame(array(array($this->ms('2026-11-03'), 10.0), array($this->ms('2026-11-04'), 0.3)), $result['cost']);
		$this->assertSame(10.3, $result['total']['cost']);
		$detail = $result['costDetail'];
		$this->assertEqualsWithDelta(10.0, $detail['unknown'][0][1], 0.0001);
		$this->assertEqualsWithDelta(0.3, $detail['hp'][0][1], 0.0001);
		$this->assertCount(1, $detail['unknown'], 'the counter day is fully explained by its HP index');
	}

	public function testTheDaysGivenByTheCounterAreListedAsMeasured(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$counter = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-04', '1.5'))),
			new GraphCmdDouble('cost', array(array('2026-11-04', '0.3'))),
		), $this->lines(), array('totalCounter' => 1));
		$heater = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-03', '50'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$result = $this->graph($counter, $heater);
		$this->assertEquals(array($this->ms('2026-11-04')), $result['measuredDays']);
	}

	public function testWithoutACounterNoDayIsMeasured(): void {
		$heater = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-03', '50'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$this->assertSame(array(), $this->graph($heater)['measuredDays']);
	}

	public function testTheDailySubscriptionIsAddedToEachDayUpToToday(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$this->setConfig('subscriptionDaily', '0,66');
		energy::$nowOverride = strtotime('2026-11-04 12:00:00');
		$meter = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-03', '2'))),
		), $this->lines());
		$object = new jeeObject(1, 'home');
		$object->eqLogics[] = $meter;
		$result = energy::getGraph('2026-11-02 00:00:00', '2026-11-10', 1);
		$this->assertCount(3, $result['costDetail']['subscription'], '2, 3 and 4 November: the days to come are not due');
		$this->assertEqualsWithDelta(2.66, $result['cost'][1][1], 0.0001, '2 + 0.66 on the day that had a cost');
		$this->assertEqualsWithDelta(0.66, $result['cost'][0][1], 0.0001, 'a day without cost still has its subscription');
		$this->assertSame(round(2 + 3 * 0.66, 2), $result['total']['cost']);
	}

	public function testWithoutSubscriptionNothingChanges(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$meter = $this->build(array(
			new GraphCmdDouble('cost', array(array('2026-11-03', '2'))),
		), $this->lines());
		$result = $this->graph($meter);
		$this->assertSame(array(), $result['costDetail']['subscription']);
		$this->assertSame(2.0, $result['total']['cost']);
	}

	public function testTheSubscriptionIsOnlyForElectricity(): void {
		$this->setConfig('subscriptionDaily', '0.66');
		energy::$nowOverride = strtotime('2026-11-04 12:00:00');
		$object = new jeeObject(1, 'home');
		$result = energy::getGraph('2026-11-02 00:00:00', '2026-11-04', 1, 'water');
		$this->assertSame(0.0, $result['total']['cost']);
	}

	public function testTheEstimatedAndMeasuredTotalsAreTwoSeparateCurves(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$counter = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-04', '20'))),
			new GraphCmdDouble('cost', array(array('2026-11-04', '4'))),
		), $this->lines(), array('totalCounter' => 1));
		$heater = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-04', '30'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$result = $this->graph($counter, $heater);
		$this->assertEqualsWithDelta(30.0, $result['estimatedConsumption'][0][1], 0.0001, 'sum of the equipments');
		$this->assertEqualsWithDelta(20.0, $result['measuredConsumption'][0][1], 0.0001, 'total counter');
		$this->assertEqualsWithDelta(20.0, $result['totalConsumption'][0][1], 0.0001, 'the combined curve is unchanged');
	}

	public function testWithoutACounterTheEstimateIsTheOnlyTotal(): void {
		$heater = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-03', '50'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$result = $this->graph($heater);
		$this->assertCount(1, $result['estimatedConsumption']);
		$this->assertSame(array(), $result['measuredConsumption']);
	}

	public function testTheEstimateKeepsTheDaysTheCounterDidNotRecord(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.2');
		$counter = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-04', '1.5'))),
			new GraphCmdDouble('cost', array(array('2026-11-04', '0.3'))),
		), $this->lines(), array('totalCounter' => 1));
		$heater = $this->build(array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-03', '50'), array('2026-11-04', '40'))),
		), array(array('category' => 'heating', 'power' => '#5#', 'consumption' => '')));
		$result = $this->graph($counter, $heater);
		$this->assertCount(2, $result['estimatedConsumption'], 'both days are estimated by the equipments');
		$this->assertCount(1, $result['measuredConsumption'], 'only 4 November is measured');
	}

	public function testTheTotalPowerIsInWholeWatts(): void {
		$a = $this->build(array(new GraphCmdDouble('power', array(), 1866.6666666667)), array());
		$b = $this->build(array(new GraphCmdDouble('power', array(), 50.4)), array());
		$result = $this->graph($a, $b);
		$this->assertSame(1917, $result['total']['power']);
	}
}

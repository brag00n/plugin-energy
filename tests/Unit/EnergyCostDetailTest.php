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
		$this->assertSame(array('hp' => array(), 'hc' => array(), 'unknown' => array()), $result['costDetail']);
	}
}

<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

final class EnergyGraphTest extends EnergyTestCase {

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
	}

	private function ms(string $date): float {
		return floatval(strtotime($date . ' UTC')) * 1000;
	}

	/** Replaces the commands of an equipment by doubles carrying canned data. */
	private function withCommands(energy $energy, array $cmds): energy {
		$energy->cmds = array();
		foreach ($cmds as $cmd) {
			$energy->addCmdDouble($cmd);
		}
		return $energy;
	}

	private function placeIn(jeeObject $object, energy ...$energies): void {
		foreach ($energies as $energy) {
			$object->eqLogics[] = $energy;
		}
	}

	public function testUnknownObjectThrows(): void {
		$this->expectException(Exception::class);
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 999);
	}

	public function testEmptyObjectReturnsAnEmptyStructure(): void {
		$object = new jeeObject(1, 'home');
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(), $result['totalConsumption']);
		$this->assertSame(array(), $result['cost']);
		$this->assertSame(0.0, $result['total']['consumption']);
		$this->assertSame(0.0, $result['total']['cost']);
		$this->assertSame('kWh', $result['consumptionUnite']);
		$this->assertArrayHasKey('home', $result['object']);
	}

	public function testWaterUsesLitres(): void {
		new jeeObject(1, 'home');
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1, 'water');
		$this->assertSame('L', $result['consumptionUnite']);
	}

	public function testConsumptionIsAggregatedByCategoryAndObject(): void {
		$object = new jeeObject(1, 'home');
		$energy = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-01-01', '5'), array('2026-01-02', '3'))),
			new GraphCmdDouble('consumptionLight', array(array('2026-01-01', '2'), array('2026-01-02', '1'))),
			new GraphCmdDouble('consumptionHeating', array(array('2026-01-01', '3'), array('2026-01-02', '2'))),
		));
		$this->placeIn($object, $energy);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(array($this->ms('2026-01-01'), 2.0), array($this->ms('2026-01-02'), 1.0)), $result['category']['light']);
		$this->assertSame(array(array($this->ms('2026-01-01'), 3.0), array($this->ms('2026-01-02'), 2.0)), $result['category']['heating']);
		$this->assertSame(array(array($this->ms('2026-01-01'), 5.0), array($this->ms('2026-01-02'), 3.0)), $result['object']['home']);
		$this->assertSame(array(array($this->ms('2026-01-01'), 5.0), array($this->ms('2026-01-02'), 3.0)), $result['totalConsumption']);
		// Total consumption is the sum of the categories (2+1 light, 3+2 heating), not of the counter
		$this->assertSame(8.0, $result['total']['consumption']);
	}

	public function testCostIsSummedPerDayAndInTotal(): void {
		$object = new jeeObject(1, 'home');
		$a = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('cost', array(array('2026-01-01', '1.5'))),
		));
		$b = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('cost', array(array('2026-01-01', '0.5'), array('2026-01-02', '2'))),
		));
		$this->placeIn($object, $a, $b);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(array($this->ms('2026-01-01'), 2.0), array($this->ms('2026-01-02'), 2.0)), $result['cost']);
		$this->assertSame(4.0, $result['total']['cost']);
	}

	public function testZeroAndNonNumericValuesAreIgnored(): void {
		$object = new jeeObject(1, 'home');
		$energy = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionLight', array(array('2026-01-01', '0'), array('2026-01-02', 'abc'), array('2026-01-03', '4'))),
		));
		$this->placeIn($object, $energy);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(array($this->ms('2026-01-03'), 4.0)), $result['category']['light']);
	}

	public function testOtherEnergyTypesAreFilteredOut(): void {
		$object = new jeeObject(1, 'home');
		$water = $this->withCommands($this->makeEnergy('water'), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-01-01', '100'))),
		));
		$this->placeIn($object, $water);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1, 'electricity');
		$this->assertSame(array(), $result['totalConsumption']);
	}

	public function testEquipmentsWithoutReadRightAreSkipped(): void {
		$object = new jeeObject(1, 'home');
		$hidden = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionLight', array(array('2026-01-01', '9'))),
		));
		$hidden->rights = false;
		$this->placeIn($object, $hidden);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(), $result['category']['light']);
		$this->assertSame(0.0, $result['total']['consumption']);
	}

	public function testChildObjectsAreIncluded(): void {
		$parent = new jeeObject(1, 'home');
		$child = new jeeObject(2, 'garage');
		$parent->childs = array($child);
		$this->placeIn($child, $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionLight', array(array('2026-01-01', '2'))),
		)));
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(array($this->ms('2026-01-01'), 2.0)), $result['object']['garage']);
		$this->assertArrayHasKey('home', $result['object']);
	}

	public function testPowerHistoryAndCurrentPower(): void {
		$object = new jeeObject(1, 'home');
		$power = new GraphCmdDouble('power', array(), 450);
		$power->powerRows = array(
			new HistoryValueDouble('2026-01-01 10:00:00', 100),
			new HistoryValueDouble('2026-01-01 10:15:00', 200),
		);
		$this->placeIn($object, $this->withCommands($this->makeEnergy('electricity'), array($power)));
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(450, $result['total']['power']);
		$this->assertCount(2, $result['power']['home']);
		$this->assertSame(100.0, $result['power']['home'][0][1]);
	}

	public function testPowerOfSeveralEquipmentsIsSummedAtTheSameInstant(): void {
		$object = new jeeObject(1, 'home');
		$cmds = array();
		foreach (array(100, 50) as $watts) {
			$power = new GraphCmdDouble('power', array(), $watts);
			$power->powerRows = array(new HistoryValueDouble('2026-01-01 10:00:00', $watts));
			$cmds[] = $this->withCommands($this->makeEnergy('electricity'), array($power));
		}
		$this->placeIn($object, ...$cmds);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertCount(1, $result['power']['home']);
		$this->assertSame(150.0, $result['power']['home'][0][1]);
		$this->assertSame(150, $result['total']['power']);
	}

	public function testTotalCounterEquipmentDrivesTheTotals(): void {
		$object = new jeeObject(1, 'home');
		$counter = $this->withCommands($this->makeEnergy('electricity', array(), array('totalCounter' => 1)), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-01-01', '10'), array('2026-01-02', '12'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '2'), array('2026-01-02', '2.4'))),
		));
		$this->placeIn($object, $counter);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(22.0, $result['total']['consumption']);
		$this->assertSame(4.4, $result['total']['cost']);
		$this->assertCount(2, $result['totalConsumption']);
		$this->assertCount(2, $result['cost']);
		// A total counter has no category breakdown
		$this->assertSame(array(), $result['category']['light']);
	}

	public function testEmptyTotalCounterKeepsTheCostAndConsumptionOfTheEquipments(): void {
		// Regression: a "total counter" whose source is down reads 0 everywhere. It used to wipe the
		// cost and consumption curves and force the header totals to 0 for the whole object.
		$object = new jeeObject(1, 'home');
		$counter = $this->withCommands($this->makeEnergy('electricity', array(), array('totalCounter' => 1)), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-01-01', '0'), array('2026-01-02', '0'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '0'), array('2026-01-02', '0'))),
		));
		$heater = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-01-01', '10'))),
			new GraphCmdDouble('consumptionHeating', array(array('2026-01-01', '10'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '2.5'))),
		));
		$this->placeIn($object, $counter, $heater);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(array($this->ms('2026-01-01'), 2.5)), $result['cost']);
		$this->assertSame(2.5, $result['total']['cost']);
		$this->assertSame(10.0, $result['total']['consumption']);
		$this->assertSame(array(array($this->ms('2026-01-01'), 10.0)), $result['totalConsumption']);
	}

	public function testTotalCounterWithoutAnyHistoryKeepsTheEquipmentsTotals(): void {
		$object = new jeeObject(1, 'home');
		$counter = $this->withCommands($this->makeEnergy('electricity', array(), array('totalCounter' => 1)), array(
			new GraphCmdDouble('consumptionTotal', array()),
			new GraphCmdDouble('cost', array()),
		));
		$heater = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionHeating', array(array('2026-01-01', '4'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '1'))),
		));
		$this->placeIn($object, $counter, $heater);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(1.0, $result['total']['cost']);
		$this->assertSame(4.0, $result['total']['consumption']);
	}

	public function testTotalCounterWithOnlyCostDoesNotBlankTheConsumption(): void {
		$object = new jeeObject(1, 'home');
		$counter = $this->withCommands($this->makeEnergy('electricity', array(), array('totalCounter' => 1)), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-01-01', '0'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '3'))),
		));
		$heater = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionHeating', array(array('2026-01-01', '6'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '1'))),
		));
		$this->placeIn($object, $counter, $heater);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		// The counter recorded a cost, so it drives the cost; it recorded no consumption, so the equipments' sum stays
		$this->assertSame(3.0, $result['total']['cost']);
		$this->assertSame(6.0, $result['total']['consumption']);
	}

	public function testTotalsAreRoundedToTwoDecimals(): void {
		$object = new jeeObject(1, 'home');
		$energy = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionLight', array(array('2026-01-01', '1.23456'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '0.98765'))),
		));
		$this->placeIn($object, $energy);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(1.23, $result['total']['consumption']);
		$this->assertSame(0.99, $result['total']['cost']);
	}

	public function testSeriesAreSortedByDate(): void {
		$object = new jeeObject(1, 'home');
		$energy = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionLight', array(array('2026-01-03', '1'), array('2026-01-01', '1'), array('2026-01-02', '1'))),
		));
		$this->placeIn($object, $energy);
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$dates = array_column($result['category']['light'], 0);
		$sorted = $dates;
		sort($sorted);
		$this->assertSame($sorted, $dates);
	}

	public function testTotalConsumptionIsSortedByDateAcrossEquipments(): void {
		// Regression: totalConsumption is built equipment after equipment, so a day that only a later
		// equipment recorded came out after the following days. The charts need ascending dates.
		$object = new jeeObject(1, 'home');
		$first = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-01', '2.63'), array('2026-11-03', '21'))),
		));
		$second = $this->withCommands($this->makeEnergy('electricity'), array(
			new GraphCmdDouble('consumptionTotal', array(array('2026-11-02', '52.5'), array('2026-11-03', '15'))),
		));
		$this->placeIn($object, $first, $second);
		$result = energy::getGraph('2026-11-01 00:00:00', '2026-11-03', 1);
		$dates = array_column($result['totalConsumption'], 0);
		$sorted = $dates;
		sort($sorted);
		$this->assertSame($sorted, $dates, 'totalConsumption must be in ascending date order');
		$this->assertSame(array(
			array($this->ms('2026-11-01'), 2.63),
			array($this->ms('2026-11-02'), 52.5),
			array($this->ms('2026-11-03'), 36.0),
		), $result['totalConsumption']);
	}

	public function testGetGraphSignatureHasNoOptionalBeforeRequired(): void {
		$method = new ReflectionMethod('energy', 'getGraph');
		$seenOptional = false;
		foreach ($method->getParameters() as $parameter) {
			if ($parameter->isOptional()) {
				$seenOptional = true;
			} else {
				$this->assertFalse($seenOptional, 'Required parameter after an optional one: $' . $parameter->getName());
			}
		}
	}
}

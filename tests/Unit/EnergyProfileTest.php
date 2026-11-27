<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

final class EnergyProfileTest extends EnergyTestCase {

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
	}

	private function ms(string $date): float {
		return floatval(strtotime($date . ' UTC')) * 1000;
	}

	/** The total counter and one equipment, with the rows given for each. */
	private function house(array $counter, array $heater, array $catchup = array()): array {
		$object = new jeeObject(1, 'home');
		$total = $this->makeEnergy('electricity', array(), array('totalCounter' => 1));
		$total->cmds = array();
		$total->addCmdDouble(new GraphCmdDouble('consumptionTotal', $counter));
		$total->addCmdDouble(new GraphCmdDouble('cost', array_map(function ($row) {
			return array($row[0], $row[1] * 0.2);
		}, $counter)));
		$total->addCmdDouble(new GraphCmdDouble('consumptionCatchup', $catchup));
		$equipment = $this->makeEnergy('electricity', array(array('category' => 'heating', 'power' => '1000', 'consumption' => '')));
		$equipment->cmds = array();
		$equipment->addCmdDouble(new GraphCmdDouble('consumptionTotal', $heater));
		$equipment->addCmdDouble(new GraphCmdDouble('consumptionHeating', $heater));
		$equipment->addCmdDouble(new GraphCmdDouble('cost', array_map(function ($row) {
			return array($row[0], $row[1] * 0.2);
		}, $heater)));
		$object->eqLogics[] = $total;
		$object->eqLogics[] = $equipment;
		return array($total, $equipment);
	}

	/** A second equipment on top of house(): its rows are the cumulative value of every day. */
	private function addEquipment(array $rows): energy {
		$equipment = $this->makeEnergy('electricity', array(array('category' => 'electrical', 'power' => '50', 'consumption' => '')));
		$equipment->cmds = array();
		$equipment->addCmdDouble(new GraphCmdDouble('consumptionTotal', $rows));
		$equipment->addCmdDouble(new GraphCmdDouble('consumptionElectrical', $rows));
		$equipment->addCmdDouble(new GraphCmdDouble('cost', array_map(function ($row) {
			return array($row[0], $row[1] * 0.2);
		}, $rows)));
		jeeObject::byId(1)->eqLogics[] = $equipment;
		return $equipment;
	}

	private function ranges(array $ranges): void {
		config::$values['energy::profileRanges'] = json_encode($ranges);
	}

	private function profile(array $cold, array $hot = array(), array $coldMonths = array(11, 12, 1, 2, 3, 4), array $fixed = array()): void {
		config::$values['energy::profileFixed'] = json_encode($fixed);
		config::$values['energy::profileSeasons'] = json_encode(array('cold' => $coldMonths));
		config::$values['energy::profile'] = json_encode(array('cold' => $cold, 'hot' => $hot));
	}

	public function testTheSeasonOfADateFollowsTheColdMonths(): void {
		$this->assertSame('cold', energyProfile::seasonOf('2026-01-15'));
		$this->assertSame('cold', energyProfile::seasonOf('2025-11-01'));
		$this->assertSame('hot', energyProfile::seasonOf('2025-05-01'));
		$this->assertSame('hot', energyProfile::seasonOf('2025-10-31 12:00:00'));
		$this->profile(array(), array(), array(6, 7));
		$this->assertSame('cold', energyProfile::seasonOf('2025-06-10'));
		$this->assertSame('hot', energyProfile::seasonOf('2025-01-10'));
	}

	public function testWithoutAProfileThePluginBehavesAsBefore(): void {
		$this->assertFalse(energyProfile::configured());
		$this->assertNull(energyProfile::context('2025-01-01', '2025-12-31', 'electricity'));
	}

	public function testOnlyElectricityHasAProfile(): void {
		$this->profile(array('1' => 50));
		$this->assertNull(energyProfile::context('2025-01-01', '2025-12-31', 'water'));
	}

	public function testTheSharesKeepTheRemainderAsUnmeasured(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 50.0)), array(array('2025-11-11', 5.0)));
		$other = $this->addEquipment(array());
		$a = (string) $heater->getId();
		$b = (string) $other->getId();
		$this->profile(array($a => 30, $b => 20));
		$shares = energyProfile::shares('cold');
		$this->assertEqualsWithDelta(50, $shares['unmeasured'], 0.001);
		$this->assertEqualsWithDelta(30, $shares[$a], 0.001);
	}

	public function testSharesAboveOneHundredAreScaledDown(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 50.0)), array(array('2025-11-11', 5.0)));
		$other = $this->addEquipment(array());
		$a = (string) $heater->getId();
		$b = (string) $other->getId();
		$this->profile(array($a => 90, $b => 30));
		$shares = energyProfile::shares('cold');
		$this->assertEqualsWithDelta(0, $shares['unmeasured'], 0.001);
		$this->assertEqualsWithDelta(75, $shares[$a], 0.001);
		$this->assertEqualsWithDelta(100, $shares[$a] + $shares[$b], 0.001);
	}

	public function testTheProfileFollowsTheIdAndNotTheNameOfAnEquipment(): void {
		list($total, $heater) = $this->house(
			array(array('2025-11-11', 50.0)),
			array(array('2025-11-11', 4861.5))
		);
		$this->profile(array((string) $heater->getId() => 40));
		$before = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		// the equipment is renamed: the same days are replaced by the same share, nothing is added, nothing is lost
		$heater->name = 'Radiateur salon (renamed)';
		$after = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		$this->assertSame($before['category']['heating'], $after['category']['heating']);
		$this->assertSame($before['profileDays'], $after['profileDays']);
		$this->assertCount(1, energyProfile::profile()['cold']);
		$this->assertEquals(array((string) $heater->getId()), array_keys(energyProfile::profile()['cold']), 'array keys of digits are integers in PHP');
		// saving again after the rename does not duplicate the equipment
		energyProfile::save(array(11, 12, 1), array('cold' => array((string) $heater->getId() => '40')), '100', true);
		$this->assertCount(1, energyProfile::profile()['cold']);
		$this->assertCount(1, energyProfile::equipments() ? array_filter(energyProfile::equipments(), function ($e) use ($heater) { return $e['id'] == $heater->getId(); }) : array());
	}

	public function testAnEquipmentThatNoLongerExistsDoesNotWeighOnTheProfile(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 50.0)), array(array('2025-11-11', 5.0)));
		$this->profile(array((string) $heater->getId() => 30, '99999' => 50), array(), array(11), array('88888' => 45));
		$this->assertEquals(array((string) $heater->getId()), array_keys(energyProfile::profile()['cold']), 'array keys of digits are integers in PHP');
		$this->assertSame(array(), energyProfile::fixed());
		$this->assertEqualsWithDelta(70, energyProfile::shares('cold')['unmeasured'], 0.001);
	}

	public function testADayAboveTheThresholdIsAberrantAndTheOthersAreKept(): void {
		$series = array('2025-11-10' => 20.0, '2025-11-11' => 4861.5, '2025-11-12' => 30.0);
		$marks = energyProfile::classify($series, array(), 100, true);
		$this->assertSame(array('2025-11-11' => 'aberrant'), $marks);
	}

	public function testAnImportedDayWithNoValueIsMissing(): void {
		$marks = energyProfile::classify(array('2025-03-02' => 12.0), array('2025-03-01' => true, '2025-03-02' => true), 100, true);
		$this->assertSame(array('2025-03-01' => 'missing'), $marks);
	}

	public function testAnImportedDayEqualToTheDayBeforeIsFrozen(): void {
		$series = array('2025-03-01' => 25.62, '2025-03-02' => 25.62, '2025-03-03' => 25.62, '2025-03-04' => 30.0);
		$catchup = array('2025-03-02' => true, '2025-03-03' => true, '2025-03-04' => true);
		$marks = energyProfile::classify($series, $catchup, 100, true);
		$this->assertSame(array('2025-03-02' => 'frozen', '2025-03-03' => 'frozen'), $marks);
	}

	public function testDaysMeasuredByThePluginAreNeverFrozenOrMissing(): void {
		$series = array('2026-03-01' => 25.62, '2026-03-02' => 25.62);
		$this->assertSame(array(), energyProfile::classify($series, array(), 100, true));
	}

	public function testTheImportedDaysAreKeptWhenTheOptionIsOff(): void {
		$series = array('2025-03-01' => 25.62, '2025-03-02' => 25.62);
		$marks = energyProfile::classify($series, array('2025-03-02' => true, '2025-03-05' => true), 100, false);
		$this->assertSame(array(), $marks);
	}

	public function testAnAberrantDayIsReplacedByTheShareOfTheCounter(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-11-11', 50.0), array('2025-11-12', 40.0)),
			array(array('2025-11-11', 4861.5), array('2025-11-12', 12.0))
		);
		$this->profile(array((string) $equipment->getId() => 40));
		$result = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		// 11/11 replaced: 40 % of 50 kWh ; 12/11 measured: 12 kWh kept
		$days = array();
		foreach ($result['category']['heating'] as $point) {
			$days[$point[0]] = $point[1];
		}
		$this->assertEqualsWithDelta(20.0, $days[$this->ms('2025-11-11')], 0.001);
		$this->assertEqualsWithDelta(12.0, $days[$this->ms('2025-11-12')], 0.001);
		$this->assertSame(array($this->ms('2025-11-11')), $result['profileDays']);
	}

	public function testTheCostOfAReplacedDayFollowsTheShareOfTheCounterCost(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-11-11', 50.0)),
			array(array('2025-11-11', 4861.5))
		);
		$this->profile(array((string) $equipment->getId() => 40));
		$context = energyProfile::context('2025-11-01', '2025-11-30', 'electricity');
		$adjusted = energyProfile::adjust($equipment, $equipment->getData('2025-11-01', '2025-11-30'), $context);
		$this->assertEqualsWithDelta(4.0, $adjusted['data']['cost'][0][1], 0.001, '40 % of the 10 euros of the counter');
		$this->assertSame(array('2025-11-11' => 'aberrant'), $adjusted['days']);
	}

	public function testTheSeasonOfTheDayPicksTheShare(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-11-11', 50.0), array('2025-06-11', 50.0)),
			array(array('2025-11-11', 4861.5), array('2025-06-11', 4861.5))
		);
		$id = (string) $equipment->getId();
		$this->profile(array($id => 40), array($id => 10));
		$context = energyProfile::context('2025-01-01', '2025-12-31', 'electricity');
		$adjusted = energyProfile::adjust($equipment, $equipment->getData('2025-01-01', '2025-12-31'), $context);
		$values = array();
		foreach ($adjusted['data']['consumptionTotal'] as $row) {
			$values[$row[0]] = $row[1];
		}
		$this->assertEqualsWithDelta(20.0, $values['2025-11-11'], 0.001);
		$this->assertEqualsWithDelta(5.0, $values['2025-06-11'], 0.001);
	}

	public function testAnEquipmentWithoutAShareLosesItsAberrantDays(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-11-11', 50.0)),
			array(array('2025-11-11', 4861.5))
		);
		// another equipment holds the profile: this one has no share, its absurd value must not reach the graph
		$other = $this->addEquipment(array());
		$this->profile(array((string) $other->getId() => 40));
		$result = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		$this->assertSame(array(), $result['category']['heating']);
		$this->assertSame(array($this->ms('2025-11-11')), $result['profileDays']);
	}

	public function testTheTotalCounterIsNeverAdjusted(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-11-11', 500.0)),
			array(array('2025-11-11', 20.0))
		);
		$this->profile(array((string) $equipment->getId() => 40));
		$result = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		$this->assertEqualsWithDelta(500.0, $result['totalConsumption'][0][1], 0.001);
		$this->assertSame(array(), $result['profileDays']);
	}

	public function testTheProfileSumsToTheCounterOnAReplacedDay(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-11-11', 60.0)),
			array(array('2025-11-11', 4861.5))
		);
		$this->profile(array((string) $equipment->getId() => 100));
		$result = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		$this->assertEqualsWithDelta(60.0, $result['totalConsumption'][0][1], 0.001);
	}

	public function testAnalysisKeepsOnlyTheHealthyDays(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-02-01', 50.0), array('2025-02-02', 50.0), array('2025-02-03', 50.0)),
			array(array('2025-02-01', 20.0), array('2025-02-02', 4000.0), array('2025-02-03', 30.0))
		);
		$result = energyProfile::analyze('2025-02-01', '2025-02-03');
		$this->assertSame(2, $result['days']);
		$this->assertSame(array('2025-02-02' => 'aberrant'), $result['dropped']);
		$this->assertEqualsWithDelta(50.0, $result['shares'][(string) $equipment->getId()], 0.01, '(20 + 30) / (50 + 50)');
		$this->assertEqualsWithDelta(50.0, $result['unmeasured'], 0.01);
	}

	public function testAFixedEquipmentIsTheSameEveryDayWhateverTheCounterSays(): void {
		list($total, $heater) = $this->house(
			array(array('2025-11-11', 50.0), array('2025-11-12', 50.0)),
			array(array('2025-11-11', 4861.5), array('2025-11-12', 20.0))
		);
		$fridge = $this->addEquipment(array(array('2025-11-11', 0.0)));
		// 45 W all day = 1.08 kWh ; the heater takes 40 % of what is left (50 - 1.08)
		$this->profile(array((string) $heater->getId() => 40), array(), array(11, 12, 1), array((string) $fridge->getId() => 45));
		$context = energyProfile::context('2025-11-01', '2025-11-30', 'electricity');
		$this->assertEqualsWithDelta(1.08, $context['fixedTotal'], 0.001);
		$adjusted = energyProfile::adjust($heater, $heater->getData('2025-11-01', '2025-11-30'), $context);
		$values = array();
		foreach ($adjusted['data']['consumptionTotal'] as $row) {
			$values[$row[0]] = $row[1];
		}
		$this->assertEqualsWithDelta(0.4 * (50 - 1.08), $values['2025-11-11'], 0.001);
		$this->assertEqualsWithDelta(20.0, $values['2025-11-12'], 0.001, 'a measured day is kept');
	}

	public function testAFixedEquipmentTakesItsPowerOnAnImportedDayEvenWithNoValue(): void {
		list($total, $heater) = $this->house(array(array('2025-03-01', 50.0)), array(array('2025-03-01', 12.0)), array(array('2025-03-01', 1)));
		$fridge = $this->addEquipment(array());
		$this->profile(array((string) $heater->getId() => 40), array(), array(11, 12, 1, 2, 3), array((string) $fridge->getId() => 26));
		$context = energyProfile::context('2025-03-01', '2025-03-31', 'electricity');
		$adjusted = energyProfile::adjust($fridge, $fridge->getData('2025-03-01', '2025-03-31'), $context);
		$this->assertSame(array('2025-03-01' => 'missing'), $adjusted['days']);
		$this->assertEqualsWithDelta(0.624, $adjusted['data']['consumptionTotal'][0][1], 0.001, '26 W x 24 h');
	}

	public function testAFixedEquipmentIsNeverFrozen(): void {
		$series = array('2025-03-01' => 1.08, '2025-03-02' => 1.08, '2025-03-03' => 1.08);
		$this->assertSame(array(), energyProfile::classify($series, null, 100, true, true));
		$this->assertCount(2, energyProfile::classify($series, null, 100, true, false));
		$marks = energyProfile::classify($series, array('2025-03-02' => true, '2025-03-03' => true), 100, true, true);
		$this->assertSame(array(), $marks);
	}

	public function testAFixedEquipmentNeverTakesMoreThanTheCounter(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 0.5)), array(array('2025-11-11', 4000.0)));
		$fridge = $this->addEquipment(array(array('2025-11-11', 4000.0)));
		$this->profile(array(), array(), array(11), array((string) $fridge->getId() => 45));
		$context = energyProfile::context('2025-11-01', '2025-11-30', 'electricity');
		$adjusted = energyProfile::adjust($fridge, $fridge->getData('2025-11-01', '2025-11-30'), $context);
		$this->assertEqualsWithDelta(0.5, $adjusted['data']['consumptionTotal'][0][1], 0.001);
	}

	public function testTheAnalysisDetectsTheFixedEquipmentAndItsPower(): void {
		$days = array('2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20');
		list($total, $heater) = $this->house(
			array_map(function ($d) { return array($d, 40.0); }, $days),
			array(array('2026-11-16', 10.0), array('2026-11-17', 14.0), array('2026-11-18', 6.0), array('2026-11-19', 12.0), array('2026-11-20', 8.0))
		);
		$fridge = $this->addEquipment(array_map(function ($d) { return array($d, 1.08); }, $days));
		$result = energyProfile::analyze('2026-11-16', '2026-11-20');
		$this->assertSame(5, $result['days']);
		$this->assertEqualsWithDelta(45.0, $result['fixed'][(string) $fridge->getId()], 0.1, '1.08 kWh / 24 h = 45 W');
		$this->assertArrayNotHasKey((string) $heater->getId(), $result['fixed']);
		// the heater shares what the counter records beyond the fridge: 50 kWh of 200 - 5.4
		$this->assertEqualsWithDelta(50 * 100 / (200 - 5.4), $result['shares'][(string) $heater->getId()], 0.05);
		$this->assertArrayNotHasKey((string) $fridge->getId(), $result['shares']);
		$this->assertEqualsWithDelta(5.4, $result['fixedKwh'], 0.01);
	}

	public function testAVariableEquipmentIsNotTakenForAFixedOne(): void {
		$days = array('2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19');
		list($total, $heater) = $this->house(
			array_map(function ($d) { return array($d, 40.0); }, $days),
			array(array('2026-11-16', 3.0), array('2026-11-17', 9.0), array('2026-11-18', 1.0), array('2026-11-19', 14.0))
		);
		$this->assertSame(array(), energyProfile::analyze('2026-11-16', '2026-11-19')['fixed']);
	}

	public function testAnEquipmentThatConsumedTooFewDaysIsNotDetectedAsFixed(): void {
		$days = array('2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22');
		list($total, $heater) = $this->house(array_map(function ($d) { return array($d, 40.0); }, $days), array(array('2026-11-15', 5.0)));
		$fridge = $this->addEquipment(array(array('2026-11-21', 1.08), array('2026-11-22', 1.08), array('2026-11-20', 1.08)));
		$this->assertSame(array(), energyProfile::analyze('2026-11-15', '2026-11-22')['fixed'], 'three constant days out of eight: not enough');
	}

	public function testSavingKeepsTheFixedPowerAndDropsTheSharesOfAFixedEquipment(): void {
		list($total, $heater) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		$fridge = $this->addEquipment(array());
		$h = (string) $heater->getId();
		$f = (string) $fridge->getId();
		energyProfile::save(array(1), array('cold' => array($h => '60', $f => '30')), '100', true, array($f => '26,5'));
		$this->assertEqualsWithDelta(26.5, energyProfile::fixed()[$f], 0.001);
		$this->assertArrayNotHasKey($f, energyProfile::profile()['cold'], 'a fixed equipment has no share');
		$this->assertEqualsWithDelta(60, energyProfile::profile()['cold'][$h], 0.001);
		$this->assertTrue(energyProfile::configured());
	}

	public function testSavingRefusesAnInvalidFixedPower(): void {
		list($total, $heater) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		$this->expectException(Exception::class);
		energyProfile::save(array(1), array(), '100', true, array((string) $heater->getId() => '-5'));
	}

	public function testOnlyFixedEquipmentsIsAProfile(): void {
		list($total, $heater) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		$this->assertFalse(energyProfile::configured());
		$this->profile(array(), array(), array(11), array((string) $heater->getId() => 45));
		$this->assertTrue(energyProfile::configured());
	}

	public function testADayOfATypedRangeIsReplacedWhateverTheEquipmentMeasured(): void {
		list($total, $heater) = $this->house(
			array(array('2025-11-10', 50.0), array('2025-11-11', 50.0), array('2025-11-12', 50.0)),
			array(array('2025-11-10', 20.0), array('2025-11-11', 20.0), array('2025-11-12', 20.0))
		);
		$this->profile(array((string) $heater->getId() => 40));
		$this->ranges(array(array('from' => '2025-11-11', 'to' => '2025-11-12')));
		$result = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		$days = array();
		foreach ($result['category']['heating'] as $point) {
			$days[$point[0]] = $point[1];
		}
		$this->assertEqualsWithDelta(20.0, $days[$this->ms('2025-11-10')], 0.001, 'outside the range the measure is kept');
		$this->assertEqualsWithDelta(20.0, $days[$this->ms('2025-11-11')], 0.001, '40 % of 50 kWh');
		$this->assertEqualsWithDelta(20.0, $days[$this->ms('2025-11-12')], 0.001);
		$this->assertSame(array($this->ms('2025-11-11'), $this->ms('2025-11-12')), $result['profileDays']);
	}

	public function testATypedRangeReallyChangesTheValueOfADay(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 50.0)), array(array('2025-11-11', 3.0)));
		$this->profile(array((string) $heater->getId() => 40));
		$this->ranges(array(array('from' => '2025-11-11', 'to' => '2025-11-11')));
		$context = energyProfile::context('2025-11-01', '2025-11-30', 'electricity');
		$adjusted = energyProfile::adjust($heater, $heater->getData('2025-11-01', '2025-11-30'), $context);
		$this->assertSame(array('2025-11-11' => 'manual'), $adjusted['days']);
		$this->assertEqualsWithDelta(20.0, $adjusted['data']['consumptionTotal'][0][1], 0.001, '3 kWh measured replaced by 40 % of 50');
	}

	public function testATypedRangeGivesTheFixedEquipmentsTheirPower(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 50.0)), array(array('2025-11-11', 3.0)));
		$fridge = $this->addEquipment(array(array('2025-11-11', 9.0)));
		$this->profile(array((string) $heater->getId() => 40), array(), array(11), array((string) $fridge->getId() => 45));
		$this->ranges(array(array('from' => '2025-11-11', 'to' => '2025-11-11')));
		$context = energyProfile::context('2025-11-01', '2025-11-30', 'electricity');
		$adjusted = energyProfile::adjust($fridge, $fridge->getData('2025-11-01', '2025-11-30'), $context);
		$this->assertEqualsWithDelta(1.08, $adjusted['data']['consumptionTotal'][0][1], 0.001, '45 W x 24 h, not the 9 kWh measured');
	}

	public function testADayOfARangeWhereTheCounterRecordedNothingKeepsItsMeasures(): void {
		list($total, $heater) = $this->house(array(array('2025-11-10', 50.0)), array(array('2025-11-10', 20.0), array('2025-11-11', 7.0)));
		$this->profile(array((string) $heater->getId() => 40));
		$this->ranges(array(array('from' => '2025-11-11', 'to' => '2025-11-11')));
		$result = energy::getGraph('2025-11-01 00:00:00', '2025-11-30', 1);
		$days = array();
		foreach ($result['category']['heating'] as $point) {
			$days[$point[0]] = $point[1];
		}
		$this->assertEqualsWithDelta(7.0, $days[$this->ms('2025-11-11')], 0.001, 'nothing to share: the measure stays');
		$this->assertSame(array(), $result['profileDays']);
	}

	public function testOnlyTheDaysOfTheGraphPeriodAreTaken(): void {
		$this->profile(array());
		$this->ranges(array(array('from' => '2025-10-25', 'to' => '2025-11-05')));
		$this->assertSame(array('2025-11-01', '2025-11-02', '2025-11-03', '2025-11-04', '2025-11-05'), array_keys(energyProfile::manualDays('2025-11-01 00:00:00', '2025-11-30')));
	}

	public function testRangesAreSavedSortedAndValidated(): void {
		list($total, $heater) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		energyProfile::save(array(1), array(), '100', true, array(), array(
			array('from' => '2025-11-11', 'to' => '2025-11-12'),
			array('from' => '', 'to' => ''),
			array('from' => '2025-02-05', 'to' => ''),
		));
		$this->assertSame(array(array('from' => '2025-02-05', 'to' => '2025-02-05'), array('from' => '2025-11-11', 'to' => '2025-11-12')), energyProfile::ranges());
	}

	public function testARangeThatEndsBeforeItStartsIsRefused(): void {
		list($total, $heater) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		$this->expectException(Exception::class);
		energyProfile::save(array(1), array(), '100', true, array(), array(array('from' => '2025-11-12', 'to' => '2025-11-11')));
	}

	public function testARangeAloneIsEnoughToActivateTheProfile(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 50.0)), array(array('2025-11-11', 3.0)));
		$this->ranges(array(array('from' => '2025-11-11', 'to' => '2025-11-11')));
		$this->assertTrue(energyProfile::configured());
	}

	public function testTheListOfTheDaysToRecalculateCountsTheTypedOnes(): void {
		list($total, $heater) = $this->house(array(array('2025-11-11', 50.0), array('2025-11-12', 50.0)), array(array('2025-11-11', 3.0), array('2025-11-12', 4.0)));
		$this->ranges(array(array('from' => '2025-11-11', 'to' => '2025-11-12')));
		$list = energyProfile::daysToRecalc('2025-11-01', '2025-11-30');
		$this->assertSame(2, $list['byReason']['manual']);
		$this->assertSame(2, $list['total']);
		$this->assertSame(2, $list['equipments'][0]['manual']);
	}

	public function testAnalysisRefusesAnInvalidOrTooLongRange(): void {
		$this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		$this->expectException(Exception::class);
		energyProfile::analyze('2025-02-10', '2025-02-01');
	}

	public function testAnalysisNeedsATotalCounter(): void {
		new jeeObject(1, 'home');
		$this->expectException(Exception::class);
		energyProfile::analyze('2025-02-01', '2025-02-03');
	}

	public function testTheDaysToRecalculateAreListedPerEquipment(): void {
		list($total, $equipment) = $this->house(
			array(array('2025-11-11', 50.0), array('2025-11-12', 50.0)),
			array(array('2025-11-11', 4861.5)),
			array(array('2025-11-12', 1))
		);
		$list = energyProfile::daysToRecalc('2025-11-01', '2025-11-30');
		$this->assertSame(2, $list['total']);
		$this->assertSame(1, $list['byReason']['aberrant']);
		$this->assertSame(1, $list['byReason']['missing']);
		$this->assertSame(array('2025-11-11'), $list['equipments'][0]['aberrant']);
	}

	public function testSavingValidatesTheValues(): void {
		list($total, $equipment) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		$id = (string) $equipment->getId();
		energyProfile::save(array(11, 12, 1), array('cold' => array($id => '60'), 'hot' => array($id => '10,5')), '120', true);
		$this->assertEqualsWithDelta(60, energyProfile::profile()['cold'][$id], 0.001);
		$this->assertEqualsWithDelta(10.5, energyProfile::profile()['hot'][$id], 0.001);
		$this->assertSame(array(1, 11, 12), energyProfile::coldMonths());
		$this->assertEqualsWithDelta(120, energyProfile::threshold(), 0.001);
	}

	public function testSavingRefusesASeasonAboveOneHundredPercent(): void {
		list($total, $equipment) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		$this->expectException(Exception::class);
		energyProfile::save(array(1), array('cold' => array((string) $equipment->getId() => '120')), '100', true);
	}

	public function testSavingRefusesAnInvalidMonthOrThreshold(): void {
		list($total, $equipment) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		try {
			energyProfile::save(array(13), array(), '100', true);
			$this->fail('month 13 accepted');
		} catch (Exception $e) {
			$this->assertStringContainsString('13', $e->getMessage());
		}
		$this->expectException(Exception::class);
		energyProfile::save(array(1), array(), '5', true);
	}

	public function testSavingIgnoresAnUnknownEquipment(): void {
		list($total, $equipment) = $this->house(array(array('2025-02-01', 50.0)), array(array('2025-02-01', 20.0)));
		energyProfile::save(array(1), array('cold' => array('99999' => '50')), '100', true);
		$this->assertSame(array(), energyProfile::profile()['cold']);
	}

	public function testTheOverviewWeighsEachSeasonOfTheCounter(): void {
		$this->house(array(array(date('Y-m-d', strtotime('-10 day')), 40.0)), array());
		$overview = energyProfile::overview();
		$this->assertCount(1, $overview['equipments']);
		$this->assertSame(1, $overview['seasons']['cold']['days'] + $overview['seasons']['hot']['days']);
		$this->assertEqualsWithDelta(40.0, $overview['seasons']['cold']['kwh'] + $overview['seasons']['hot']['kwh'], 0.01);
	}
}

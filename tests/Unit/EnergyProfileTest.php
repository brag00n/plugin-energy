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

	private function profile(array $cold, array $hot = array(), array $coldMonths = array(11, 12, 1, 2, 3, 4)): void {
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
		$this->profile(array('7' => 30, '8' => 20));
		$shares = energyProfile::shares('cold');
		$this->assertEqualsWithDelta(50, $shares['unmeasured'], 0.001);
		$this->assertEqualsWithDelta(30, $shares['7'], 0.001);
	}

	public function testSharesAboveOneHundredAreScaledDown(): void {
		$this->profile(array('7' => 90, '8' => 30));
		$shares = energyProfile::shares('cold');
		$this->assertEqualsWithDelta(0, $shares['unmeasured'], 0.001);
		$this->assertEqualsWithDelta(75, $shares['7'], 0.001);
		$this->assertEqualsWithDelta(100, $shares['7'] + $shares['8'], 0.001);
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
		$this->profile(array('999' => 40));
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

<?php

final class EnergyEmptyHistoryAndDataTest extends EnergyTestCase {

	public function testEmptyHistoryClearsEveryInfoCommand(): void {
		$energy = $this->makeEnergy('electricity');
		foreach ($energy->getCmd('info') as $cmd) {
			$cmd->event(12);
		}
		$energy->emptyHistory();
		$this->assertCount(count($energy->getCmd('info')), history::$emptied);
		foreach ($energy->getCmd('info') as $cmd) {
			$this->assertSame(0, $cmd->execCmd(), $cmd->getLogicalId());
		}
	}

	public function testEmptyHistoryRealignsTheCounterIndexes(): void {
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'light', 'power' => '', 'consumption' => '#1#'),
		), array('previous#1#' => 5));
		$this->setExpression('#1#', 777);
		$energy->emptyHistory();
		$this->assertSame(777.0, $energy->getConfiguration('previous#1#'));
	}

	public function testEmptyHistoryResetsThePowerIntegrationState(): void {
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'light', 'power' => '#5#', 'consumption' => ''),
		), array('lastValue#5#' => 1500));
		$energy->emptyHistory();
		$this->assertSame(0, $energy->getConfiguration('lastValue#5#'));
		$this->assertGreaterThan(0, $energy->getConfiguration('lastChangeTime#5#'));
	}

	public function testEmptyHistoryOnWaterRealignsTheIndex(): void {
		$energy = $this->makeEnergy('water', array(
			array('category' => '', 'power' => '', 'consumption' => '#2#'),
		), array('previous#2#' => 1));
		$this->setExpression('#2#', 4321);
		$energy->emptyHistory();
		$this->assertSame(4321.0, $energy->getConfiguration('previous#2#'));
	}

	public function testGetDataExposesEveryCategory(): void {
		$energy = $this->makeEnergy('electricity');
		foreach (array('consumptionTotal', 'consumptionOther', 'consumptionLight', 'consumptionMultimedia', 'consumptionAutomatism', 'consumptionElectrical', 'consumptionHeating', 'cost') as $logicalId) {
			$cmdDouble = new HistoryEnergyCmdDouble($logicalId);
			$energy->cmds = array_filter($energy->cmds, function ($c) use ($logicalId) {
				return $c->getLogicalId() != $logicalId;
			});
			$energy->addCmdDouble($cmdDouble);
		}
		$data = $energy->getData('2026-01-01 00:00:00', '2026-01-31');
		foreach (array('consumptionTotal', 'other', 'light', 'multimedia', 'automatism', 'electrical', 'heating', 'cost') as $key) {
			$this->assertArrayHasKey($key, $data);
		}
		$this->assertSame(array(array('2026-01-01', '1')), $data['light']);
		$this->assertSame(array(array('2026-01-01', '1')), $data['consumptionTotal']);
	}

	public function testGetDataDoesNotExposeHpHc(): void {
		$energy = $this->makeEnergy('electricity');
		$data = $energy->getData();
		$this->assertArrayNotHasKey('hp', $data);
		$this->assertArrayNotHasKey('hc', $data);
	}

	public function testGetDataWaterOnlyHasTotalAndCost(): void {
		$energy = $this->makeEnergy('water');
		foreach (array('consumptionTotal', 'cost') as $logicalId) {
			$energy->cmds = array_filter($energy->cmds, function ($c) use ($logicalId) {
				return $c->getLogicalId() != $logicalId;
			});
			$energy->addCmdDouble(new HistoryEnergyCmdDouble($logicalId));
		}
		$data = $energy->getData();
		$this->assertNotEmpty($data['consumptionTotal']);
		$this->assertSame(array(), $data['light']);
	}
}

/** A command whose getHistoryEnergy() returns a single canned row instead of querying the database. */
class HistoryEnergyCmdDouble extends cmd {
	public function __construct(string $logicalId) {
		$this->logicalId = $logicalId;
		$this->type = 'info';
	}

	public function getHistoryEnergy($_startTime = null, $_endTime = null) {
		return array(array('2026-01-01', '1'));
	}
}

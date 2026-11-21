<?php

final class EnergyLifecycleTest extends EnergyTestCase {

	private const ELECTRICITY_CMDS = array(
		'cost', 'consumptionTotal', 'power', 'consumptionLight', 'consumptionOther', 'consumptionMultimedia',
		'consumptionAutomatism', 'consumptionElectrical', 'consumptionHeating', 'consumptionHp', 'consumptionHc',
	);

	public function testElectricityCreatesAllCommands(): void {
		$energy = $this->makeEnergy('electricity');
		foreach (self::ELECTRICITY_CMDS as $logicalId) {
			$this->assertNotNull($energy->getCmd(null, $logicalId), 'Missing ' . $logicalId);
		}
		$this->assertCount(count(self::ELECTRICITY_CMDS), $energy->getCmd('info'));
	}

	public function testWaterAndGasOnlyHaveCostAndTotal(): void {
		foreach (array('water', 'gas') as $type) {
			eqLogic::reset();
			$energy = $this->makeEnergy($type);
			$this->assertCount(2, $energy->getCmd('info'), $type);
			$this->assertNotNull($energy->getCmd(null, 'cost'));
			$this->assertNotNull($energy->getCmd(null, 'consumptionTotal'));
			$this->assertNull($energy->getCmd(null, 'power'));
		}
	}

	public function testWaterAndGasAreTotalCounters(): void {
		$this->assertSame(1, $this->makeEnergy('water')->getConfiguration('totalCounter'));
	}

	public function testElectricityIsNotForcedToTotalCounter(): void {
		$this->assertSame('', $this->makeEnergy('electricity')->getConfiguration('totalCounter'));
	}

	public function testSavingTwiceDoesNotDuplicateCommands(): void {
		$energy = $this->makeEnergy('electricity');
		$energy->save();
		$energy->save();
		$this->assertCount(count(self::ELECTRICITY_CMDS), $energy->getCmd('info'));
	}

	public function testHpAndHcLogicalIdsMatchTheLookups(): void {
		$energy = $this->makeEnergy('electricity');
		$this->assertSame('consumptionHp', $energy->getCmd(null, 'consumptionHp')->getLogicalId());
		$this->assertSame('consumptionHc', $energy->getCmd(null, 'consumptionHc')->getLogicalId());
	}

	public function testUnits(): void {
		$electricity = $this->makeEnergy('electricity');
		$this->assertSame('kWh', $electricity->getCmd(null, 'consumptionTotal')->getUnite());
		$this->assertSame('kWh', $electricity->getCmd(null, 'consumptionHp')->getUnite());
		$this->assertSame('W', $electricity->getCmd(null, 'power')->getUnite());

		eqLogic::reset();
		$this->assertSame('L', $this->makeEnergy('water')->getCmd(null, 'consumptionTotal')->getUnite());
		eqLogic::reset();
		$this->assertSame('kWh', $this->makeEnergy('gas')->getCmd(null, 'consumptionTotal')->getUnite());
	}

	public function testCurrencyComesFromTheConfiguration(): void {
		$this->setConfig('currency', '$');
		$this->assertSame('$', $this->makeEnergy('electricity')->getCmd(null, 'cost')->getUnite());
	}

	public function testCurrencyDefaultsToEuro(): void {
		$this->assertSame('€', $this->makeEnergy('electricity')->getCmd(null, 'cost')->getUnite());
	}

	public function testNewEquipmentShowsPowerCostAndTotalByDefault(): void {
		$energy = $this->makeEnergy('electricity');
		$this->assertSame(1, $energy->getCmd(null, 'power')->getIsVisible());
		$this->assertSame(1, $energy->getCmd(null, 'cost')->getIsVisible());
		$this->assertSame(1, $energy->getCmd(null, 'consumptionTotal')->getIsVisible());
		$this->assertSame(0, $energy->getCmd(null, 'consumptionLight')->getIsVisible());
	}

	public function testVisibilityFollowsTheEquipmentConfiguration(): void {
		$energy = $this->makeEnergy('electricity');
		$energy->setConfiguration('visibleConsumptionLight', 1);
		$energy->setConfiguration('visibleConsumptionHP', 1);
		$energy->save();
		$this->assertSame(1, $energy->getCmd(null, 'consumptionLight')->getIsVisible());
		$this->assertSame(1, $energy->getCmd(null, 'consumptionHp')->getIsVisible());
		$this->assertSame(0, $energy->getCmd(null, 'consumptionHc')->getIsVisible());
	}

	public function testConsumptionCommandsAreHistorizedWithMax(): void {
		$cmd = $this->makeEnergy('electricity')->getCmd(null, 'consumptionLight');
		$this->assertSame(1, $cmd->isHistorized);
		$this->assertSame('max', $cmd->getConfiguration('historizeMode'));
	}

	public function testEmptyTypeIsRejectedOnUpdate(): void {
		$energy = $this->makeEnergy('electricity');
		$energy->setConfiguration('type', '');
		$this->expectException(Exception::class);
		$energy->save();
	}

	public function testUpdateSetsTheEnergyCategory(): void {
		$energy = $this->makeEnergy('electricity');
		$energy->save();
		$this->assertSame(1, $energy->category['energy']);
	}

	public function testEnergyCmdHasNoExecution(): void {
		$cmd = new energyCmd();
		$this->assertNull($cmd->execute());
		$this->assertTrue($cmd->dontRemoveCmd());
	}

	public function testEnergyCmdRoundsNumericValuesForTheWidget(): void {
		$cmd = new energyCmd();
		$cmd->setType('info');
		$cmd->setSubType('numeric');
		$this->assertSame(1.23, $cmd->formatValueWidget(1.2345));
	}

	public function testEnergyCmdLeavesOtherValuesUntouched(): void {
		$cmd = new energyCmd();
		$cmd->setType('info');
		$cmd->setSubType('string');
		$this->assertSame('abc', $cmd->formatValueWidget('abc'));
	}
}

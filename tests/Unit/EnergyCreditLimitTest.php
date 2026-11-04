<?php

/** A credit above what the subscribed power allows is an error of the source: it is ignored with a warning. */
final class EnergyCreditLimitTest extends EnergyTestCase {

	private function meter(float $previous): energy {
		return $this->makeEnergy('electricity', array(
			array('category' => 'hc', 'power' => '', 'consumption' => '#10#'),
		), array('previous#10#' => $previous));
	}

	private function cycle(int $at, float $index): void {
		$this->setExpression('#10#', $index);
		energy::$nowOverride = $at;
		energy::cron15();
	}

	private function warnings(): string {
		return implode("\n", array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[1] === 'warning';
		})));
	}

	public function testTheProtectionIsOffByDefault(): void {
		$energy = $this->meter(0);
		$this->cycle(mktime(12, 0, 0), 100);
		$this->assertEqualsWithDelta(100, $this->cmdValue($energy, 'consumptionTotal'), 0.0001, 'nothing is refused without a subscribed power');
	}

	public function testTheProductionCaseIsIgnored(): void {
		// 60 A on a 12 kVA contract: a 100 kWh jump in one 15 minute cycle is impossible
		$this->setConfig('energySubscribedPower', '12');
		$energy = $this->meter(0.5);
		$this->cycle(mktime(22, 30, 0), 0.6);
		$this->cycle(mktime(22, 45, 0), 100);
		$this->assertEqualsWithDelta(0.1, $this->cmdValue($energy, 'consumptionTotal'), 0.0001, 'only the real 0.1 kWh is credited');
		$this->assertStringContainsString('Credit ignored', $this->warnings());
		$this->assertStringContainsString('100', $this->warnings());
	}

	public function testTheIndexIsRealignedSoOnlyThatCycleIsLost(): void {
		$this->setConfig('energySubscribedPower', '12');
		$energy = $this->meter(0);
		$this->cycle(mktime(22, 30, 0), 0.1);
		$this->cycle(mktime(22, 45, 0), 100);
		$this->assertSame(100.0, floatval($energy->getConfiguration('previous#10#')));
		$this->cycle(mktime(23, 0, 0), 100.4);
		$this->assertEqualsWithDelta(0.5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001, '0.1 then 0.4: the next cycle is credited normally');
	}

	public function testARealHighConsumptionIsKept(): void {
		// 12 kVA x 1.5 x 0.25 h = 4.5 kWh allowed in a cycle: 3.4 kWh (13.6 kW) is a heavy but possible load
		$this->setConfig('energySubscribedPower', '12');
		$energy = $this->meter(0);
		$this->cycle(mktime(12, 0, 0), 0.1);
		$this->cycle(mktime(12, 15, 0), 3.5);
		$this->assertEqualsWithDelta(3.5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertStringNotContainsString('Credit ignored', $this->warnings());
	}

	public function testTheLimitFollowsTheRealDurationOfTheCycle(): void {
		// a counter that catches up after 3 hours without cron is legitimate: 12 kVA x 1.5 x 3 h = 54 kWh allowed
		$this->setConfig('energySubscribedPower', '12');
		$energy = $this->meter(0);
		$this->cycle(mktime(9, 0, 0), 0.1);
		$this->cycle(mktime(12, 0, 0), 20);
		$this->assertEqualsWithDelta(20, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertStringNotContainsString('Credit ignored', $this->warnings());
	}

	public function testTheLimitIsComputedFromTheSubscribedPower(): void {
		$this->assertNull(energy::maxCreditKwh(900));
		$this->setConfig('energySubscribedPower', '12');
		$this->assertEqualsWithDelta(4.5, energy::maxCreditKwh(900), 0.0001);
		$this->assertEqualsWithDelta(4.5, energy::maxCreditKwh(60), 0.0001, 'never less than a nominal cycle');
		$this->setConfig('energySubscribedPower', '6,5');
		$this->assertEqualsWithDelta(2.4375, energy::maxCreditKwh(900), 0.0001, 'comma accepted');
	}

	public function testTheProtectionIsIndependentOfTheAnomalyDetection(): void {
		$this->setConfig('energySubscribedPower', '12');
		$this->setConfig('energyAnomalyEnabled', '0');
		$energy = $this->meter(0);
		$this->cycle(mktime(22, 30, 0), 0.1);
		$this->cycle(mktime(22, 45, 0), 100);
		$this->assertEqualsWithDelta(0.1, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertStringContainsString('Credit ignored', $this->warnings());
	}
}

<?php

/**
 * A decreasing index is held for one cycle before it is believed (see energy::holdIndexDecrease()).
 * Production case: the Linky gateway sometimes publishes a power instead of the index, so the counter read 0 for one
 * cycle and came back to 20 kWh. Realigning at once on the 0 lost the consumption of the following cycle too.
 */
final class EnergyIndexGlitchTest extends EnergyTestCase {

	private function meter(float $previous, string $category = 'hc'): energy {
		return $this->makeEnergy('electricity', array(
			array('category' => $category, 'power' => '', 'consumption' => '#10#'),
		), array('previous#10#' => $previous));
	}

	private function cycle(int $at, $index): void {
		$this->setExpression('#10#', $index);
		energyRuntime::$now = $at;
		energy::cron15();
	}

	private function logged(string $level, string $text): bool {
		foreach (log::$lines as $line) {
			if ($line[1] === $level && strpos($line[2], $text) !== false) {
				return true;
			}
		}
		return false;
	}

	public function testAGlitchOfOneCycleLosesNothing(): void {
		$energy = $this->meter(20.0);
		$this->cycle(mktime(12, 0, 0), 20.2);
		$this->cycle(mktime(12, 15, 0), 0.17);   // the source published a power: the index "went down"
		$this->cycle(mktime(12, 30, 0), 20.6);   // and it is back
		// 0.2 then nothing then 0.4 from the reference index: the consumption of the glitch cycle is not lost
		$this->assertEqualsWithDelta(0.6, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertSame(20.6, floatval($energy->getConfiguration('previous#10#')));
	}

	public function testTheReferenceIndexIsKeptDuringTheGlitch(): void {
		$energy = $this->meter(20.0);
		$this->cycle(mktime(12, 0, 0), 0.0);
		$this->assertSame(20.0, floatval($energy->getConfiguration('previous#10#')), 'not realigned on the suspect reading');
		$this->assertEquals(0, $this->cmdValue($energy, 'consumptionTotal'), 'nothing credited');
		$this->assertTrue($this->logged('warning', 'Index decrease held'));
	}

	public function testTheOldBehaviourLostTheNextCycleAndTheNewOneDoesNot(): void {
		// 12 kVA: 20.4 kWh in one cycle would be refused as impossible, which is what happened after a realignment on 0
		$this->setConfig('energySubscribedPower', '12');
		$energy = $this->meter(20.0);
		$this->cycle(mktime(12, 0, 0), 0.17);
		$this->cycle(mktime(12, 15, 0), 20.3);
		$this->assertEqualsWithDelta(0.3, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertFalse($this->logged('warning', 'Credit ignored'), 'no impossible credit is produced any more');
	}

	public function testARealResetIsFollowedAfterTwoReadings(): void {
		$energy = $this->meter(5000.0);
		$this->cycle(mktime(12, 0, 0), 12.0);
		$this->assertSame(5000.0, floatval($energy->getConfiguration('previous#10#')), 'first reading: held');
		$this->cycle(mktime(12, 15, 0), 12.5);   // still low: the counter really was reset
		$this->assertSame(12.5, floatval($energy->getConfiguration('previous#10#')), 'second reading: realigned');
		$this->assertTrue($this->logged('debug', 'Counter reset detected'));
		$this->cycle(mktime(12, 30, 0), 13.5);
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'consumptionTotal'), 0.0001, 'consumption resumes right after the realignment');
	}

	public function testTheHoldIsClearedWhenTheIndexIsBack(): void {
		$energy = $this->meter(20.0);
		$this->cycle(mktime(12, 0, 0), 0.17);
		$this->cycle(mktime(12, 15, 0), 20.1);
		$this->assertFalse(is_numeric($energy->getConfiguration('pendingDrop#10#', '')), 'nothing pending any more');
		$this->assertTrue($this->logged('info', 'Index back to normal'));
		// a new, independent glitch is held again instead of being taken for a confirmed reset
		$this->cycle(mktime(12, 30, 0), 0.2);
		$this->assertSame(20.1, floatval($energy->getConfiguration('previous#10#')));
	}

	public function testTwoSeparateGlitchesAreNotMistakenForAReset(): void {
		$energy = $this->meter(20.0);
		$this->cycle(mktime(12, 0, 0), 0.17);
		$this->cycle(mktime(12, 15, 0), 20.2);
		$this->cycle(mktime(12, 30, 0), 0.43);
		$this->cycle(mktime(12, 45, 0), 20.5);
		$this->assertEqualsWithDelta(0.5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
		$this->assertSame(20.5, floatval($energy->getConfiguration('previous#10#')));
	}

	public function testAnIndexRefusedByThePowerLimitDoesNotLeaveAHoldBehind(): void {
		$this->setConfig('energySubscribedPower', '12');
		$energy = $this->meter(20.0);
		$this->cycle(mktime(12, 0, 0), 0.17);       // held
		$this->cycle(mktime(12, 15, 0), 200.0);     // back, but absurdly high: refused by the limit, realigned
		$this->assertFalse(is_numeric($energy->getConfiguration('pendingDrop#10#', '')));
		$this->assertTrue($this->logged('warning', 'Credit ignored'));
	}

	public function testNonNumericReadingChangesNothing(): void {
		$energy = $this->meter(20.0);
		$this->cycle(mktime(12, 0, 0), 'unavailable');
		$this->assertSame(20.0, floatval($energy->getConfiguration('previous#10#')));
		$this->assertFalse(is_numeric($energy->getConfiguration('pendingDrop#10#', '')), 'a missing reading is not a decrease');
	}

	public function testWaterAndGasFollowTheSameRule(): void {
		foreach (array('water', 'gas') as $type) {
			eqLogic::reset();
			$energy = $this->makeEnergy($type, array(
				array('category' => 'other', 'power' => '', 'consumption' => '#20#'),
			), array('previous#20#' => 1000.0));
			$this->setExpression('#20#', 0.0);
			energyRuntime::$now = mktime(12, 0, 0);
			energy::cron15();
			$this->assertSame(1000.0, floatval($energy->getConfiguration('previous#20#')), $type . ': held');
			$this->setExpression('#20#', 1003.0);
			energyRuntime::$now = mktime(12, 15, 0);
			energy::cron15();
			$this->assertEqualsWithDelta(3.0, $this->cmdValue($energy, 'consumptionTotal'), 0.0001, $type . ': the 3 units are credited');
		}
	}
}

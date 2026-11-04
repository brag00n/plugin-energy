<?php

/** A wrong first rate must not blind the detector (R12), and emptying the history forgets the profile. */
final class EnergyOutlierAndForgetTest extends EnergyTestCase {

	/** First cycle jumps by $firstKwh, then $count cycles of 0.5 kWh; returns the equipment. */
	private function cycles(float $firstKwh, int $count): energy {
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '', 'consumption' => '#9#'),
		), array('previous#9#' => 0));
		$now = mktime(0, 0, 0, 11, 1, 2026);
		$index = 0;
		for ($i = 0; $i <= $count; $i++) {
			$index += ($i == 0) ? $firstKwh : 0.5;
			$this->setExpression('#9#', $index);
			energy::$nowOverride = $now + $i * 900;
			energy::cron15();
		}
		return $energy;
	}

	private function profile(energy $energy): array {
		return json_decode($energy->getConfiguration('jumpProfile'), true);
	}

	public function testAnAberrantFirstCycleIsDroppedOnceTheProfileIsEstablished(): void {
		$energy = $this->cycles(100, 30);
		$profile = $this->profile($energy);
		$this->assertLessThan(5000, max($profile['days']), 'the 400 000 W cycle must not stay as the reference');
		$this->assertStringContainsString('dropped from the profile', implode("\n", array_map(function ($l) {
			return $l[2];
		}, array_filter(log::$lines, function ($l) {
			return $l[1] === 'warning';
		}))));
	}

	public function testTheDetectorStillJudgesAfterwards(): void {
		$energy = $this->cycles(100, 30);
		log::$lines = array();
		$this->setExpression('#9#', 0.5 * 30 + 100 + 50);
		energy::$nowOverride = mktime(0, 0, 0, 11, 1, 2026) + 31 * 900;
		energy::cron15();
		$this->assertStringContainsString('Abnormal consumption jump', implode("\n", array_map(function ($l) {
			return $l[2];
		}, log::$lines)));
	}

	public function testANormalProfileIsLeftUntouched(): void {
		$energy = $this->cycles(0.5, 30);
		$this->assertEqualsWithDelta(2000, max($this->profile($energy)['days']), 1);
		$this->assertSame(array(), $this->profile($energy)['seed']);
	}

	public function testTheSeedIsStoredOnlyWhileTheProfileIsBuilt(): void {
		$energy = $this->cycles(0.5, 5);
		$this->assertCount(6, $this->profile($energy)['seed']);
	}

	public function testEmptyHistoryForgetsTheProfile(): void {
		$energy = $this->cycles(0.5, 5);
		$this->assertNotSame('', $energy->getConfiguration('jumpProfile'));
		$energy->emptyHistory();
		$this->assertSame('', $energy->getConfiguration('jumpProfile'));
	}
}

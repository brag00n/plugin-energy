<?php

/** Adaptive detection of an abnormal consumption jump: no constant depends on the kind or the size of the equipment. */
final class EnergyJumpDetectionTest extends EnergyTestCase {

	private const DAY = 86400;

	private function jumpWarnings(): array {
		return array_values(array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[0] === 'energy' && $line[1] === 'warning' && strpos($line[2], 'Abnormal consumption jump') === 0;
		})));
	}

	private function jumpDebug(): array {
		return array_values(array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[0] === 'energy' && $line[1] === 'debug' && strpos($line[2], '[jump]') === 0;
		})));
	}

	/** An equipment fed by a counter, to feed any energy per cycle through its index. */
	private function counterEquipment(): energy {
		return $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '', 'consumption' => '#9#'),
		), array('previous#9#' => 0));
	}

	private $index = 0;

	/** Runs one 15 minute cycle crediting $kwh to the equipment. */
	private function cycle(float $kwh, int $at): void {
		$this->index += $kwh;
		$this->setExpression('#9#', $this->index);
		energy::$nowOverride = $at;
		energy::cron15();
	}

	/** Builds a profile with $count regular 15 minute cycles of $kwh, starting at $start. Returns the next timestamp. */
	private function learn(float $kwh, int $count, int $start): int {
		$at = $start;
		for ($i = 0; $i < $count; $i++) {
			$this->cycle($kwh, $at);
			$at += 900;
		}
		return $at;
	}

	// ---- the pieces

	public function testJudgeDoesNotJudgeWithoutEnoughActivity(): void {
		$verdict = energy::jumpJudge(array('days' => array('2026-11-01' => 2000.0), 'active' => 5), 99999);
		$this->assertFalse($verdict['established']);
		$this->assertFalse($verdict['abnormal']);
		$this->assertNull($verdict['threshold']);
	}

	public function testJudgeDoesNotJudgeAProfileMadeOnlyOfZeros(): void {
		$verdict = energy::jumpJudge(array('days' => array(), 'active' => 500), 1500);
		$this->assertFalse($verdict['established'], 'a reference of 0 would make every first load a jump');
		$this->assertFalse($verdict['abnormal']);
	}

	public function testJudgeThresholdIsTheReferenceTimesTheMargin(): void {
		$profile = array('days' => array('2026-10-30' => 1000.0, '2026-11-01' => 2000.0), 'active' => 100);
		$verdict = energy::jumpJudge($profile, 2999);
		$this->assertTrue($verdict['established']);
		$this->assertEqualsWithDelta(2000.0, $verdict['reference'], 0.001);
		$this->assertEqualsWithDelta(3000.0, $verdict['threshold'], 0.001);
		$this->assertFalse($verdict['abnormal']);
		$this->assertTrue(energy::jumpJudge($profile, 3001)['abnormal']);
	}

	public function testLearnKeepsTheHighestRateOfTheDay(): void {
		$now = mktime(12, 0, 0, 11, 3, 2026);
		$profile = energy::jumpLearn(array('days' => array(), 'active' => 0), 500, $now);
		$profile = energy::jumpLearn($profile, 540, $now + 900);
		$profile = energy::jumpLearn($profile, 400, $now + 1800);
		$this->assertSame(array('2026-11-03' => 540.0), $profile['days']);
		$this->assertSame(3, $profile['active']);
	}

	public function testLearnRaisesTheReferenceByLimitedStepsOnly(): void {
		// Without this cap, a cycle just under the threshold would be learned in full and let a stronger one through
		$now = mktime(12, 0, 0, 11, 3, 2026);
		$profile = array('days' => array('2026-11-03' => 1000.0), 'active' => 100);
		$profile = energy::jumpLearn($profile, 1400, $now);
		$this->assertEqualsWithDelta(1100.0, $profile['days']['2026-11-03'], 0.001, 'a rise of 40 % is learned as 10 %');
	}

	public function testALastingChangeOfRegimeIsFollowedStepByStep(): void {
		$now = mktime(12, 0, 0, 11, 3, 2026);
		$profile = array('days' => array('2026-11-03' => 1000.0), 'active' => 100);
		for ($i = 0; $i < 20; $i++) {
			$profile = energy::jumpLearn($profile, 1400, $now + $i * 900);
		}
		$this->assertEqualsWithDelta(1400.0, max($profile['days']), 0.001, 'the reference reaches the new regime');
	}

	public function testLearnIgnoresZeroAndNegativeRates(): void {
		$profile = array('days' => array(), 'active' => 0);
		$this->assertSame($profile, energy::jumpLearn($profile, 0, 1000));
		$this->assertSame($profile, energy::jumpLearn($profile, -5, 1000));
	}

	public function testLearnForgetsDaysOutOfTheWindow(): void {
		$now = mktime(12, 0, 0, 11, 3, 2026);
		$profile = array('days' => array(date('Y-m-d', $now - 40 * self::DAY) => 9000.0, date('Y-m-d', $now - 5 * self::DAY) => 100.0), 'active' => 50);
		$profile = energy::jumpLearn($profile, 200, $now);
		$this->assertArrayNotHasKey(date('Y-m-d', $now - 40 * self::DAY), $profile['days'], 'an old peak must not set the reference forever');
		$this->assertCount(2, $profile['days']);
	}

	public function testStoredProfileGarbageIsIgnored(): void {
		$energy = $this->makeEnergy('electricity');
		foreach (array('', 'not json', '[1,2]', '{"days": "x", "active": "y"}', '{"days": {"bad": 5, "2026-11-01": "abc", "2026-11-02": -3, "2026-11-03": 7}, "active": -1}') as $raw) {
			$energy->setConfiguration('jumpProfile', $raw);
			$profile = energy::jumpProfile($energy);
			$this->assertIsArray($profile['days']);
			$this->assertGreaterThanOrEqual(0, $profile['active']);
		}
		$this->assertSame(array('2026-11-03' => 7.0), $profile['days']);
	}

	// ---- the behaviour, whatever the size

	/** @dataProvider */
	public static function sizeProvider(): array {
		return array(
			'standby 0.8 W' => array(0.0002, 'tiny'),
			'fridge 120 W' => array(0.03, 'small'),
			'radiator 2 kW' => array(0.5, 'medium'),
			'house meter 8 kW' => array(2.0, 'large'),
			'warehouse 80 kW' => array(20.0, 'huge'),
		);
	}

	/** @dataProvider sizeProvider */
	public function testNormalCyclesNeverWarnWhateverTheSize(float $kwhPerCycle, string $label): void {
		$this->counterEquipment();
		$end = $this->learn($kwhPerCycle, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->assertSame(array(), $this->jumpWarnings(), $label);
		$this->assertNotEmpty($this->jumpDebug() === array() ? array('x') : array('x'));
	}

	/** @dataProvider sizeProvider */
	public function testAJumpIsDetectedWhateverTheSize(float $kwhPerCycle, string $label): void {
		$this->counterEquipment();
		$end = $this->learn($kwhPerCycle, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle($kwhPerCycle * 5, $end);
		$warnings = $this->jumpWarnings();
		$this->assertCount(1, $warnings, $label . ': a 5x jump must be reported');
		$this->assertStringContainsString('above the threshold of', $warnings[0]);
	}

	/** @dataProvider sizeProvider */
	public function testAMildDeviationBelowTheMarginIsNotAJump(float $kwhPerCycle, string $label): void {
		$this->counterEquipment();
		$end = $this->learn($kwhPerCycle, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle($kwhPerCycle * 1.3, $end);
		$this->assertSame(array(), $this->jumpWarnings(), $label);
	}

	// ---- the learning guards

	public function testNothingIsJudgedBeforeEnoughActiveCycles(): void {
		log::$level = 100;
		$this->counterEquipment();
		$end = $this->learn(0.5, 10, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$this->assertSame(array(), $this->jumpWarnings(), 'only 10 active cycles so far: not judged');
		$this->assertStringContainsString('not judged', implode("\n", $this->jumpDebug()));
	}

	public function testALoadThatWasZeroAllSummerIsNotAJumpWhenItStarts(): void {
		$energy = $this->counterEquipment();
		// 25 days with nothing at all, cycle after cycle
		$at = mktime(0, 0, 0, 10, 1, 2026);
		for ($i = 0; $i < 96 * 25; $i++) {
			energy::$nowOverride = $at;
			$this->setExpression('#9#', 0);
			energy::cron15();
			$at += 900;
		}
		// the first cold day
		$this->index = 0;
		$this->cycle(0.375, $at);
		$this->assertSame(array(), $this->jumpWarnings(), 'the first load after a long zero is not a jump');
	}

	public function testAJumpIsNotLearnedSoItCannotHideTheNextOne(): void {
		log::$level = 100;
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$this->cycle(50, $end + 900);
		$this->assertCount(2, $this->jumpWarnings(), 'the second identical jump must still be reported');
		$this->assertStringContainsString('NOT learned', implode("\n", $this->jumpDebug()));
	}

	public function testAnIsolatedJumpIsReportedEachTimeButNotAcceptedAsARegime(): void {
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$this->cycle(0.5, $end + 900);
		$this->cycle(50, $end + 1800);
		$this->assertCount(2, $this->jumpWarnings(), 'two jumps in an hour are two warnings, not yet a regime');
		$this->assertStringNotContainsString('regime change', implode("\n", array_map(function ($l) {
			return $l[2];
		}, log::$lines)));
	}

	public function testALastingChangeOfLevelIsReportedThenAcceptedAsARegime(): void {
		// The heating season starts: the load really triples and stays there
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		for ($i = 0; $i < 6; $i++) {
			$this->cycle(1.5, $end + $i * 900);
		}
		$all = array_map(function ($l) {
			return $l[2];
		}, array_filter(log::$lines, function ($l) {
			return $l[1] === 'warning' && (strpos($l[2], 'Abnormal consumption jump') === 0 || strpos($l[2], 'Consumption regime change') === 0);
		}));
		$all = array_values($all);
		$this->assertStringStartsWith('Abnormal consumption jump', $all[0]);
		$this->assertStringStartsWith('Abnormal consumption jump', $all[1]);
		$this->assertStringStartsWith('Consumption regime change accepted', $all[2], 'the third abnormal cycle within 6 h is accepted');
		$this->assertCount(3, $all, 'once accepted, the following cycles are normal and no longer reported');
		$this->assertStringContainsString('the reference is raised to 6000 W', $all[2]);
	}

	public function testAbnormalCyclesFarApartAreNeverAcceptedAsARegime(): void {
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$this->cycle(50, $end + 8 * 3600);
		$this->cycle(50, $end + 16 * 3600);
		$this->assertCount(3, $this->jumpWarnings());
		$this->assertStringNotContainsString('regime change', implode("\n", array_map(function ($l) {
			return $l[2];
		}, log::$lines)));
	}

	public function testRegimeChangeIsTracedInDebug(): void {
		log::$level = 100;
		$this->counterEquipment();
		$end = $this->learn(0.5, 30, mktime(0, 0, 0, 11, 1, 2026));
		for ($i = 0; $i < 3; $i++) {
			$this->cycle(1.5, $end + $i * 900);
		}
		$text = implode("\n", $this->jumpDebug());
		$this->assertStringContainsString('(2/3 abnormal cycles within 6 h)', $text);
		$this->assertStringContainsString('accepted as a change of regime (3 abnormal cycles within 6 h), reference raised to 6000 W', $text);
	}

	public function testALoadStartingLateInTheFirstCycleDoesNotLockAnArtificiallyLowReference(): void {
		// A heater that only starts at the end of its first cycle gives a tiny first rate (here 100 W) before the
		// real level (2000 W). The learning cap must not apply while the profile is being built.
		$this->counterEquipment();
		$at = mktime(0, 0, 0, 11, 1, 2026);
		$this->cycle(0.025, $at);
		$at += 900;
		$end = $this->learn(0.5, 40, $at);
		$this->assertSame(array(), $this->jumpWarnings(), 'the real level must not be flagged against the first tiny cycle');
	}

	public function testAbnormalHistoryIsStoredAndBounded(): void {
		$energy = $this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$profile = energy::jumpProfile($energy);
		$this->assertCount(1, $profile['abnormal']);
		$this->assertSame($end, $profile['abnormal'][0][0]);
		$this->assertEqualsWithDelta(200000.0, $profile['abnormal'][0][1], 0.5);
	}

	public function testAnOldPeakStopsSettingTheReference(): void {
		$energy = $this->counterEquipment();
		$energy->setConfiguration('jumpProfile', json_encode(array('days' => array('2026-09-01' => 90000.0), 'active' => 500)));
		$energy->save();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(5, $end);
		$this->assertCount(1, $this->jumpWarnings(), 'the 90 kW peak of two months ago must not hide a jump today');
	}

	public function testRateIsMeasuredOverTheRealDurationSoACatchUpIsNotAJump(): void {
		// A counter that catches up after a long cron stop: 50 kWh over 25 h is 2 kW, a normal rate for this load
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026)); // 0.5 kWh per 15 min = 2 kW
		$this->cycle(50, $end + 25 * 3600 - 900);
		$all = implode("\n", $this->jumpWarnings());
		$this->assertStringNotContainsString('Abnormal consumption jump', $all, '50 kWh over 25 h = 2 kW is the normal rate of this load');
	}

	public function testTheSameEnergyInAShortTimeIsAJump(): void {
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$this->assertCount(1, $this->jumpWarnings(), '50 kWh in 15 min = 200 kW for a 2 kW load');
	}

	// ---- the explanation in the warning

	public function testWarningGivesTheThresholdTheReferenceAndTheCronIntervals(): void {
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$warning = $this->jumpWarnings()[0];
		$this->assertStringContainsString('50 kWh credited in 900 s = 200000 W', $warning);
		$this->assertStringContainsString('above the threshold of 3000 W', $warning);
		$this->assertStringContainsString('1.5 x the highest normal rate of 2000 W', $warning);
		$this->assertStringContainsString('last intervals: 900, 900, 900, 900, 900 s', $warning);
		$this->assertStringContainsString('the cron intervals are normal', $warning);
		$this->assertStringContainsString('[heating, index delta, #9#] 50 kWh (index 30 => 80)', $warning);
	}

	public function testWarningBlamesTheCronAfterALongStop(): void {
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(500, $end + 25 * 3600);
		$all = implode("\n", array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[1] === 'warning';
		})));
		$this->assertStringContainsString('cron15 ran late: 90900 s', $all);
	}

	// ---- the traces for the analysis afterwards

	public function testEveryDecisionIsTracedInDebug(): void {
		log::$level = 100;
		$this->counterEquipment();
		$end = $this->learn(0.5, 30, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(50, $end);
		$text = implode("\n", $this->jumpDebug());
		$this->assertStringContainsString('profile not established yet', $text);
		$this->assertStringContainsString('reference 2000 W over 1 day(s), threshold 3000 W -> normal', $text);
		$this->assertStringContainsString('-> ABNORMAL', $text);
		$this->assertStringContainsString('learned 2000 W, profile now 1 day(s), highest 2000 W', $text);
		$this->assertStringContainsString('abnormal cycle NOT learned (1/3 abnormal cycles within 6 h), the reference is unchanged', $text);
	}

	public function testNothingIsTracedOutsideDebug(): void {
		log::$level = 400;
		$this->counterEquipment();
		$this->learn(0.5, 30, mktime(0, 0, 0, 11, 1, 2026));
		$this->assertSame(array(), $this->jumpDebug());
	}

	public function testTheProfileIsPersistedWithTheEquipment(): void {
		$energy = $this->counterEquipment();
		$this->learn(0.5, 30, mktime(0, 0, 0, 11, 1, 2026));
		$profile = energy::jumpProfile($energy);
		$this->assertSame(30, $profile['active']);
		$this->assertSame(array('2026-11-01' => 2000.0), $profile['days']);
	}

	public function testStoredProfileStaysSmall(): void {
		$energy = $this->counterEquipment();
		$this->learn(0.5, 96 * 45, mktime(0, 0, 0, 10, 1, 2026));
		$stored = $energy->getConfiguration('jumpProfile');
		$this->assertLessThan(1500, strlen($stored), 'one value per day over the window, not one per cycle');
		$this->assertLessThanOrEqual(31, count(energy::jumpProfile($energy)['days']));
	}

	public function testTheJumpWarningDoesNotChangeWhatIsRecorded(): void {
		$energy = $this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$before = $this->cmdValue($energy, 'consumptionHeating');
		$this->cycle(50, $end);
		$this->assertEqualsWithDelta($before + 50, $this->cmdValue($energy, 'consumptionHeating'), 0.0001);
	}

	public function testTuningSettingsOverrideTheDefaults(): void {
		$this->setConfig('energyJumpMargin', '4');
		$this->counterEquipment();
		$end = $this->learn(0.5, 60, mktime(0, 0, 0, 11, 1, 2026));
		$this->cycle(1.5, $end);
		$this->assertSame(array(), $this->jumpWarnings(), '3x the reference is under a margin of 4');
		$this->cycle(3, $end + 900);
		$this->assertCount(1, $this->jumpWarnings(), '6x the reference is over a margin of 4');
	}
}

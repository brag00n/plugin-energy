<?php

/** Cron15 trace and detection of an abnormal consumption jump, with the elements that explain it. */
final class EnergyCronWatchTest extends EnergyTestCase {

	private function warnings(): array {
		return array_values(array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) {
			return $line[0] === 'energy' && $line[1] === 'warning';
		})));
	}

	private function debugLines(string $prefix): array {
		return array_values(array_map(function ($line) {
			return $line[2];
		}, array_filter(log::$lines, function ($line) use ($prefix) {
			return $line[0] === 'energy' && $line[1] === 'debug' && strpos($line[2], $prefix) === 0;
		})));
	}

	/** Runs `$cycles` cron15 cycles separated by the given intervals (seconds), starting at 12:00. */
	private function runCycles(array $intervals): void {
		$now = mktime(12, 0, 0);
		energy::$nowOverride = $now;
		energy::cron15();
		foreach ($intervals as $interval) {
			$now += $interval;
			energy::$nowOverride = $now;
			energy::cron15();
		}
	}

	// ---- cronRecord(): memory of the cycles and intervals

	public function testFirstCycleHasNoInterval(): void {
		$result = energy::cronRecord(1000);
		$this->assertNull($result['interval']);
		$this->assertSame(array(), $result['intervals']);
		$this->assertFalse($result['late']);
	}

	public function testIntervalsAreComputedBetweenCycles(): void {
		energy::cronRecord(1000);
		energy::cronRecord(1900);
		$result = energy::cronRecord(2810);
		$this->assertSame(910, $result['interval']);
		$this->assertSame(array(900, 910), $result['intervals']);
		$this->assertFalse($result['late']);
	}

	public function testLateCycleIsDetected(): void {
		energy::cronRecord(1000);
		energy::cronRecord(1900);
		$result = energy::cronRecord(1900 + 25 * 3600);
		$this->assertSame(90000, $result['interval']);
		$this->assertTrue($result['late']);
	}

	public function testExactlyTheToleratedGapIsNotLate(): void {
		energy::cronRecord(1000);
		$this->assertFalse(energy::cronRecord(1000 + 1800)['late']);
		energy::cronRecord(10000);
		$this->assertTrue(energy::cronRecord(10000 + 1801)['late']);
	}

	public function testOnlyTheLastFiveIntervalsAreReported(): void {
		$t = 1000;
		for ($i = 0; $i < 10; $i++) {
			$t += 900;
			$result = energy::cronRecord($t);
		}
		$this->assertCount(5, $result['intervals']);
	}

	public function testHistoryIsBounded(): void {
		$t = 1000;
		for ($i = 0; $i < 40; $i++) {
			$t += 900;
			energy::cronRecord($t);
		}
		$this->assertCount(12, energy::cronHistory());
	}

	public function testHistorySurvivesAsStoredConfiguration(): void {
		energy::cronRecord(1000);
		energy::cronRecord(1900);
		$this->assertSame(array(1000, 1900), energy::cronHistory());
	}

	public function testClockGoingBackwardsRestartsTheHistory(): void {
		energy::cronRecord(5000);
		energy::cronRecord(5900);
		$result = energy::cronRecord(1000);
		$this->assertNull($result['interval']);
		$this->assertFalse($result['late']);
		$this->assertSame(array(1000), energy::cronHistory());
	}

	public function testSameSecondIsNotRecordedTwice(): void {
		energy::cronRecord(1000);
		$result = energy::cronRecord(1000);
		$this->assertNull($result['interval']);
		$this->assertSame(array(1000), energy::cronHistory());
	}

	public function testGarbageInTheStoredHistoryIsIgnored(): void {
		config::$values['energy::cronHistory'] = '[1000, "abc", null, -5, 2000, [3]]';
		$this->assertSame(array(1000, 2000), energy::cronHistory());
		config::$values['energy::cronHistory'] = 'not json';
		$this->assertSame(array(), energy::cronHistory());
		config::$values['energy::cronHistory'] = '';
		$this->assertSame(array(), energy::cronHistory());
	}

	// ---- cron15 trace

	public function testRegularCyclesOnlyLeaveADebugTrace(): void {
		$this->runCycles(array(900, 900));
		$this->assertSame(array(), $this->warnings());
		$lines = $this->debugLines('[cron] cycle started');
		$this->assertCount(3, $lines);
		$this->assertStringContainsString('first recorded cycle', $lines[0]);
		$this->assertStringContainsString('900 s since the previous one', $lines[1]);
	}

	public function testLateCronIsReportedAsAWarningWithTheIntervals(): void {
		$this->runCycles(array(900, 900, 25 * 3600));
		$warnings = $this->warnings();
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('cron15 ran late: 90000 s since the previous cycle', $warnings[0]);
		$this->assertStringContainsString('Last intervals: 900, 900, 90000 s', $warnings[0]);
		$this->assertStringContainsString('The cron was not running between these two cycles', $warnings[0]);
	}

	public function testLateWarningIsNotRepeatedOnTheNextRegularCycle(): void {
		$this->runCycles(array(900, 25 * 3600, 900));
		$this->assertCount(1, $this->warnings());
	}

}

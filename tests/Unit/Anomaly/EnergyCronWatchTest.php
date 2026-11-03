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
		$result = energyAnomalyCron::record(1000);
		$this->assertNull($result['interval']);
		$this->assertSame(array(), $result['intervals']);
		$this->assertFalse($result['late']);
	}

	public function testIntervalsAreComputedBetweenCycles(): void {
		energyAnomalyCron::record(1000);
		energyAnomalyCron::record(1900);
		$result = energyAnomalyCron::record(2810);
		$this->assertSame(910, $result['interval']);
		$this->assertSame(array(900, 910), $result['intervals']);
		$this->assertFalse($result['late']);
	}

	public function testLateCycleIsDetected(): void {
		energyAnomalyCron::record(1000);
		energyAnomalyCron::record(1900);
		$result = energyAnomalyCron::record(1900 + 25 * 3600);
		$this->assertSame(90000, $result['interval']);
		$this->assertTrue($result['late']);
	}

	public function testExactlyTheToleratedGapIsNotLate(): void {
		energyAnomalyCron::record(1000);
		$this->assertFalse(energyAnomalyCron::record(1000 + 1800)['late']);
		energyAnomalyCron::record(10000);
		$this->assertTrue(energyAnomalyCron::record(10000 + 1801)['late']);
	}

	public function testOnlyTheLastFiveIntervalsAreReported(): void {
		$t = 1000;
		for ($i = 0; $i < 10; $i++) {
			$t += 900;
			$result = energyAnomalyCron::record($t);
		}
		$this->assertCount(5, $result['intervals']);
	}

	public function testHistoryIsBounded(): void {
		$t = 1000;
		for ($i = 0; $i < 40; $i++) {
			$t += 900;
			energyAnomalyCron::record($t);
		}
		$this->assertCount(12, energyAnomalyCron::history());
	}

	public function testHistorySurvivesAsStoredConfiguration(): void {
		energyAnomalyCron::record(1000);
		energyAnomalyCron::record(1900);
		$this->assertSame(array(1000, 1900), energyAnomalyCron::history());
	}

	public function testClockGoingBackwardsRestartsTheHistory(): void {
		energyAnomalyCron::record(5000);
		energyAnomalyCron::record(5900);
		$result = energyAnomalyCron::record(1000);
		$this->assertNull($result['interval']);
		$this->assertFalse($result['late']);
		$this->assertSame(array(1000), energyAnomalyCron::history());
	}

	public function testSameSecondIsNotRecordedTwice(): void {
		energyAnomalyCron::record(1000);
		$result = energyAnomalyCron::record(1000);
		$this->assertNull($result['interval']);
		$this->assertSame(array(1000), energyAnomalyCron::history());
	}

	public function testGarbageInTheStoredHistoryIsIgnored(): void {
		config::$values['energy::cronHistory'] = '[1000, "abc", null, -5, 2000, [3]]';
		$this->assertSame(array(1000, 2000), energyAnomalyCron::history());
		config::$values['energy::cronHistory'] = 'not json';
		$this->assertSame(array(), energyAnomalyCron::history());
		config::$values['energy::cronHistory'] = '';
		$this->assertSame(array(), energyAnomalyCron::history());
	}

	// ---- cron15 trace

	public function testRegularCyclesOnlyLeaveADebugTrace(): void {
		log::$level = 100;
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

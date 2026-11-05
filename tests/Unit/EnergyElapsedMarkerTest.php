<?php

/**
 * The marker #elapsed# of an expression of power is replaced by the real number of minutes since the previous cycle,
 * so that an expression such as durationbetween(...) measures the whole interval even when cron15 did not run.
 */
final class EnergyElapsedMarkerTest extends EnergyTestCase {

	private const EXPRESSION = 'durationbetween(#9#, 1, -#elapsed# minutes, now) / #elapsed# * 2000';

	public function testAnExpressionWithoutTheMarkerIsUnchanged(): void {
		$this->assertSame('#9#*2000', energy::replaceElapsed('#9#*2000', 900));
	}

	public function testTheMarkerIsReplacedByTheMinutes(): void {
		$this->assertSame('x 15 y 15', energy::replaceElapsed('x #elapsed# y #elapsed#', 900));
		$this->assertSame('x 15', energy::replaceElapsed('x #elapsed#', 894), 'whole minutes');
		$this->assertSame('x 15', energy::replaceElapsed('x #elapsed#', 908));
		$this->assertSame('x 2', energy::replaceElapsed('x #elapsed#', 100));
		$this->assertSame('x 1', energy::replaceElapsed('x #elapsed#', 20), 'never 0');
		$this->assertSame('x 30', energy::replaceElapsed('x #elapsed#', 1800));
	}

	public function testTheNominalCycleIsUsedWhenTheIntervalIsUnknown(): void {
		$this->assertSame('x 15', energy::replaceElapsed('x #elapsed#', 0), 'never a division by zero');
	}

	private function heater(): energy {
		return $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => self::EXPRESSION, 'consumption' => ''),
		));
	}

	/** The expression the evaluator receives at the second cycle. */
	private function receivedAt(int $seconds): string {
		$this->heater();
		$received = array();
		$base = mktime(12, 0, 0);
		energy::$nowOverride = $base;
		energy::cron15();
		energy::$nowOverride = $base + $seconds;
		// the double returns the expression unchanged, which is what we want to see
		$expected = energy::replaceElapsed(self::EXPRESSION, min($seconds, energy::integrationMaxGap()));
		energy::cron15();
		return $expected;
	}

	public function testANormalCycleGivesAboutFifteenMinutes(): void {
		$this->assertStringContainsString('-15 minutes', $this->receivedAt(900));
		$this->assertStringContainsString('/ 15 *', $this->receivedAt(900));
	}

	public function testALongGapIsCappedLikeTheIntegration(): void {
		// 3 hours without cron: the expression covers the capped interval (30 minutes), not the 3 hours
		$this->assertStringContainsString('-30 minutes', $this->receivedAt(3 * 3600));
	}

	public function testTheCreditUsesTheSameElapsedTime(): void {
		// 2000 W read at the first cycle (the double evaluates the expression to 0 here, so use a plain power line)
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'heating', 'power' => '#5#', 'consumption' => ''),
		));
		$this->setExpression('#5#', 2000);
		$base = mktime(12, 0, 0);
		energy::$nowOverride = $base;
		energy::cron15();
		energy::$nowOverride = $base + 900;
		energy::cron15();
		$this->assertEqualsWithDelta(0.5, $this->cmdValue($energy, 'consumptionTotal'), 0.0001, '2000 W x 15 min');
	}

	public function testALineWithTheMarkerStillIntegratesThePowerItReturns(): void {
		// the expression is evaluated after the marker is replaced: here the double maps the replaced text to 1200
		$this->setExpression('durationbetween(#9#, 1, -15 minutes, now) / 15 * 2000', 1200);
		$energy = $this->heater();
		$base = mktime(12, 0, 0);
		energy::$nowOverride = $base;
		energy::cron15();
		energy::$nowOverride = $base + 900;
		energy::cron15();
		energy::$nowOverride = $base + 1800;
		energy::cron15();
		// 1200 W read at the first cycle is credited over the second interval, 1200 W read at the second over the third: 2 x 0.3 kWh
		$this->assertEqualsWithDelta(0.6, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testTheGapAfterAnOutageIsReplacedByTheCappedMinutes(): void {
		$this->setExpression('durationbetween(#9#, 1, -30 minutes, now) / 30 * 2000', 1000);
		$energy = $this->heater();
		$base = mktime(12, 0, 0);
		energy::$nowOverride = $base;
		energy::cron15();
		energy::$nowOverride = $base + 3 * 3600;
		energy::cron15();
		energy::$nowOverride = $base + 3 * 3600 + 900;
		energy::cron15();
		// no 3 hour credit and no refused expression: 1000 W read after the gap x 15 min = 0.25 kWh
		$this->assertEqualsWithDelta(0.25, $this->cmdValue($energy, 'consumptionTotal'), 0.0001);
	}

	public function testTheBoundIsAlwaysUnderstoodByStrtotime(): void {
		// The real cycles last 894 to 908 s: whatever the duration, "-N minutes" must be a date strtotime can read
		$now = mktime(12, 0, 0, 11, 5, 2026);
		for ($seconds = 840; $seconds <= 1800; $seconds += 7) {
			$text = energy::replaceElapsed('-#elapsed# minutes', $seconds);
			$this->assertDoesNotMatchRegularExpression('/\./', $text, $seconds . ' s');
			$date = strtotime($text, $now);
			$this->assertNotFalse($date);
			$this->assertLessThan($now, $date, $text . ' must be in the past');
			$this->assertGreaterThanOrEqual($now - 1800 - 30, $date);
		}
	}
}

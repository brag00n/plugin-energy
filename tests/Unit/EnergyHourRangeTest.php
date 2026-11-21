<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnergyHourRangeTest extends TestCase {

	public static function rangeProvider(): array {
		return array(
			'inside a range crossing midnight, evening' => array(2230, 2200, 600, true),
			'inside a range crossing midnight, morning' => array(5, 2200, 600, true),
			'exactly midnight in a range crossing it' => array(0, 2200, 600, true),
			'range start bound is included' => array(2200, 2200, 600, true),
			'range end bound is included' => array(600, 2200, 600, true),
			'just after the end of a range crossing midnight' => array(601, 2200, 600, false),
			'midday outside a range crossing midnight' => array(1200, 2200, 600, false),
			'inside a normal range' => array(1230, 1200, 1400, true),
			'outside a normal range' => array(1500, 1200, 1400, false),
			'unset range' => array(1200, -1, -1, false),
			'empty range (start equals end)' => array(1200, 1200, 1200, false),
			'hour given as string like date(Gi)' => array('2230', 2200, 600, true),
		);
	}

	#[DataProvider('rangeProvider')]
	public function testIsInHourRange($hour, $start, $end, bool $expected): void {
		$this->assertSame($expected, energy::isInHourRange($hour, $start, $end));
	}
}

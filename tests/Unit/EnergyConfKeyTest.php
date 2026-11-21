<?php

use PHPUnit\Framework\Attributes\DataProvider;

final class EnergyConfKeyTest extends EnergyTestCase {

	public static function keyProvider(): array {
		return array(
			'power only' => array(array('power' => '#12#', 'consumption' => ''), 0, '#12#'),
			'consumption only' => array(array('power' => '', 'consumption' => '#34#'), 0, '#34#'),
			'power and consumption' => array(array('power' => '#12#', 'consumption' => '#34#'), 0, '#12##34#'),
			'several commands in an expression' => array(array('power' => '#1# + #2#', 'consumption' => ''), 0, '#1##2#'),
			'text around the command is ignored' => array(array('power' => 'abs(#7#) * 2', 'consumption' => ''), 0, '#7#'),
			'nothing falls back to the position' => array(array('power' => '', 'consumption' => ''), 3, 3),
			'missing keys fall back to the position' => array(array(), 5, 5),
			'expression without command falls back to the position' => array(array('power' => '230', 'consumption' => ''), 2, 2),
		);
	}

	#[DataProvider('keyProvider')]
	public function testGetConfEnergyKey(array $conf, int $position, $expected): void {
		$this->assertSame($expected, energy::getConfEnergyKey($conf, $position));
	}

	public function testPositionDefaultsToZero(): void {
		$this->assertSame(0, energy::getConfEnergyKey(array()));
	}
}

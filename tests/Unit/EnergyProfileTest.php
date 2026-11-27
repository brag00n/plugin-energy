<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

/**
 * The profiles (energyProfile) and the recalculation of the consumptions of the equipments with them (energyProfileUpdate).
 * The history is a small in-memory double of the tables history and historyArch.
 */
final class EnergyProfileTest extends EnergyTestCase {

	/** @var string[] */
	private $trash = array();
	/** @var array history by table: [cmd_id, datetime, value] */
	private $store = array();
	/** @var energy */
	private $counter;

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
		$base = sys_get_temp_dir() . '/energy_profile_' . bin2hex(random_bytes(4));
		energyCatchup::$baseDir = $base;
		$this->trash[] = $base;
		energyRuntime::$now = strtotime('2026-11-26 12:00:00');
		$this->store = array('history' => array(), 'historyArch' => array());
		DB::$handler = function ($sql, $values) {
			return $this->database($sql, $values);
		};
		$this->counter = $this->makeEnergy('electricity', array(), array('totalCounter' => 1));
		new jeeObject(1, 'home');
	}

	protected function tearDown(): void {
		energyCatchup::$baseDir = null;
		DB::$handler = null;
		foreach ($this->trash as $path) {
			$this->remove($path);
		}
		$this->trash = array();
		parent::tearDown();
	}

	private function remove($path): void {
		if (is_dir($path)) {
			foreach (scandir($path) as $entry) {
				if ($entry != '.' && $entry != '..') {
					$this->remove($path . '/' . $entry);
				}
			}
			@rmdir($path);
		} elseif (is_file($path)) {
			@unlink($path);
		}
	}

	private function database($sql, $values) {
		if (preg_match('/^SELECT cmd_id, `datetime`, value FROM (\w+) WHERE cmd_id IN \(([\d,]+)\)/', $sql, $m)) {
			$ids = array_map('intval', explode(',', $m[2]));
			$out = array();
			foreach ($this->store[$m[1]] as $row) {
				if (in_array($row[0], $ids, true) && $row[1] >= $values['start'] && $row[1] <= $values['end']) {
					$out[] = array('cmd_id' => $row[0], 'datetime' => $row[1], 'value' => $row[2]);
				}
			}
			return $out;
		}
		if (preg_match('/^DELETE FROM (\w+) WHERE cmd_id=:cmd_id/', $sql, $m)) {
			$this->store[$m[1]] = array_values(array_filter($this->store[$m[1]], function ($row) use ($values) {
				return !($row[0] === $values['cmd_id'] && $row[1] >= $values['start'] && $row[1] <= $values['end']);
			}));
			return array();
		}
		if (preg_match('/^REPLACE INTO (\w+) /', $sql, $m)) {
			$this->store[$m[1]] = array_values(array_filter($this->store[$m[1]], function ($row) use ($values) {
				return !($row[0] === $values['cmd_id'] && $row[1] === $values['datetime']);
			}));
			$this->store[$m[1]][] = array($values['cmd_id'], $values['datetime'], $values['value']);
			return array();
		}
		if (strpos($sql, 'MAX(CAST(value as DOUBLE))') !== false) {
			$days = array();
			foreach ($this->store as $rows) {
				foreach ($rows as $row) {
					if ($row[0] !== $values['cmd_id']) {
						continue;
					}
					$moment = date('Y-m-d H:i:s', strtotime($row[1]) - 240);
					if ((isset($values['startTime']) && $moment < $values['startTime']) || (isset($values['endTime']) && $moment > $values['endTime'])) {
						continue;
					}
					$day = substr($moment, 0, 10);
					$days[$day] = isset($days[$day]) ? max($days[$day], floatval($row[2])) : floatval($row[2]);
				}
			}
			ksort($days);
			$out = array();
			foreach ($days as $day => $value) {
				$out[] = array('0' => $day, '1' => $value);
			}
			return $out;
		}
		return null;
	}

	/** An equipment of the profile (a heater by default). */
	private function equipment(string $category = 'heating'): energy {
		return $this->makeEnergy('electricity', array(array('category' => $category, 'power' => '1000', 'consumption' => '')));
	}

	private function put(energy $energy, string $logicalId, string $date, float $value): void {
		$this->store['history'][] = array($energy->getCmd(null, $logicalId)->getId(), $date . ' 12:00:00', strval($value));
	}

	/** The value of a command on a day, or null. */
	private function read(energy $energy, string $logicalId, string $date) {
		$values = energyProfile::byDate($energy->getCmd(null, $logicalId)->getHistoryEnergy($date . ' 00:00:00', $date));
		return isset($values[$date]) ? $values[$date] : null;
	}

	private function rowsOf(energy $energy, string $logicalId): int {
		$id = $energy->getCmd(null, $logicalId)->getId();
		$n = 0;
		foreach ($this->store as $rows) {
			foreach ($rows as $row) {
				if ($row[0] === $id) {
					$n++;
				}
			}
		}
		return $n;
	}

	/** The total counter of the days: date => kWh (cost 0.2 per kWh). */
	private function totals(array $days): void {
		foreach ($days as $date => $kwh) {
			$this->put($this->counter, 'consumptionTotal', $date, $kwh);
			$this->put($this->counter, 'cost', $date, $kwh * 0.2);
		}
	}

	/** The share of HC of every equipment, per season, and the tariff: HP/HC (0.30 / 0.20) or fixed (0.25). */
	private function hcProfile(array $cold, array $hot = array(), bool $variable = true): void {
		config::$values['energy::profileHc'] = json_encode(array('cold' => $cold, 'hot' => $hot));
		config::$values['energy::rateMode'] = $variable ? 'variable' : 'fixed';
		config::$values['energy::rateHp'] = '0.30';
		config::$values['energy::rateHc'] = '0.20';
		config::$values['energy::rate'] = '0.25';
	}

	private function profile(array $cold = array(), array $hot = array(), array $fixed = array(), array $coldMonths = array(11, 12, 1, 2, 3, 4)): void {
		config::$values['energy::profileSeasons'] = json_encode(array('cold' => $coldMonths));
		config::$values['energy::profile'] = json_encode(array('cold' => $cold, 'hot' => $hot));
		config::$values['energy::profileFixedKwh'] = json_encode($fixed);
	}

	private function recalc(string $from, string $to, array $options = array()): array {
		$id = '';
		$result = array();
		foreach (array_keys(energyProfileUpdate::STEPS) as $step) {
			$result = energyProfileUpdate::runStep($id, $step, $from, $to, $options);
			$id = $result['id'];
			if (!$result['ok']) {
				return $result;
			}
		}
		return $result;
	}

	/* -------------------------------------------------------------- the seasons and the profile */

	public function testTheSeasonOfADateFollowsTheColdMonths(): void {
		$this->assertSame('cold', energyProfile::seasonOf('2026-01-15'));
		$this->assertSame('cold', energyProfile::seasonOf('2025-11-01'));
		$this->assertSame('hot', energyProfile::seasonOf('2025-05-01'));
		$this->assertSame('hot', energyProfile::seasonOf('2025-10-31 12:00:00'));
		$this->profile(array(), array(), array(), array(6, 7));
		$this->assertSame('cold', energyProfile::seasonOf('2025-06-10'));
		$this->assertSame('hot', energyProfile::seasonOf('2025-01-10'));
	}

	public function testNothingIsConfiguredWithoutAProfile(): void {
		$this->assertFalse(energyProfile::configured());
	}

	public function testTheSharesKeepTheRemainderAsUnmeasured(): void {
		$a = $this->equipment();
		$b = $this->equipment();
		$this->profile(array((string) $a->getId() => 30, (string) $b->getId() => 20));
		$shares = energyProfile::shares('cold');
		$this->assertEqualsWithDelta(50, $shares['unmeasured'], 0.001);
		$this->assertEqualsWithDelta(30, $shares[(string) $a->getId()], 0.001);
	}

	public function testSharesAboveOneHundredAreScaledDown(): void {
		$a = $this->equipment();
		$b = $this->equipment();
		$this->profile(array((string) $a->getId() => 90, (string) $b->getId() => 30));
		$shares = energyProfile::shares('cold');
		$this->assertEqualsWithDelta(0, $shares['unmeasured'], 0.001);
		$this->assertEqualsWithDelta(75, $shares[(string) $a->getId()], 0.001);
	}

	public function testTheValueOfADayIsFixedPlusVariablePlusUnmeasuredEqualToTheTotal(): void {
		$fridge = $this->equipment('electrical');
		$heater = $this->equipment();
		$this->profile(array((string) $heater->getId() => 40), array(), array((string) $fridge->getId() => 1.08));
		$values = energyProfile::dayValues('2025-11-11', 50.0);
		$this->assertEqualsWithDelta(1.08, $values[(string) $fridge->getId()], 0.0001);
		// 40 % of what the fixed equipment leaves: 40 % of 48.92
		$this->assertEqualsWithDelta(0.4 * 48.92, $values[(string) $heater->getId()], 0.0001);
		$unmeasured = energyProfile::shares('cold')['unmeasured'] / 100 * 48.92;
		$this->assertEqualsWithDelta(50.0, array_sum($values) + $unmeasured, 0.0001);
	}

	public function testTheFixedValuesAreScaledDownWhenTheyExceedTheTotal(): void {
		$fridge = $this->equipment('electrical');
		$box = $this->equipment('multimedia');
		$this->profile(array(), array(), array((string) $fridge->getId() => 3.0, (string) $box->getId() => 1.0));
		$values = energyProfile::dayValues('2025-11-11', 2.0);
		$this->assertEqualsWithDelta(2.0, array_sum($values), 0.0001);
		$this->assertEqualsWithDelta(1.5, $values[(string) $fridge->getId()], 0.0001);
	}

	public function testTheProfileOfTheDayIsTheOneOfItsSeason(): void {
		$heater = $this->equipment();
		$id = (string) $heater->getId();
		$this->profile(array($id => 40), array($id => 10));
		$this->assertEqualsWithDelta(20.0, energyProfile::dayValues('2025-11-11', 50.0)[$id], 0.0001);
		$this->assertEqualsWithDelta(5.0, energyProfile::dayValues('2025-06-11', 50.0)[$id], 0.0001);
	}

	public function testTheProfileFollowsTheIdAndNotTheNameOfAnEquipment(): void {
		$heater = $this->equipment();
		$id = (string) $heater->getId();
		energyProfile::save(array(11, 12, 1), array('cold' => array($id => '40')), array(), '50', '100');
		$heater->name = 'Radiateur salon (renamed)';
		energyProfile::save(array(11, 12, 1), array('cold' => array($id => '40')), array(), '50', '100');
		$this->assertCount(1, energyProfile::profile()['cold']);
		$this->assertEquals(array($id), array_keys(energyProfile::profile()['cold']), 'array keys of digits are integers in PHP');
		$this->assertEquals(array($id), energyProfile::profileIds());
	}

	public function testAnEquipmentThatNoLongerExistsDoesNotWeighOnTheProfile(): void {
		$heater = $this->equipment();
		$this->profile(array((string) $heater->getId() => 30, '99999' => 50), array(), array('88888' => 1.0));
		$this->assertEquals(array((string) $heater->getId()), array_keys(energyProfile::profile()['cold']));
		$this->assertSame(array(), energyProfile::fixed());
		$this->assertEqualsWithDelta(70, energyProfile::shares('cold')['unmeasured'], 0.001);
	}

	public function testSavingValidatesAndKeepsTheValues(): void {
		$heater = $this->equipment();
		$fridge = $this->equipment('electrical');
		$h = (string) $heater->getId();
		$f = (string) $fridge->getId();
		energyProfile::save(array(11, 12, 1), array('cold' => array($h => '60', $f => '30'), 'hot' => array($h => '10,5')), array($f => '1,08'), '40', '90');
		$this->assertEqualsWithDelta(60, energyProfile::profile()['cold'][$h], 0.001);
		$this->assertEqualsWithDelta(10.5, energyProfile::profile()['hot'][$h], 0.001);
		$this->assertEqualsWithDelta(1.08, energyProfile::fixed()[$f], 0.001);
		$this->assertArrayNotHasKey($f, energyProfile::profile()['cold'], 'a fixed equipment has no share');
		$this->assertSame(array(1, 11, 12), energyProfile::coldMonths());
		$this->assertEqualsWithDelta(0.4, energyProfile::toleranceFixed(), 0.0001);
		$this->assertEqualsWithDelta(0.9, energyProfile::toleranceVariable(), 0.0001);
	}

	public function testSavingRefusesInvalidValues(): void {
		$heater = $this->equipment();
		$h = (string) $heater->getId();
		foreach (array(
			array(array(13), array(), array(), '50', '100'),
			array(array(1), array('cold' => array($h => '150')), array(), '50', '100'),
			array(array(1), array('cold' => array($h => '40')), array($h => '-2'), '50', '100'),
			array(array(1), array(), array(), 'abc', '100'),
		) as $arguments) {
			try {
				energyProfile::save(...$arguments);
				$this->fail('an invalid value was accepted: ' . json_encode($arguments));
			} catch (Exception $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testAFixedEquipmentWithoutAValueIsRefusedAndNotTurnedIntoAVariableOne(): void {
		$box = $this->equipment('multimedia');
		$id = (string) $box->getId();
		try {
			energyProfile::save(array(1), array(), array($id => ''), '50', '100');
			$this->fail('a fixed equipment without a value was accepted');
		} catch (Exception $e) {
			$this->assertStringContainsString($box->getHumanName(), $e->getMessage(), 'the message names the equipment');
		}
		$this->assertSame(array(), energyProfile::fixed());
		energyProfile::save(array(1), array(), array($id => '0,3'), '50', '100');
		$this->assertEqualsWithDelta(0.3, energyProfile::fixed()[$id], 0.0001);
	}

	public function testTheTolerancesHaveTheirDefaults(): void {
		$this->assertEqualsWithDelta(0.5, energyProfile::toleranceFixed(), 0.0001);
		$this->assertEqualsWithDelta(1.0, energyProfile::toleranceVariable(), 0.0001);
	}

	/* -------------------------------------------------------------- the three kinds of day */

	public function testABlankDayHasNoValueOrZero(): void {
		$this->assertSame('blank', energyProfileUpdate::status(null, 10.0, 1.0, false));
		$this->assertSame('blank', energyProfileUpdate::status(0.0, 10.0, 1.0, false));
	}

	public function testADayAboveTheProfilePlusTheToleranceIsAberrant(): void {
		// fixed: 50 % of tolerance, a value of 1.08 exceeds at 1.62
		$this->assertSame('filled', energyProfileUpdate::status(1.5, 1.08, 0.5, false));
		$this->assertSame('aberrant', energyProfileUpdate::status(1.7, 1.08, 0.5, false));
		// variable: 100 % of tolerance, twice its value is still plausible
		$this->assertSame('filled', energyProfileUpdate::status(19.9, 10.0, 1.0, false));
		$this->assertSame('aberrant', energyProfileUpdate::status(20.5, 10.0, 1.0, false));
	}

	public function testADayBelowTheProfileIsFilledNotAberrant(): void {
		$this->assertSame('filled', energyProfileUpdate::status(0.2, 10.0, 1.0, false));
	}

	public function testWhenTheEquipmentsAddUpToMoreThanTheTotalEveryDayIsAberrant(): void {
		$this->assertSame('aberrant', energyProfileUpdate::status(3.0, 10.0, 1.0, true));
		$this->assertSame('blank', energyProfileUpdate::status(0.0, 10.0, 1.0, true), 'a blank day stays blank');
	}

	public function testTheOptionsDefaultToBlankAndAberrant(): void {
		$this->assertSame(array('blank' => true, 'aberrant' => true, 'filled' => false), energyProfileUpdate::options(array()));
		$this->assertSame(array('blank' => false, 'aberrant' => true, 'filled' => true), energyProfileUpdate::options(array('blank' => 0, 'filled' => '1')));
	}

	/* -------------------------------------------------------------- the analysis of the modal */

	private function house(): array {
		$heater = $this->equipment();
		$fridge = $this->equipment('electrical');
		$this->profile(array((string) $heater->getId() => 40), array(), array((string) $fridge->getId() => 1.08));
		return array($heater, $fridge);
	}

	public function testADayWithoutATotalIsIgnored(): void {
		$this->house();
		$this->totals(array('2025-11-10' => 50.0));
		$preview = energyProfileUpdate::preview('2025-11-10', '2025-11-12', array('blank' => 1));
		$this->assertSame(1, $preview['summary']['days']);
		$this->assertSame(2, $preview['summary']['noTotal']);
		$this->assertSame(array('2025-11-10'), array_values(array_unique(array_column($preview['items'], 'date'))));
	}

	public function testTheAnalysisWritesNothing(): void {
		list($heater) = $this->house();
		$this->totals(array('2025-11-10' => 50.0));
		energyProfileUpdate::preview('2025-11-10', '2025-11-10', array('blank' => 1));
		$this->assertSame(0, $this->rowsOf($heater, 'consumptionTotal'));
		$this->assertCount(0, energyCatchup::listBackups());
	}

	public function testBlankAberrantAndFilledDaysAreSortedAndOnlyTheChosenOnesAreRewritten(): void {
		list($heater) = $this->house();
		$this->totals(array('2025-11-10' => 50.0, '2025-11-11' => 50.0, '2025-11-12' => 50.0));
		// 10: blank, 11: aberrant (4861 kWh), 12: filled (20 kWh, within 100 % of 19.57)
		$this->put($heater, 'consumptionTotal', '2025-11-11', 4861.5);
		$this->put($heater, 'consumptionTotal', '2025-11-12', 20.0);
		$preview = energyProfileUpdate::preview('2025-11-10', '2025-11-12', array());
		$byDate = array();
		foreach ($preview['items'] as $item) {
			if ($item['id'] == $heater->getId()) {
				$byDate[$item['date']] = array($item['status'], $item['write']);
			}
		}
		$this->assertSame(array('blank', true), $byDate['2025-11-10']);
		$this->assertSame(array('aberrant', true), $byDate['2025-11-11'], 'the equipments add up to more than the total of the day');
		$this->assertSame(array('filled', false), $byDate['2025-11-12']);
		$withFilled = energyProfileUpdate::preview('2025-11-12', '2025-11-12', array('filled' => 1));
		$written = array_filter($withFilled['items'], function ($item) use ($heater) {
			return $item['id'] == $heater->getId() && $item['write'];
		});
		$this->assertCount(1, $written, 'ticking "filled" rewrites it');
	}

	public function testAnEquipmentOutOfTheProfileIsNeverTouched(): void {
		$this->house();
		$stranger = $this->equipment();
		$this->totals(array('2025-11-10' => 50.0));
		$this->put($stranger, 'consumptionTotal', '2025-11-10', 9999.0);
		$this->recalc('2025-11-10', '2025-11-10', array('blank' => 1, 'aberrant' => 1));
		$this->assertEqualsWithDelta(9999.0, $this->read($stranger, 'consumptionTotal', '2025-11-10'), 0.0001);
	}

	public function testTheRangeMustEndYesterdayAtTheLatest(): void {
		$this->house();
		$this->expectException(Exception::class);
		energyProfileUpdate::preview('2026-11-20', '2026-11-26', array());
	}

	public function testNothingCanBeRecalculatedWithoutAProfile(): void {
		$this->totals(array('2025-11-10' => 50.0));
		$this->expectException(Exception::class);
		energyProfileUpdate::preview('2025-11-10', '2025-11-10', array());
	}

	/* -------------------------------------------------------------- the writing */

	public function testTheDaysAreRewrittenWithOneRowAtNoonAndTheTotalIsRespected(): void {
		list($heater, $fridge) = $this->house();
		$this->totals(array('2025-11-11' => 50.0));
		$this->put($heater, 'consumptionTotal', '2025-11-11', 4861.5);
		$this->put($heater, 'consumptionHeating', '2025-11-11', 4861.5);
		$this->put($heater, 'consumptionTotal', '2025-11-11', 4850.0);
		$result = $this->recalc('2025-11-11', '2025-11-11', array('blank' => 1, 'aberrant' => 1));
		$this->assertTrue($result['ok'], $result['message']);
		$this->assertTrue($result['done']);
		$this->assertEqualsWithDelta(0.4 * 48.92, $this->read($heater, 'consumptionTotal', '2025-11-11'), 0.001);
		$this->assertEqualsWithDelta(0.4 * 48.92, $this->read($heater, 'consumptionHeating', '2025-11-11'), 0.001, 'its category follows');
		$this->assertEqualsWithDelta(1.08, $this->read($fridge, 'consumptionTotal', '2025-11-11'), 0.001);
		$this->assertSame(1, $this->rowsOf($heater, 'consumptionTotal'), 'the rows of the day were replaced by one');
		// its cost is its share of the cost of the counter
		$this->assertEqualsWithDelta(0.4 * 48.92 / 50.0 * 10.0, $this->read($heater, 'cost', '2025-11-11'), 0.001);
	}

	public function testADayThatIsNotRewrittenKeepsItsRowsUntouched(): void {
		list($heater) = $this->house();
		$this->totals(array('2025-11-12' => 50.0));
		$this->put($heater, 'consumptionTotal', '2025-11-12', 12.0);
		$this->put($heater, 'consumptionTotal', '2025-11-12', 20.0);
		$this->recalc('2025-11-12', '2025-11-12', array('blank' => 1, 'aberrant' => 1));
		$this->assertEqualsWithDelta(20.0, $this->read($heater, 'consumptionTotal', '2025-11-12'), 0.0001);
		$this->assertSame(2, $this->rowsOf($heater, 'consumptionTotal'));
	}

	public function testABackupIsWrittenAndReadBackBeforeAnyWrite(): void {
		list($heater) = $this->house();
		$this->totals(array('2025-11-11' => 50.0));
		$this->put($heater, 'consumptionTotal', '2025-11-11', 4861.5);
		$this->recalc('2025-11-11', '2025-11-11', array('blank' => 1, 'aberrant' => 1));
		$backups = energyCatchup::listBackups();
		$this->assertCount(1, $backups);
		$this->assertSame('profile', $backups[0]['kind']);
		$data = json_decode(file_get_contents(energyCatchup::backupPath($backups[0]['name'])), true);
		$this->assertSame('profile', $data['kind']);
		$this->assertContains(array('history', $heater->getCmd(null, 'consumptionTotal')->getId(), '2025-11-11 12:00:00', '4861.5'), $data['rows']);
	}

	public function testNothingIsWrittenWhenTheBackupCannotBeWritten(): void {
		list($heater) = $this->house();
		$this->totals(array('2025-11-11' => 50.0));
		$this->put($heater, 'consumptionTotal', '2025-11-11', 4861.5);
		// the backup folder is a file: the backup cannot be written
		$dir = energyCatchup::dir('backup');
		foreach ((array) glob($dir . '/*') as $file) {
			@unlink($file);
		}
		@unlink($dir . '/.htaccess');
		rmdir($dir);
		file_put_contents($dir, 'not a folder');
		$result = $this->recalc('2025-11-11', '2025-11-11', array('blank' => 1, 'aberrant' => 1));
		$this->assertFalse($result['ok']);
		$this->assertEqualsWithDelta(4861.5, $this->read($heater, 'consumptionTotal', '2025-11-11'), 0.0001, 'nothing was changed');
		unlink($dir);
	}

	public function testRestoringTheBackupPutsTheHistoryBack(): void {
		list($heater, $fridge) = $this->house();
		$this->totals(array('2025-11-11' => 50.0));
		$this->put($heater, 'consumptionTotal', '2025-11-11', 4861.5);
		$this->put($heater, 'consumptionHeating', '2025-11-11', 4861.5);
		$result = $this->recalc('2025-11-11', '2025-11-11', array('blank' => 1, 'aberrant' => 1));
		$this->assertEqualsWithDelta(0.4 * 48.92, $this->read($heater, 'consumptionTotal', '2025-11-11'), 0.001);
		energyCatchup::restore($result['backup']);
		$this->assertEqualsWithDelta(4861.5, $this->read($heater, 'consumptionTotal', '2025-11-11'), 0.0001);
		$this->assertEqualsWithDelta(4861.5, $this->read($heater, 'consumptionHeating', '2025-11-11'), 0.0001);
		$this->assertNull($this->read($fridge, 'consumptionTotal', '2025-11-11'), 'what was blank is blank again');
	}

	public function testTheVerificationDetectsAValueThatDiffers(): void {
		list($heater) = $this->house();
		$this->totals(array('2025-11-11' => 50.0));
		$result = energyProfileUpdate::runStep('', 'check', '2025-11-11', '2025-11-11', array('blank' => 1));
		$id = $result['id'];
		energyProfileUpdate::runStep($id, 'backup');
		energyProfileUpdate::runStep($id, 'write');
		// something rewrites the day between the writing and the verification
		$this->put($heater, 'consumptionTotal', '2025-11-11', 777.0);
		$verify = energyProfileUpdate::runStep($id, 'verify');
		$this->assertFalse($verify['ok']);
		$this->assertStringContainsString('Restaurez la sauvegarde', $verify['message']);
	}

	public function testTheStepsMustFollowTheirOrder(): void {
		$this->house();
		$this->totals(array('2025-11-11' => 50.0));
		$result = energyProfileUpdate::runStep('', 'check', '2025-11-11', '2025-11-11', array('blank' => 1));
		$this->expectException(Exception::class);
		energyProfileUpdate::runStep($result['id'], 'write');
	}

	public function testTheSeasonOfEachDayPicksItsProfileInTheWriting(): void {
		$heater = $this->equipment();
		$id = (string) $heater->getId();
		$this->profile(array($id => 40), array($id => 10));
		$this->totals(array('2025-04-30' => 50.0, '2025-05-01' => 50.0));
		$this->recalc('2025-04-30', '2025-05-01', array('blank' => 1));
		$this->assertEqualsWithDelta(20.0, $this->read($heater, 'consumptionTotal', '2025-04-30'), 0.001, 'cold');
		$this->assertEqualsWithDelta(5.0, $this->read($heater, 'consumptionTotal', '2025-05-01'), 0.001, 'hot');
	}

	/* -------------------------------------------------------------- HC / HP / fixed rate */

	public function testWithAnHpHcTariffTheHcAndHpFollowTheShareOfTheEquipmentAndTheCostFollowsThem(): void {
		list($heater) = $this->house();
		$this->hcProfile(array((string) $heater->getId() => 30));
		$this->totals(array('2025-11-11' => 50.0));
		$this->recalc('2025-11-11', '2025-11-11', array('blank' => 1));
		$value = 0.4 * 48.92;
		$this->assertEqualsWithDelta($value * 0.3, $this->read($heater, 'consumptionHc', '2025-11-11'), 0.001);
		$this->assertEqualsWithDelta($value * 0.7, $this->read($heater, 'consumptionHp', '2025-11-11'), 0.001);
		$this->assertEqualsWithDelta($value * 0.3 * 0.20 + $value * 0.7 * 0.30, $this->read($heater, 'cost', '2025-11-11'), 0.001);
	}

	public function testWithAFixedTariffThereIsNoSplitAndTheCostIsTheConsumptionTimesTheRate(): void {
		list($heater) = $this->house();
		$this->hcProfile(array((string) $heater->getId() => 30), array(), false);
		$this->totals(array('2025-11-11' => 50.0));
		$this->recalc('2025-11-11', '2025-11-11', array('blank' => 1));
		$value = 0.4 * 48.92;
		$this->assertNull($this->read($heater, 'consumptionHc', '2025-11-11'), 'no split with a fixed tariff');
		$this->assertNull($this->read($heater, 'consumptionHp', '2025-11-11'));
		$this->assertEqualsWithDelta($value * 0.25, $this->read($heater, 'cost', '2025-11-11'), 0.001);
	}

	public function testWithoutAShareOfHcTheShareOfTheCounterOfTheDayIsUsed(): void {
		list($heater) = $this->house();
		$this->hcProfile(array());
		$this->totals(array('2025-11-11' => 50.0));
		$this->put($this->counter, 'consumptionHc', '2025-11-11', 20.0);
		$this->put($this->counter, 'consumptionHp', '2025-11-11', 30.0);
		$this->recalc('2025-11-11', '2025-11-11', array('blank' => 1));
		$value = 0.4 * 48.92;
		$this->assertEqualsWithDelta($value * 0.4, $this->read($heater, 'consumptionHc', '2025-11-11'), 0.001, '20 of 50 kWh are HC at the counter');
	}

	public function testWithoutAnyShareOfHcThereIsNoSplitAndTheCostIsTheShareOfTheCounterCost(): void {
		list($heater) = $this->house();
		$this->hcProfile(array());
		$this->totals(array('2025-11-11' => 50.0));
		$this->recalc('2025-11-11', '2025-11-11', array('blank' => 1));
		$this->assertNull($this->read($heater, 'consumptionHc', '2025-11-11'));
		$this->assertEqualsWithDelta(0.4 * 48.92 / 50.0 * 10.0, $this->read($heater, 'cost', '2025-11-11'), 0.001);
	}

	public function testTheShareOfHcIsThePercentOfTheSeasonOfTheDay(): void {
		$heater = $this->equipment();
		$id = (string) $heater->getId();
		$this->profile(array($id => 40), array($id => 10));
		$this->hcProfile(array($id => 60), array($id => 25));
		$this->assertEqualsWithDelta(60, energyProfile::hcShareOf('2025-11-11', $id), 0.001);
		$this->assertEqualsWithDelta(25, energyProfile::hcShareOf('2025-06-11', $id), 0.001);
		$this->assertNull(energyProfile::hcShareOf('2025-06-11', '999'));
	}

	public function testTheAnalysisDeducesTheShareOfHcOfEveryEquipmentFromItsHistory(): void {
		$heater = $this->equipment();
		$fridge = $this->equipment('electrical');
		$silent = $this->equipment();
		foreach (array('2026-11-16', '2026-11-17', '2026-11-18') as $date) {
			$this->totals(array($date => 40.0));
			$this->put($heater, 'consumptionTotal', $date, 10.0);
			$this->put($heater, 'consumptionHc', $date, 4.0);
			$this->put($heater, 'consumptionHp', $date, 6.0);
			$this->put($fridge, 'consumptionTotal', $date, 1.08);
			$this->put($fridge, 'consumptionHc', $date, 0.27);
			$this->put($fridge, 'consumptionHp', $date, 0.81);
		}
		$result = energyProfile::analyze('2026-11-16', '2026-11-18');
		$this->assertEqualsWithDelta(40.0, $result['hc'][(string) $heater->getId()], 0.1);
		$this->assertEqualsWithDelta(25.0, $result['hc'][(string) $fridge->getId()], 0.1);
		$this->assertArrayNotHasKey((string) $silent->getId(), $result['hc'], 'no HC and no HP history: nothing is deduced');
	}

	public function testTheShareOfHcIsSavedAndValidated(): void {
		$heater = $this->equipment();
		$id = (string) $heater->getId();
		energyProfile::save(array(1), array('cold' => array($id => '40')), array(), '50', '100', array('cold' => array($id => '35,5'), 'hot' => array($id => '20')));
		$this->assertEqualsWithDelta(35.5, energyProfile::hc()['cold'][$id], 0.001);
		$this->assertEqualsWithDelta(20, energyProfile::hc()['hot'][$id], 0.001);
		$this->expectException(Exception::class);
		energyProfile::save(array(1), array(), array(), '50', '100', array('cold' => array($id => '150')));
	}

	public function testTheCommandsOfHcAndHpAreSavedInTheBackupAndRestored(): void {
		list($heater) = $this->house();
		$this->hcProfile(array((string) $heater->getId() => 30));
		$this->totals(array('2025-11-11' => 50.0));
		$this->put($heater, 'consumptionHc', '2025-11-11', 99.0);
		$result = $this->recalc('2025-11-11', '2025-11-11', array('blank' => 1, 'aberrant' => 1, 'filled' => 1));
		$this->assertEqualsWithDelta(0.4 * 48.92 * 0.3, $this->read($heater, 'consumptionHc', '2025-11-11'), 0.001);
		energyCatchup::restore($result['backup']);
		$this->assertEqualsWithDelta(99.0, $this->read($heater, 'consumptionHc', '2025-11-11'), 0.0001);
	}

	/* -------------------------------------------------------------- the analysis of a profile */

	public function testTheAnalysisDetectsTheFixedEquipmentAndItsKwh(): void {
		$days = array('2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20');
		$heater = $this->equipment();
		$fridge = $this->equipment('electrical');
		foreach ($days as $date) {
			$this->totals(array($date => 40.0));
			$this->put($fridge, 'consumptionTotal', $date, 1.08);
		}
		foreach (array(10.0, 14.0, 6.0, 12.0, 8.0) as $i => $kwh) {
			$this->put($heater, 'consumptionTotal', $days[$i], $kwh);
		}
		$result = energyProfile::analyze('2026-11-16', '2026-11-20');
		$this->assertSame(5, $result['days']);
		$this->assertEqualsWithDelta(1.08, $result['fixed'][(string) $fridge->getId()], 0.001, 'a value in kWh per day, not in watts');
		$this->assertArrayNotHasKey((string) $heater->getId(), $result['fixed']);
		$this->assertEqualsWithDelta(50 * 100 / (200 - 5.4), $result['shares'][(string) $heater->getId()], 0.05);
		$this->assertArrayNotHasKey((string) $fridge->getId(), $result['shares']);
	}

	public function testTheFixedValueIsTheMedianOfTheKeptDays(): void {
		$days = array('2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20');
		$fridge = $this->equipment('electrical');
		foreach ($days as $i => $date) {
			$this->totals(array($date => 40.0));
			$this->put($fridge, 'consumptionTotal', $date, array(1.00, 1.04, 1.05, 1.06, 1.10)[$i]);
		}
		$this->assertEqualsWithDelta(1.05, energyProfile::analyze('2026-11-16', '2026-11-20')['fixed'][(string) $fridge->getId()], 0.0001);
	}

	public function testADayWhereTheEquipmentsExceedTheTotalIsLeftOutOfTheAnalysis(): void {
		$heater = $this->equipment();
		$this->totals(array('2026-11-16' => 50.0, '2026-11-17' => 50.0, '2026-11-18' => 50.0));
		$this->put($heater, 'consumptionTotal', '2026-11-16', 20.0);
		$this->put($heater, 'consumptionTotal', '2026-11-17', 4000.0);
		$this->put($heater, 'consumptionTotal', '2026-11-18', 30.0);
		$result = energyProfile::analyze('2026-11-16', '2026-11-18');
		$this->assertSame(2, $result['days']);
		$this->assertSame(array('2026-11-17' => 'aberrant'), $result['dropped']);
		$this->assertEqualsWithDelta(50.0, $result['shares'][(string) $heater->getId()], 0.01);
	}

	public function testAVariableEquipmentIsNotTakenForAFixedOne(): void {
		$heater = $this->equipment();
		foreach (array('2026-11-16' => 3.0, '2026-11-17' => 9.0, '2026-11-18' => 1.0, '2026-11-19' => 14.0) as $date => $kwh) {
			$this->totals(array($date => 40.0));
			$this->put($heater, 'consumptionTotal', $date, $kwh);
		}
		$this->assertSame(array(), energyProfile::analyze('2026-11-16', '2026-11-19')['fixed']);
	}

	public function testAnEquipmentThatConsumedTooFewDaysIsNotDetectedAsFixed(): void {
		$fridge = $this->equipment('electrical');
		$days = array('2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22');
		foreach ($days as $date) {
			$this->totals(array($date => 40.0));
		}
		foreach (array('2026-11-20', '2026-11-21', '2026-11-22') as $date) {
			$this->put($fridge, 'consumptionTotal', $date, 1.08);
		}
		$this->assertSame(array(), energyProfile::analyze('2026-11-15', '2026-11-22')['fixed'], 'three constant days out of eight: not enough');
	}

	public function testAnalysisRefusesAnInvalidRange(): void {
		$this->expectException(Exception::class);
		energyProfile::analyze('2025-02-10', '2025-02-01');
	}

	public function testTheOverviewWeighsEachSeasonOfTheCounter(): void {
		$this->equipment();
		$this->totals(array(date('Y-m-d', strtotime('-10 day')) => 40.0));
		$overview = energyProfile::overview();
		$this->assertCount(1, $overview['equipments']);
		$this->assertSame(1, $overview['seasons']['cold']['days'] + $overview['seasons']['hot']['days']);
		$this->assertEqualsWithDelta(50.0, $overview['toleranceFixed'], 0.0001);
	}
}

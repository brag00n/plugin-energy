<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

/** Tariff periods (energyRates) and the cost catch-up of a period (energyRateCatchup). */
final class EnergyRatesTest extends EnergyTestCase {

	/** @var string[] */
	private $trash = array();
	/** @var array history by table: [cmd_id, datetime, value] */
	private $store = array();

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
		$base = sys_get_temp_dir() . '/energy_rates_' . bin2hex(random_bytes(4));
		energyCatchup::$baseDir = $base;
		$this->trash[] = $base;
		energyRuntime::$now = strtotime('2026-11-26 12:00:00');
		$this->store = array('history' => array(), 'historyArch' => array());
		DB::$handler = function ($sql, $values) {
			return $this->database($sql, $values);
		};
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

	private function periods(): void {
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.30');
		$this->setConfig('rateHc', '0.20');
		$this->setConfig('subscriptionDaily', '0.50');
		$this->setConfig('ratePeriods', json_encode(array(
			array('from' => '2026-01-01', 'to' => '2026-02-01', 'rateHp' => '0.2081', 'rateHc' => '0.1635', 'subscriptionDaily' => '0.66'),
			array('from' => '2026-02-01', 'to' => '2026-08-01', 'rateHp' => '0,2065', 'rateHc' => '0.1579', 'subscriptionDaily' => '0.69'),
			array('from' => '2026-08-01', 'to' => '', 'rateHp' => '0.2142', 'rateHc' => '0.1589'),
		)));
	}

	private function total(): energy {
		return $this->makeEnergy('electricity', array(
			array('category' => 'hp', 'power' => '', 'consumption' => '#1#'),
			array('category' => 'hc', 'power' => '', 'consumption' => '#2#'),
		), array('totalCounter' => 1));
	}

	private function put(energy $energy, string $logicalId, string $date, float $value): void {
		$this->store['history'][] = array($energy->getCmd(null, $logicalId)->getId(), $date . ' 12:00:00', strval($value));
	}

	private function costOf(energy $energy): array {
		$id = $energy->getCmd(null, 'cost')->getId();
		$out = array();
		foreach ($this->store as $rows) {
			foreach ($rows as $row) {
				if ($row[0] === $id) {
					$out[substr($row[1], 0, 10)] = floatval($row[2]);
				}
			}
		}
		ksort($out);
		return $out;
	}

	/* -------------------------------------------------------------- which tariffs for which date */

	public function testPeriodsSavedAsJsonAreReadBackWhenTheCoreDecodesThem(): void {
		// config::byKey of Jeedom returns an array for a JSON value: reading it with strval() lost every period
		energyRates::save(array(array('from' => '2026-01-01', 'to' => '', 'rateHp' => '0,2')));
		$this->assertIsArray(config::byKey('ratePeriods', 'energy'), 'the double behaves like the core');
		$this->assertCount(1, energyRates::periods());
		$this->assertSame('2026-01-01', energyRates::periodFor('2026-03-01')['from']);
	}

	public function testWithoutAnyPeriodTheConfigurationIsUsed(): void {
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0,30');
		$this->setConfig('rateHc', '0.20');
		$this->setConfig('subscriptionDaily', '0.5');
		$tariff = energyRates::forDate('2026-03-03');
		$this->assertTrue($tariff['variable']);
		$this->assertSame(0.30, $tariff['rateHp']);
		$this->assertSame(0.20, $tariff['rateHc']);
		$this->assertSame(0.5, $tariff['subscriptionDaily']);
		$this->assertNull($tariff['period']);
	}

	public function testThePeriodThatHoldsTheDateIsUsedFromIncludedToExcluded(): void {
		$this->periods();
		$this->assertSame(0.2081, energyRates::forDate('2026-01-01')['rateHp'], 'the first day is included');
		$this->assertSame(0.2081, energyRates::forDate('2026-01-31')['rateHp']);
		$this->assertSame(0.2065, energyRates::forDate('2026-02-01')['rateHp'], 'the end is excluded: the next period starts');
		$this->assertSame(0.2142, energyRates::forDate('2026-08-01')['rateHp']);
		$this->assertSame(0.2142, energyRates::forDate('2030-01-01')['rateHp'], 'a period without end holds every later date');
	}

	public function testADateOutsideEveryPeriodFallsBackToTheConfiguration(): void {
		$this->periods();
		$this->assertSame(0.30, energyRates::forDate('2025-12-31')['rateHp']);
	}

	public function testAFieldLeftEmptyInAPeriodFallsBackToTheConfiguration(): void {
		$this->periods();
		$this->assertSame(0.50, energyRates::forDate('2026-09-01')['subscriptionDaily'], 'only what changes has to be typed');
		$this->assertSame(0.66, energyRates::forDate('2026-01-15')['subscriptionDaily']);
	}

	public function testAcceptsADateTimeAndATimestamp(): void {
		$this->periods();
		$this->assertSame(0.2065, energyRates::forDate('2026-03-03 10:15:00')['rateHp']);
		$this->assertSame(0.2065, energyRates::forDate(strtotime('2026-03-03 10:15:00'))['rateHp']);
	}

	public function testNormalizeRefusesWhatCannotBeUsed(): void {
		foreach (array(
			array(array('from' => '2026-02-31', 'to' => '')),
			array(array('from' => '2026-02-01', 'to' => '2026-02-01')),
			array(array('from' => '2026-02-01', 'to' => '2026-01-01')),
			array(array('from' => '2026-02-01', 'to' => '', 'rateHp' => '-1')),
			array(array('from' => '2026-02-01', 'to' => '', 'rateHp' => 'abc')),
			array(array('from' => '2026-02-01', 'to' => '', 'startHc' => '25:99')),
			array(array('from' => '2026-01-01', 'to' => '2026-03-01'), array('from' => '2026-02-01', 'to' => '')),
			array(array('from' => '2026-01-01', 'to' => ''), array('from' => '2026-02-01', 'to' => '')),
		) as $bad) {
			try {
				energyRates::normalize($bad);
				$this->fail('should have been refused: ' . json_encode($bad));
			} catch (Exception $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testNormalizeSortsAndKeepsPeriodsThatFollowEachOther(): void {
		$ok = energyRates::normalize(array(
			array('from' => '2026-02-01', 'to' => '', 'rateHp' => '0,21', 'startHc' => '22:30'),
			array('from' => '2026-01-01', 'to' => '2026-02-01'),
		));
		$this->assertSame('2026-01-01', $ok[0]['from']);
		$this->assertSame('0,21', $ok[1]['rateHp']);
		$this->assertSame('22:30', $ok[1]['startHc']);
	}

	public function testSaveWritesTheSetting(): void {
		energyRates::save(array(array('from' => '2026-01-01', 'to' => '', 'rateHp' => '0.2')));
		$this->assertCount(1, energyRates::periods());
	}

	/* -------------------------------------------------------------- live cost, panel, subscription */

	public function testTheLiveCostUsesThePeriodOfTheDate(): void {
		$this->periods();
		energyRuntime::$now = strtotime('2026-03-10 12:00:00');
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'hp', 'power' => '', 'consumption' => '#1#'),
		), array('previous#1#' => 100));
		$this->setExpression('#1#', 110);
		energy::cron15();
		$this->assertEqualsWithDelta(10 * 0.2065, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testTheLiveCostIsUnchangedWithoutPeriod(): void {
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.30');
		$this->setConfig('rateHc', '0.20');
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'hp', 'power' => '', 'consumption' => '#1#'),
		), array('previous#1#' => 100));
		$this->setExpression('#1#', 110);
		energy::cron15();
		$this->assertEqualsWithDelta(3.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testTheLiveFixedRateFollowsThePeriodToo(): void {
		$this->setConfig('rateMode', 'fixed');
		$this->setConfig('rate', '0.10');
		$this->setConfig('ratePeriods', json_encode(array(array('from' => '2026-01-01', 'to' => '', 'rate' => '0.25'))));
		$energy = $this->makeEnergy('electricity', array(
			array('category' => 'light', 'power' => '', 'consumption' => '#1#'),
		), array('previous#1#' => 0));
		$this->setExpression('#1#', 4);
		energy::cron15();
		$this->assertEqualsWithDelta(1.0, $this->cmdValue($energy, 'cost'), 0.0001);
	}

	public function testThePanelSplitAndSubscriptionFollowThePeriodDay(): void {
		$this->periods();
		$object = new jeeObject(1, 'home');
		$meter = $this->makeEnergy('electricity', array(
			array('category' => 'hp', 'power' => '', 'consumption' => '#1#'),
			array('category' => 'hc', 'power' => '', 'consumption' => '#2#'),
		), array('totalCounter' => 1));
		$meter->cmds = array();
		foreach (array(
			new GraphCmdDouble('consumptionHp', array(array('2026-01-15', '10'), array('2026-03-15', '10'))),
			new GraphCmdDouble('consumptionHc', array(array('2026-01-15', '5'), array('2026-03-15', '5'))),
			new GraphCmdDouble('cost', array(array('2026-01-15', '2.9'), array('2026-03-15', '2.9'))),
		) as $cmd) {
			$meter->addCmdDouble($cmd);
		}
		$object->eqLogics[] = $meter;
		$graph = energy::getGraph('2026-01-01 00:00:00', '2026-03-31', 1);
		$ms = function ($d) {
			return floatval(strtotime($d . ' UTC')) * 1000;
		};
		$byDay = function ($series) {
			$out = array();
			foreach ($series as $p) {
				$out[$p[0]] = $p[1];
			}
			return $out;
		};
		$hp = $byDay($graph['costDetail']['hp']);
		$this->assertEqualsWithDelta(10 * 0.2081, $hp[$ms('2026-01-15')], 0.0001, 'January rate');
		$this->assertEqualsWithDelta(10 * 0.2065, $hp[$ms('2026-03-15')], 0.0001, 'March rate');
		$sub = $byDay($graph['costDetail']['subscription']);
		$this->assertEqualsWithDelta(0.66, $sub[$ms('2026-01-15')], 0.0001);
		$this->assertEqualsWithDelta(0.69, $sub[$ms('2026-03-15')], 0.0001);
	}

	/* -------------------------------------------------------------- the cost catch-up of a period */

	private function seed(energy $energy): void {
		foreach (array('2026-01-10' => array(100.0, 30.0, 70.0), '2026-01-11' => array(50.0, 20.0, 30.0)) as $date => $v) {
			$this->put($energy, 'consumptionTotal', $date, $v[0]);
			$this->put($energy, 'consumptionHc', $date, $v[1]);
			$this->put($energy, 'consumptionHp', $date, $v[2]);
			$this->put($energy, 'cost', $date, 99.0);
		}
	}

	public function testThePreviewComparesTheStoredCostWithTheNewOne(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$preview = energyRateCatchup::preview($energy->getId(), '2026-01-01');
		$this->assertSame(2, $preview['summary']['days']);
		$this->assertEqualsWithDelta(30 * 0.1635 + 70 * 0.2081 + 20 * 0.1635 + 30 * 0.2081, $preview['summary']['new'], 0.01);
		$this->assertEqualsWithDelta(198.0, $preview['summary']['current'], 0.01);
		$this->assertStringContainsString('PRÉVISUALISATION', $preview['text']);
		$this->assertSame(array(), $this->costOfNothingWritten(), 'a preview never writes');
	}

	private function costOfNothingWritten(): array {
		return array_filter(DB::$statements, function ($s) {
			return strpos($s[0], 'REPLACE') === 0 || strpos($s[0], 'DELETE') === 0;
		});
	}

	public function testAPeriodWithoutEndStopsYesterday(): void {
		$this->periods();
		$range = energyRateCatchup::range('2026-08-01');
		$this->assertSame(array('2026-08-01', '2026-11-25'), $range);
	}

	public function testAnUnknownPeriodIsRefused(): void {
		$this->periods();
		$this->expectException(Exception::class);
		energyRateCatchup::range('2026-01-02');
	}

	public function testApplyWritesOnlyTheCostAndKeepsABackupThatRestoresIt(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$this->put($energy, 'cost', '2026-01-12', 5.0);
		$before = $this->store;
		$result = array('next' => 'check');
		$id = '';
		while ($result['next'] !== null) {
			$result = energyRateCatchup::runStep($id, $result['next'], $energy->getId(), '2026-01-01');
			$this->assertTrue($result['ok'], $result['step'] . ': ' . $result['message']);
			$id = $result['id'];
			if ($result['done']) {
				break;
			}
		}
		$cost = $this->costOf($energy);
		$this->assertEqualsWithDelta(30 * 0.1635 + 70 * 0.2081, $cost['2026-01-10'], 0.0001);
		$this->assertEqualsWithDelta(20 * 0.1635 + 30 * 0.2081, $cost['2026-01-11'], 0.0001);
		$this->assertEquals(5.0, $cost['2026-01-12'], 'a day without total is not touched');
		foreach (array('consumptionTotal', 'consumptionHc', 'consumptionHp') as $logicalId) {
			$this->assertSame(
				array_values(array_filter($before['history'], function ($r) use ($energy, $logicalId) {
					return $r[0] === $energy->getCmd(null, $logicalId)->getId();
				})),
				array_values(array_filter($this->store['history'], function ($r) use ($energy, $logicalId) {
					return $r[0] === $energy->getCmd(null, $logicalId)->getId();
				})),
				$logicalId . ' must not change'
			);
		}
		$backups = energyCatchup::listBackups();
		$this->assertCount(1, $backups);
		$this->assertSame('rates', $backups[0]['kind']);
		energyCatchup::restore($backups[0]['name']);
		$restored = $this->costOf($energy);
		$this->assertEquals(99.0, $restored['2026-01-10']);
		$this->assertEquals(99.0, $restored['2026-01-11']);
		$this->assertEquals(5.0, $restored['2026-01-12']);
	}

	private function runAllSteps(energy $energy, string $from): array {
		$id = '';
		$next = 'check';
		$results = array();
		while ($next !== null) {
			$result = energyRateCatchup::runStep($id, $next, $energy->getId(), $from);
			$results[] = $result;
			$id = $result['id'];
			$next = ($result['done'] || !$result['ok']) ? null : $result['next'];
		}
		return $results;
	}

	private function costRows(energy $energy, string $date): array {
		$id = $energy->getCmd(null, 'cost')->getId();
		$rows = array();
		foreach ($this->store as $table => $list) {
			foreach ($list as $row) {
				if ($row[0] === $id && strpos($row[1], $date) === 0) {
					$rows[$row[1]] = floatval($row[2]);
				}
			}
		}
		ksort($rows);
		return $rows;
	}

	public function testADayMeasuredByThePluginKeepsItsPointsScaledToTheNewCost(): void {
		// the plugin writes a counter of the day, one point per cycle: the shape and the dates are kept, the last point is the new cost
		$this->periods();
		$energy = $this->total();
		$this->put($energy, 'consumptionTotal', '2026-01-10', 100.0);
		$this->put($energy, 'consumptionHc', '2026-01-10', 30.0);
		$this->put($energy, 'consumptionHp', '2026-01-10', 70.0);
		$id = $energy->getCmd(null, 'cost')->getId();
		foreach (array('00:15:00' => 2.0, '08:00:00' => 8.0, '23:45:00' => 20.0) as $time => $value) {
			$this->store['history'][] = array($id, '2026-01-10 ' . $time, strval($value));
		}
		$expected = 30 * 0.1635 + 70 * 0.2081;
		$results = $this->runAllSteps($energy, '2026-01-01');
		$this->assertTrue(end($results)['ok'], end($results)['message']);
		$rows = $this->costRows($energy, '2026-01-10');
		$this->assertCount(3, $rows, 'the three points of the day are kept');
		$this->assertEqualsWithDelta($expected, $rows['2026-01-10 23:45:00'], 0.0001, 'the last point is the new cost');
		$this->assertEqualsWithDelta($expected * 2 / 20, $rows['2026-01-10 00:15:00'], 0.0001, 'the shape is kept');
		$this->assertEqualsWithDelta($expected * 8 / 20, $rows['2026-01-10 08:00:00'], 0.0001);
		$this->assertArrayNotHasKey('2026-01-10 12:00:00', $rows, 'no extra point at noon');
	}

	public function testAnImportedDayWithOneRowGetsOnePointAtNoon(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$results = $this->runAllSteps($energy, '2026-01-01');
		$this->assertTrue(end($results)['ok'], end($results)['message']);
		$rows = $this->costRows($energy, '2026-01-10');
		$this->assertSame(array('2026-01-10 12:00:00'), array_keys($rows));
	}

	public function testThePreviewTellsWhichDaysAreMeasuredByThePlugin(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$this->put($energy, 'consumptionTotal', '2026-01-12', 40.0);
		$this->put($energy, 'consumptionHc', '2026-01-12', 10.0);
		$this->put($energy, 'consumptionHp', '2026-01-12', 30.0);
		$id = $energy->getCmd(null, 'cost')->getId();
		$this->store['history'][] = array($id, '2026-01-12 06:00:00', '3.0');
		$this->store['history'][] = array($id, '2026-01-12 23:45:00', '9.0');
		$preview = energyRateCatchup::preview($energy->getId(), '2026-01-01');
		$byDate = array_column($preview['days'], null, 'date');
		$this->assertSame('import', $byDate['2026-01-10']['origin']);
		$this->assertSame('plugin', $byDate['2026-01-12']['origin']);
		$this->assertSame(2, $byDate['2026-01-12']['points']);
		$this->assertSame(1, $preview['summary']['measured']);
		$this->assertStringContainsString('mesurés par le plugin', $preview['text']);
	}

	public function testTheBackupListHasTheOldestFirstAndTheOrigin(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$this->runAllSteps($energy, '2026-01-01');
		$dir = energyCatchup::dir('backup');
		$old = json_decode(file_get_contents(glob($dir . '/catchup_*.json')[0]), true);
		$old['created'] = '2020-01-01 00:00:00';
		$old['kind'] = 'import';
		file_put_contents($dir . '/catchup_20200101_000000_aaaaaa.json', json_encode($old));
		$list = energyCatchup::listBackups();
		$this->assertCount(2, $list);
		$this->assertSame('import', $list[0]['kind'], 'the oldest first');
		$this->assertSame('rates', $list[1]['kind'], 'the most recent last');
	}

	public function testTheBackupKeepsTheTariffsAndThePeriodsThatMadeTheNewCosts(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$this->runAllSteps($energy, '2026-01-01');
		$list = energyCatchup::listBackups();
		$this->assertCount(1, $list);
		$this->assertSame(0.2081, $list[0]['tariff']['rateHp']);
		$this->assertSame(0.1635, $list[0]['tariff']['rateHc']);
		$this->assertSame('2026-01-01', $list[0]['tariff']['from']);
		$this->assertSame('2026-02-01', $list[0]['tariff']['to']);
		$this->assertTrue($list[0]['hasPeriods']);
		$data = json_decode(file_get_contents(energyCatchup::dir('backup') . '/' . $list[0]['name']), true);
		$this->assertCount(3, $data['periods'], 'the whole table of the periods is kept');
	}

	public function testRestoringCanPutTheTariffPeriodsBackAsTheyWere(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$this->runAllSteps($energy, '2026-01-01');
		// the table is changed after the recalculation
		energyRates::save(array(array('from' => '2026-01-01', 'to' => '', 'rateHp' => '0.5')));
		$this->assertCount(1, energyRates::periods());
		$name = energyCatchup::listBackups()[0]['name'];
		energyCatchup::restore($name, false);
		$this->assertCount(1, energyRates::periods(), 'the periods stay as they are unless asked');
		energyCatchup::restore($name, true);
		$this->assertCount(3, energyRates::periods(), 'the table of the periods is back as it was');
		$this->assertSame('0,2065', energyRates::periods()[1]['rateHp']);
	}

	public function testEachRecalculationLeavesALineInTheJournal(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		foreach (array('2026-03-10', '2026-03-11') as $date) {
			$this->put($energy, 'consumptionTotal', $date, 40.0);
			$this->put($energy, 'consumptionHc', $date, 10.0);
			$this->put($energy, 'consumptionHp', $date, 30.0);
		}
		$this->runAllSteps($energy, '2026-01-01');
		$this->runAllSteps($energy, '2026-02-01');
		$lines = energyRateCatchup::journalLines();
		$this->assertCount(2, $lines);
		$this->assertSame('2026-01-01', $lines[0]['from']);
		$this->assertSame(0.2065, $lines[1]['tariff']['rateHp']);
		$this->assertNotSame('', $lines[0]['backup']);
	}

	public function testAnImportBackupHasNoTariffAndNoPeriods(): void {
		$dir = energyCatchup::dir('backup');
		file_put_contents($dir . '/catchup_20260101_000000_abcdef.json', json_encode(array('created' => '2026-01-01 00:00:00', 'from' => '2026-01-01', 'to' => '2026-01-02', 'writtenDays' => array(), 'rows' => array())));
		$list = energyCatchup::listBackups();
		$this->assertSame('import', $list[0]['kind']);
		$this->assertNull($list[0]['tariff']);
		$this->assertFalse($list[0]['hasPeriods']);
	}

	public function testRecalculatingTwiceGivesTheSameCostsAndTheSecondBackupHoldsTheFirstResult(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		// a day measured by the plugin: three points, scaled the first time
		$this->put($energy, 'consumptionTotal', '2026-01-12', 40.0);
		$this->put($energy, 'consumptionHc', '2026-01-12', 10.0);
		$this->put($energy, 'consumptionHp', '2026-01-12', 30.0);
		$id = $energy->getCmd(null, 'cost')->getId();
		foreach (array('01:00:00' => 1.0, '12:00:00' => 5.0, '23:45:00' => 10.0) as $time => $value) {
			$this->store['history'][] = array($id, '2026-01-12 ' . $time, strval($value));
		}
		$this->runAllSteps($energy, '2026-01-01');
		$firstImported = $this->costRows($energy, '2026-01-10');
		$firstMeasured = $this->costRows($energy, '2026-01-12');
		$this->runAllSteps($energy, '2026-01-01');
		$this->assertEqualsWithDelta($firstImported, $this->costRows($energy, '2026-01-10'), 0.000001, 'an imported day gives the same cost');
		$secondMeasured = $this->costRows($energy, '2026-01-12');
		$this->assertSame(array_keys($firstMeasured), array_keys($secondMeasured), 'the points of the day are the same');
		$this->assertEqualsWithDelta(max($firstMeasured), max($secondMeasured), 0.000001, 'the last point is still the new cost');
		foreach ($firstMeasured as $time => $value) {
			$this->assertEqualsWithDelta($value, $secondMeasured[$time], 0.000001, 'the shape does not drift');
		}
		$backups = energyCatchup::listBackups();
		$this->assertCount(2, $backups, 'one backup per recalculation');
		$this->assertCount(2, energyRateCatchup::journalLines());
	}

	public function testNothingIsWrittenWhenTheBackupCannotBeWritten(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$first = energyRateCatchup::runStep('', 'check', $energy->getId(), '2026-01-01');
		$backup = energyCatchup::$baseDir . '/backup';
		@rmdir($backup);
		file_put_contents($backup, 'x');
		try {
			$result = energyRateCatchup::runStep($first['id'], 'backup');
			$this->assertFalse($result['ok']);
		} catch (Exception $e) {
			$this->assertNotSame('', $e->getMessage());
		}
		$this->assertEquals(99.0, $this->costOf($energy)['2026-01-10'], 'the cost was not touched');
	}

	public function testStepsMustFollowTheOrder(): void {
		$this->periods();
		$energy = $this->total();
		$this->seed($energy);
		$first = energyRateCatchup::runStep('', 'check', $energy->getId(), '2026-01-01');
		$this->expectException(Exception::class);
		energyRateCatchup::runStep($first['id'], 'cost');
	}
}

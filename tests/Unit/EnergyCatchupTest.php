<?php

/** The catch-up of the history from an Enedis export (energyCatchup): file, split, plan, backup, import, restore. */
final class EnergyCatchupTest extends EnergyTestCase {

	/** @var string[] folders and files to delete after each test */
	private $trash = array();
	/** @var array in-memory history: table => list of [cmd_id, datetime, value] */
	private $store = array();

	protected function setUp(): void {
		parent::setUp();
		$base = sys_get_temp_dir() . '/energy_catchup_' . bin2hex(random_bytes(4));
		energyCatchup::$baseDir = $base;
		$this->trash[] = $base;
		energyRuntime::$now = strtotime('2026-11-26 12:00:00');
		log::$lines = array();
		log::$level = 100;
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

	/** A small history database: SELECT / DELETE / REPLACE of the statements of energyCatchup. */
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
			// getHistoryEnergy(): the maximum by day over both tables, with the 240 s shift of the real query
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

	/** Rows of a command, all tables, as date => value. */
	private function valuesOf(energy $energy, string $logicalId): array {
		$id = $energy->getCmd(null, $logicalId)->getId();
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

	/** Builds an export like the one of Enedis: header rows, then "dd/mm/yyyy" / kWh rows. */
	private function xlsx(array $days, array $extraRows = array()): string {
		$strings = array();
		$index = function ($text) use (&$strings) {
			$position = array_search($text, $strings, true);
			if ($position === false) {
				$strings[] = $text;
				$position = count($strings) - 1;
			}
			return $position;
		};
		$rows = '';
		$r = 1;
		$rows .= '<row r="' . $r . '"><c r="B' . $r . '" t="s"><v>' . $index('Compte Client') . '</v></c></row>';
		$r++;
		$rows .= '<row r="' . $r . '"><c r="B' . $r . '" t="s"><v>' . $index('Point Référence Mesure (PRM) : ') . '</v></c><c r="C' . $r . '" t="s"><v>' . $index('23282633827960') . '</v></c><c r="E' . $r . '" t="s"><v>' . $index('Type de comptage : ') . '</v></c><c r="F' . $r . '" t="s"><v>' . $index('Consommation Quotidienne') . '</v></c></row>';
		$r++;
		$rows .= '<row r="' . $r . '"><c r="B' . $r . '" t="s"><v>' . $index('Date de début : ') . '</v></c><c r="C' . $r . '" t="s"><v>' . $index('01/01/2026') . '</v></c></row>';
		$r++;
		$rows .= '<row r="' . $r . '"><c r="B' . $r . '" t="s"><v>' . $index('Date') . '</v></c><c r="C' . $r . '" t="s"><v>' . $index('Valeur (en kWh)') . '</v></c></row>';
		foreach ($days as $date => $kwh) {
			$r++;
			$cells = '<c r="B' . $r . '" t="s"><v>' . $index(date('d/m/Y', strtotime($date))) . '</v></c>';
			if ($kwh !== null) {
				$cells .= '<c r="C' . $r . '"><v>' . $kwh . '</v></c>';
			}
			$rows .= '<row r="' . $r . '">' . $cells . '</row>';
		}
		foreach ($extraRows as $raw) {
			$r++;
			$rows .= '<row r="' . $r . '">' . $raw . '</row>';
		}
		$shared = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		foreach ($strings as $s) {
			$shared .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
		}
		$shared .= '</sst>';
		$sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>';
		$path = sys_get_temp_dir() . '/enedis_' . bin2hex(random_bytes(4)) . '.xlsx';
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		$zip->addFromString('xl/sharedStrings.xml', $shared);
		$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
		$zip->close();
		$this->trash[] = $path;
		return $path;
	}

	/** A total counter equipment with its commands. */
	private function totalCounter(): energy {
		return $this->makeEnergy('electricity', array(), array('totalCounter' => 1));
	}

	private function put(energy $energy, string $logicalId, string $date, float $value, string $table = 'history'): void {
		$this->store[$table][] = array($energy->getCmd(null, $logicalId)->getId(), $date . ' 12:00:00', strval($value));
	}

	private function january(): array {
		$days = array();
		for ($d = 1; $d <= 10; $d++) {
			$days[sprintf('2026-01-%02d', $d)] = 10 + $d;
		}
		return $days;
	}

	/* -------------------------------------------------------------- the file */

	public function testParsesTheDailyExport(): void {
		$file = energyCatchup::parseXlsx($this->xlsx($this->january()));
		$this->assertSame('2026-01-01', $file['from']);
		$this->assertSame('2026-01-10', $file['to']);
		$this->assertSame(10, $file['count']);
		$this->assertEquals(155, $file['total']);
		$this->assertSame('23282633827960', $file['prm']);
		$this->assertSame('Consommation Quotidienne', $file['type']);
		$this->assertSame(array(), $file['gaps']);
		$this->assertFalse($file['hasSplit']);
	}

	public function testTheDateOfTheHeaderIsNotADay(): void {
		$file = energyCatchup::parseXlsx($this->xlsx(array('2026-01-02' => 5, '2026-01-03' => 6)));
		$this->assertSame('2026-01-02', $file['from'], '"Date de début : 01/01/2026" must not count as a day');
	}

	public function testUnavailableAndInvalidDaysAndGaps(): void {
		$file = energyCatchup::parseXlsx($this->xlsx(array('2026-01-01' => 10, '2026-01-02' => null, '2026-01-04' => 12, '2026-01-05' => 999)));
		$this->assertSame(array('2026-01-02'), $file['unavailable']);
		$this->assertSame(array('2026-01-03'), $file['gaps']);
		$this->assertCount(1, $file['invalid']);
		$this->assertSame(2, $file['count']);
	}

	public function testRefusesAFileThatIsNotAnExport(): void {
		$path = sys_get_temp_dir() . '/notxlsx_' . bin2hex(random_bytes(4)) . '.xlsx';
		file_put_contents($path, 'not a zip');
		$this->trash[] = $path;
		$this->expectException(Exception::class);
		energyCatchup::parseXlsx($path);
	}

	public function testRefusesAWorkbookWithoutDailyData(): void {
		$path = $this->xlsx(array());
		$this->expectException(Exception::class);
		energyCatchup::parseXlsx($path);
	}

	public function testRefusesAFileThatIsTooBig(): void {
		$path = sys_get_temp_dir() . '/big_' . bin2hex(random_bytes(4)) . '.xlsx';
		file_put_contents($path, str_repeat('a', energyCatchup::MAX_FILE_BYTES + 1));
		$this->trash[] = $path;
		$this->expectException(Exception::class);
		energyCatchup::parseXlsx($path);
	}

	public function testUploadKeepsTheFileAndRefusesOtherExtensions(): void {
		$info = energyCatchup::storeUpload($this->xlsx($this->january()), 'export.xlsx', false);
		$this->assertTrue(energyCatchup::validId($info['id']));
		$this->assertSame(10, $info['count']);
		$this->assertArrayNotHasKey('days', $info);
		$this->assertFileExists(energyCatchup::$baseDir . '/catchup/' . $info['id'] . '.xlsx');
		$this->assertFileExists(energyCatchup::$baseDir . '/.htaccess');
		$this->expectException(Exception::class);
		energyCatchup::storeUpload($this->xlsx($this->january()), 'export.csv', false);
	}

	public function testAnIdThatIsNotAHashIsRefused(): void {
		$this->assertFalse(energyCatchup::validId('../../etc/passwd'));
		$this->assertFalse(energyCatchup::validId(''));
		$this->assertFalse(energyCatchup::validId(null));
	}

	/* -------------------------------------------------------------- periods and HC/HP */

	public function testPeriodsMustBeValidAndNotOverlap(): void {
		$ok = energyCatchup::normalizePeriods(array(array('from' => '2026-02-07', 'to' => '2026-03-07', 'hc' => '425,5', 'hp' => '849'), array('from' => '2026-01-07', 'to' => '2026-02-07', 'hc' => '', 'hp' => '')));
		$this->assertSame('2026-01-07', $ok[0]['from'], 'sorted');
		$this->assertNull($ok[0]['hc']);
		$this->assertSame(425.5, $ok[1]['hc']);
		foreach (array(
			array(array('from' => '2026-02-07', 'to' => '2026-02-07')),
			array(array('from' => '2026-02-31', 'to' => '2026-03-07')),
			array(array('from' => '2026-01-01', 'to' => '2026-02-01', 'hc' => '-1')),
			array(array('from' => '2026-01-01', 'to' => '2026-02-01', 'hc' => 'abc')),
			array(array('from' => '2026-01-01', 'to' => '2026-02-01'), array('from' => '2026-01-31', 'to' => '2026-03-01')),
		) as $bad) {
			try {
				energyCatchup::normalizePeriods($bad);
				$this->fail('should have been refused: ' . json_encode($bad));
			} catch (Exception $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testProportionalSplitAddsUpToTheInvoice(): void {
		$days = array();
		foreach ($this->january() as $date => $kwh) {
			$days[$date] = array('kwh' => floatval($kwh), 'hc' => null, 'hp' => null);
		}
		$periods = array(array('from' => '2026-01-01', 'to' => '2026-01-11', 'hc' => 50.0, 'hp' => 105.0));
		$hc = 0;
		$hp = 0;
		foreach ($days as $date => $day) {
			$split = energyCatchup::splitDay($date, $day, $periods, $days);
			$this->assertSame('period', $split['source']);
			$this->assertEqualsWithDelta($day['kwh'], $split['hc'] + $split['hp'], 0.0005);
			$hc += $split['hc'];
			$hp += $split['hp'];
		}
		$this->assertEqualsWithDelta(50, $hc, 0.01);
		$this->assertEqualsWithDelta(105, $hp, 0.01);
	}

	public function testOnlyOneOfHcHpTheOtherIsTheRest(): void {
		$days = array('2026-01-01' => array('kwh' => 10.0, 'hc' => null, 'hp' => null), '2026-01-02' => array('kwh' => 10.0, 'hc' => null, 'hp' => null));
		$split = energyCatchup::splitDay('2026-01-01', $days['2026-01-01'], array(array('from' => '2026-01-01', 'to' => '2026-01-03', 'hc' => 8.0, 'hp' => null)), $days);
		$this->assertEqualsWithDelta(4, $split['hc'], 0.001);
		$this->assertEqualsWithDelta(6, $split['hp'], 0.001);
	}

	public function testRealSplitOfTheFileWinsAndDaysOutsideAPeriodHaveNone(): void {
		$real = array('kwh' => 20.0, 'hc' => 5.0, 'hp' => 15.0);
		$days = array('2026-01-01' => $real, '2026-02-01' => array('kwh' => 9.0, 'hc' => null, 'hp' => null));
		$periods = array(array('from' => '2026-01-01', 'to' => '2026-01-31', 'hc' => 1.0, 'hp' => 1.0));
		$this->assertSame('file', energyCatchup::splitDay('2026-01-01', $real, $periods, $days)['source']);
		$none = energyCatchup::splitDay('2026-02-01', $days['2026-02-01'], $periods, $days);
		$this->assertNull($none['hc']);
		$this->assertNull($none['source']);
	}

	public function testCostOfADay(): void {
		$variable = array('variable' => true, 'hp' => 0.2, 'hc' => 0.1, 'fixed' => 0.15);
		$this->assertEqualsWithDelta(0.1 * 4 + 0.2 * 6, energyCatchup::dayCost(10, 4, 6, null, $variable), 0.000001);
		$this->assertEqualsWithDelta(10 * (0.5 * 0.1 + 0.5 * 0.2), energyCatchup::dayCost(10, null, null, 0.5, $variable), 0.000001, 'share of the last known period');
		$this->assertEqualsWithDelta(10 * 0.2, energyCatchup::dayCost(10, null, null, null, $variable), 0.000001, 'no split known: peak rate');
		$this->assertEqualsWithDelta(1.5, energyCatchup::dayCost(10, 4, 6, null, array('variable' => false, 'hp' => 0, 'hc' => 0, 'fixed' => 0.15)), 0.000001);
	}

	/* -------------------------------------------------------------- analysis */

	private function params(energy $energy, array $override = array()): array {
		return array_merge(array(
			'energy_id' => $energy->getId(), 'from' => '2026-01-01', 'to' => '2026-01-10', 'only_empty' => '1',
			'periods' => array(array('from' => '2026-01-01', 'to' => '2026-01-11', 'hc' => '50', 'hp' => '105')),
		), $override);
	}

	private function upload(array $days = null): string {
		return energyCatchup::storeUpload($this->xlsx($days === null ? $this->january() : $days), 'export.xlsx', false)['id'];
	}

	private function rates(): void {
		$this->setConfig('rateMode', 'variable');
		$this->setConfig('rateHp', '0.2');
		$this->setConfig('rateHc', '0.1');
	}

	public function testAnalysisKeepsDaysThatHavePluginDataByDefault(): void {
		$this->rates();
		$energy = $this->totalCounter();
		$this->put($energy, 'consumptionTotal', '2026-01-03', 1.17);
		$result = energyCatchup::analyze($this->params($energy), $this->upload());
		$this->assertSame(9, $result['summary']['write']);
		$this->assertSame(1, $result['summary']['keep']);
		$this->assertSame(0, $result['summary']['replace']);
		$byDate = array_column($result['days'], null, 'date');
		$this->assertSame('keep', $byDate['2026-01-03']['status'], 'a partial day of the plugin is still plugin data');
		$this->assertSame('write', $byDate['2026-01-04']['status']);
		$this->assertStringContainsString('ANALYSE DU RATTRAPAGE', $result['text']);
		$this->assertStringContainsString('2026-01-03', $result['text']);
	}

	public function testAnalysisReplacesWhenTheOptionIsOff(): void {
		$this->rates();
		$energy = $this->totalCounter();
		$this->put($energy, 'consumptionTotal', '2026-01-03', 1.17);
		$result = energyCatchup::analyze($this->params($energy, array('only_empty' => '0')), $this->upload());
		$this->assertSame(1, $result['summary']['replace']);
		$this->assertSame(0, $result['summary']['keep']);
	}

	public function testAnalysisComparesEachPeriodWithTheInvoice(): void {
		$this->rates();
		$energy = $this->totalCounter();
		$result = energyCatchup::analyze($this->params($energy), $this->upload());
		$this->assertSame(155.0, floatval($result['periods'][0]['enedis']));
		$this->assertSame(155.0, floatval($result['periods'][0]['invoice']));
		$this->assertEquals(0, $result['periods'][0]['gapPercent']);
		$this->assertFalse($result['periods'][0]['warning']);
		$off = energyCatchup::analyze($this->params($energy, array('periods' => array(array('from' => '2026-01-01', 'to' => '2026-01-11', 'hc' => '50', 'hp' => '90')))), $this->upload());
		$this->assertTrue($off['periods'][0]['warning']);
	}

	public function testAnalysisRefusesWhatTheFileCannotGive(): void {
		$energy = $this->totalCounter();
		$id = $this->upload();
		foreach (array(
			array('from' => '2025-12-31'),
			array('to' => '2026-02-01'),
			array('from' => '2026-01-10', 'to' => '2026-01-01'),
			array('from' => 'garbage'),
			array('energy_id' => 9999),
		) as $override) {
			try {
				energyCatchup::analyze($this->params($energy, $override), $id);
				$this->fail('should have been refused: ' . json_encode($override));
			} catch (Exception $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testAnalysisRefusesTheFutureAndAnEquipmentThatIsNotATotalCounter(): void {
		$energy = $this->totalCounter();
		$days = array('2026-11-25' => 10, '2026-11-26' => 11);
		try {
			energyCatchup::analyze($this->params($energy, array('from' => '2026-11-25', 'to' => '2026-11-26', 'periods' => array())), $this->upload($days));
			$this->fail('today must be refused');
		} catch (Exception $e) {
			$this->assertStringContainsString('2026-11-26', $e->getMessage());
		}
		eqLogic::reset();
		$plain = $this->makeEnergy('electricity');
		$this->expectException(Exception::class);
		energyCatchup::analyze($this->params($plain), $this->upload());
	}

	public function testAnalysisLogsInfoAndDebug(): void {
		$energy = $this->totalCounter();
		energyCatchup::analyze($this->params($energy), $this->upload());
		$levels = array_unique(array_column(log::$lines, 1));
		$this->assertContains('info', $levels);
		$this->assertContains('debug', $levels);
		foreach (log::$lines as $line) {
			if (strpos($line[2], '[perf]') !== 0) {
				$this->assertStringStartsWith('[catchup] ', $line[2]);
			}
		}
		log::$lines = array();
		energyLogTypes::$override = array('catchup' => false);
		energyCatchup::analyze($this->params($energy), $this->upload());
		$catchup = array_filter(log::$lines, function ($line) {
			return strpos($line[2], '[catchup]') === 0;
		});
		$this->assertNotContains('debug', array_column($catchup, 1), 'the box "Rattrapage" switches the debug lines off');
		$this->assertContains('info', array_column($catchup, 1), 'the info lines are always written');
	}

	/* -------------------------------------------------------------- import */

	private function runAll(string $id, array $params): array {
		$results = array();
		$step = 'check';
		while ($step !== null) {
			$result = energyCatchup::runStep($id, $step, $params);
			$results[] = $result;
			if (!$result['ok'] || $result['done']) {
				break;
			}
			$step = $result['next'];
		}
		return $results;
	}

	public function testImportWritesEveryCommandAndFlagsTheDays(): void {
		$this->rates();
		$this->setConfig('counterReliableSince', '2026-11-05');
		$energy = $this->totalCounter();
		$id = $this->upload();
		$results = $this->runAll($id, $this->params($energy));
		$this->assertSame(array_keys(energyCatchup::STEPS), array_column($results, 'step'));
		foreach ($results as $result) {
			$this->assertTrue($result['ok'], $result['step'] . ': ' . $result['message']);
		}
		$this->assertTrue(end($results)['done']);
		$total = $this->valuesOf($energy, 'consumptionTotal');
		$this->assertCount(10, $total);
		$this->assertEquals(11, $total['2026-01-01']);
		$this->assertEquals(20, $total['2026-01-10']);
		$hc = $this->valuesOf($energy, 'consumptionHc');
		$hp = $this->valuesOf($energy, 'consumptionHp');
		$this->assertEqualsWithDelta(50, array_sum($hc), 0.01);
		$this->assertEqualsWithDelta(105, array_sum($hp), 0.01);
		$this->assertCount(10, $this->valuesOf($energy, 'cost'));
		$flag = $this->valuesOf($energy, 'consumptionCatchup');
		$this->assertSame(array_keys($total), array_keys($flag));
		$this->assertSame('2026-01-01', config::byKey('counterReliableSince', 'energy'), 'the setting goes back to the first imported day');
		$this->assertFileDoesNotExist(energyCatchup::$baseDir . '/catchup/' . $id . '.xlsx', 'the upload is deleted at the end');
		$this->assertStringContainsString('import finished', end($results)['text']);
		$this->assertCount(1, energyCatchup::listBackups());
	}

	public function testImportWithoutAHcHpSplitWritesNoHcHpRows(): void {
		$this->rates();
		$energy = $this->totalCounter();
		$id = $this->upload();
		$this->runAll($id, $this->params($energy, array('periods' => array())));
		$this->assertSame(array(), $this->valuesOf($energy, 'consumptionHc'));
		$this->assertCount(10, $this->valuesOf($energy, 'consumptionTotal'));
		$this->assertCount(10, $this->valuesOf($energy, 'cost'));
	}

	public function testImportLeavesTheDaysOfThePluginAndTheirValues(): void {
		$energy = $this->totalCounter();
		$this->put($energy, 'consumptionTotal', '2026-01-03', 1.17);
		$this->put($energy, 'cost', '2026-01-03', 0.3);
		$this->runAll($this->upload(), $this->params($energy));
		$total = $this->valuesOf($energy, 'consumptionTotal');
		$this->assertEquals(1.17, $total['2026-01-03']);
		$this->assertEquals(0.3, $this->valuesOf($energy, 'cost')['2026-01-03']);
		$this->assertArrayNotHasKey('2026-01-03', $this->valuesOf($energy, 'consumptionCatchup'));
		$this->assertCount(10, $total);
	}

	public function testImportReplacesAndRestoreBringsTheOldValuesBack(): void {
		$this->setConfig('counterReliableSince', '2026-11-05');
		$energy = $this->totalCounter();
		$this->put($energy, 'consumptionTotal', '2026-01-03', 1.17);
		$this->put($energy, 'cost', '2026-01-03', 0.3);
		$this->put($energy, 'consumptionTotal', '2026-01-04', 7.0, 'historyArch');
		$before = $this->store;
		$results = $this->runAll($this->upload(), $this->params($energy, array('only_empty' => '0')));
		$this->assertTrue(end($results)['ok']);
		$this->assertEquals(13, $this->valuesOf($energy, 'consumptionTotal')['2026-01-03']);
		$this->assertCount(1, energyCatchup::listBackups());
		$name = energyCatchup::listBackups()[0]['name'];
		energyCatchup::restore($name);
		$this->assertSame('2026-11-05', config::byKey('counterReliableSince', 'energy'));
		$normalize = function ($store) {
			foreach ($store as &$rows) {
				usort($rows, function ($a, $b) {
					return strcmp($a[0] . $a[1], $b[0] . $b[1]);
				});
			}
			return $store;
		};
		$this->assertEquals($normalize($before), $normalize($this->store), 'the history is exactly what it was before the import');
		$this->assertTrue(energyCatchup::listBackups()[0]['restored']);
	}

	public function testNothingToWriteIsRefusedBeforeAnythingIsTouched(): void {
		$energy = $this->totalCounter();
		foreach ($this->january() as $date => $kwh) {
			$this->put($energy, 'consumptionTotal', $date, 5.0);
		}
		try {
			$this->runAll($this->upload(), $this->params($energy));
			$this->fail('nothing to write must be refused');
		} catch (Exception $e) {
			$this->assertStringContainsString('Rien à écrire', $e->getMessage());
		}
		$this->assertSame(array(), energyCatchup::listBackups());
	}

	public function testNoWriteWhenTheBackupCannotBeWritten(): void {
		$energy = $this->totalCounter();
		$id = $this->upload();
		$params = $this->params($energy);
		$this->assertTrue(energyCatchup::runStep($id, 'check', $params)['ok']);
		// the backup folder is replaced by a file: it cannot be written
		$backup = energyCatchup::$baseDir . '/backup';
		@rmdir($backup);
		file_put_contents($backup, 'x');
		try {
			$result = energyCatchup::runStep($id, 'backup');
			$this->assertFalse($result['ok']);
		} catch (Exception $e) {
			$this->assertNotSame('', $e->getMessage());
		}
		$this->assertSame(array(), $this->valuesOf($energy, 'consumptionTotal'), 'nothing was written');
	}

	public function testStepsMustFollowTheOrder(): void {
		$energy = $this->totalCounter();
		$id = $this->upload();
		$this->assertTrue(energyCatchup::runStep($id, 'check', $this->params($energy))['ok']);
		$this->expectException(Exception::class);
		energyCatchup::runStep($id, 'total');
	}

	public function testAnUnknownStepOrImportIdIsRefused(): void {
		foreach (array(array('00000000000000000000000000000000', 'backup'), array('../x', 'backup'), array($this->upload(), 'nope')) as $case) {
			try {
				energyCatchup::runStep($case[0], $case[1]);
				$this->fail('should have been refused');
			} catch (Exception $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testBackupNamesCannotLeaveTheFolder(): void {
		$this->expectException(Exception::class);
		energyCatchup::restore('../../etc/passwd');
	}

	public function testPeriodHintOnlyWhenThePluginCoversTheWholePeriod(): void {
		$energy = $this->totalCounter();
		foreach (array('2026-01-01', '2026-01-02') as $date) {
			$this->put($energy, 'consumptionTotal', $date, 10.0);
		}
		$this->assertNull(energyCatchup::periodHint($energy->getId(), '2026-01-01', '2026-01-04'), 'a day without data: no hint');
	}
}

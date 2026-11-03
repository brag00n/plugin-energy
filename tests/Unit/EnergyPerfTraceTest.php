<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

/** The performance traces: only produced in debug, never changing the result of getGraph(). */
final class EnergyPerfTraceTest extends EnergyTestCase {

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
	}

	private function perfLines(): array {
		$lines = array();
		foreach (log::$lines as $line) {
			if ($line[0] === 'energy' && $line[1] === 'debug' && strpos($line[2], '[perf]') === 0) {
				$lines[] = $line[2];
			}
		}
		return $lines;
	}

	private function buildScenario(): void {
		$object = new jeeObject(1, 'home');
		$power = new GraphCmdDouble('power', array(), 300);
		$power->powerRows = array(new HistoryValueDouble('2026-01-01 10:00:00', 300), new HistoryValueDouble('2026-01-01 10:15:00', 320));
		$heater = $this->withCommands($this->makeEnergy('electricity'), array(
			$power,
			new GraphCmdDouble('consumptionTotal', array(array('2026-01-01', '5'), array('2026-01-02', '6'))),
			new GraphCmdDouble('consumptionHeating', array(array('2026-01-01', '5'), array('2026-01-02', '6'))),
			new GraphCmdDouble('cost', array(array('2026-01-01', '1'))),
		));
		$object->eqLogics[] = $heater;
	}

	private function withCommands(energy $energy, array $cmds): energy {
		$energy->cmds = array();
		foreach ($cmds as $cmd) {
			$energy->addCmdDouble($cmd);
		}
		return $energy;
	}

	public function testTracesFollowTheLogLevel(): void {
		log::$level = 400;
		$this->assertFalse(energy::isPerfEnabled());
		log::$level = 100;
		$this->assertTrue(energy::isPerfEnabled());
		log::$level = 200;
		$this->assertFalse(energy::isPerfEnabled(), 'info level must not produce the debug traces');
	}

	public function testOverrideWinsOverTheLogLevel(): void {
		log::$level = 400;
		energy::$perfOverride = true;
		$this->assertTrue(energy::isPerfEnabled());
		log::$level = 100;
		energy::$perfOverride = false;
		$this->assertFalse(energy::isPerfEnabled());
	}

	public function testNothingIsLoggedOutsideDebug(): void {
		$this->buildScenario();
		log::$level = 400;
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(), $this->perfLines());
	}

	public function testDebugLogsTheWholeRun(): void {
		$this->buildScenario();
		log::$level = 100;
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$text = implode("\n", $this->perfLines());
		$this->assertStringContainsString('getGraph start: object 1, type electricity', $text);
		$this->assertStringContainsString('object tree (1 objects)', $text);
		$this->assertStringContainsString('power history: ', $text);
		$this->assertStringContainsString('getData: ', $text);
		$this->assertStringContainsString('getGraph payload: ', $text);
		$this->assertStringContainsString('KB of JSON', $text);
		$this->assertStringContainsString('getGraph done in ', $text);
	}

	public function testPowerHistoryRowsAreCounted(): void {
		$this->buildScenario();
		log::$level = 100;
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertStringContainsString('history power: 1 calls', implode("\n", $this->perfLines()));
		$this->assertMatchesRegularExpression('/power history: \d+ ms, 2 rows/', implode("\n", $this->perfLines()));
	}

	public function testSummaryListsTheSlowestGroupFirst(): void {
		$this->buildScenario();
		log::$level = 100;
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$summary = array();
		foreach ($this->perfLines() as $line) {
			if (preg_match('/^\[perf\]   (.+): \d+ calls, (\d+) ms, \d+ rows$/', $line, $m)) {
				$summary[$m[1]] = (int) $m[2];
			}
		}
		$this->assertNotEmpty($summary, 'the summary lines are missing');
		$sorted = $summary;
		arsort($sorted);
		$this->assertSame(array_values($sorted), array_values($summary), 'slowest group must come first');
	}

	public function testResultIsTheSameWithAndWithoutTraces(): void {
		$this->buildScenario();
		log::$level = 400;
		$off = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		log::$level = 100;
		$on = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame($off, $on);
	}

	public function testCountersAreResetBetweenRuns(): void {
		$this->buildScenario();
		log::$level = 100;
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		log::$lines = array();
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertStringContainsString('history power: 1 calls', implode("\n", $this->perfLines()), 'calls of the first run must not leak into the second');
	}

	public function testSqlTraceShowsTheCommandAndTheRows(): void {
		$cmd = new energyCmd();
		$cmd->id = 7;
		$cmd->setLogicalId('consumptionTotal');
		log::$level = 100;
		$cmd->getHistoryEnergy('2026-01-01 00:00:00', '2026-01-31');
		$text = implode("\n", $this->perfLines());
		$this->assertMatchesRegularExpression('/SQL history of cmd 7 \(consumptionTotal\): \d+ ms, 0 rows/', $text);
	}

	public function testSqlIsNotTracedOutsideDebug(): void {
		$cmd = new energyCmd();
		$cmd->id = 7;
		log::$level = 400;
		$cmd->getHistoryEnergy('2026-01-01 00:00:00', '2026-01-31');
		$this->assertSame(array(), $this->perfLines());
		$this->assertCount(1, DB::$statements, 'the query itself must still run');
	}

	public function testAjaxLogsTheRequestDurationOnlyInDebug(): void {
		$ajax = file_get_contents(__DIR__ . '/../../core/ajax/energy.ajax.php');
		$this->assertStringContainsString('energy::isPerfEnabled()', $ajax);
		$this->assertStringContainsString('register_shutdown_function', $ajax);
		$this->assertStringContainsString("ajax getData for object", $ajax);
	}
}

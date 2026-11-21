<?php

/**
 * getHistoryEnergy() builds its SQL and sends it to DB::Prepare(). The database is replaced by a double
 * that records the statement, so the shape of the query can be checked without a MySQL server.
 */

final class EnergyHistoryQueryTest extends EnergyTestCase {

	protected function setUp(): void {
		parent::setUp();
		DB::$statements = array();
		DB::$throw = null;
	}

	private function cmd(): energyCmd {
		$cmd = new energyCmd();
		$cmd->id = 7;
		return $cmd;
	}

	public function testQueryIsBoundToTheCommand(): void {
		$this->cmd()->getHistoryEnergy('2026-01-01 00:00:00', '2026-01-31');
		[$sql, $values] = DB::$statements[0];
		$this->assertSame(7, $values['cmd_id']);
		$this->assertStringContainsString('cmd_id=:cmd_id', $sql);
	}

	public function testBothHistoryTablesAreRead(): void {
		$this->cmd()->getHistoryEnergy();
		$sql = DB::$statements[0][0];
		$this->assertStringContainsString('FROM history', $sql);
		$this->assertStringContainsString('FROM historyArch', $sql);
		$this->assertStringContainsString('UNION ALL', $sql);
	}

	public function testBoundsAreBoundOnlyWhenGiven(): void {
		$this->cmd()->getHistoryEnergy();
		[$sql, $values] = DB::$statements[0];
		$this->assertArrayNotHasKey('startTime', $values);
		$this->assertArrayNotHasKey('endTime', $values);
		$this->assertStringNotContainsString(':startTime', $sql);

		DB::$statements = array();
		$this->cmd()->getHistoryEnergy('2026-01-01 00:00:00', '2026-01-31');
		[$sql, $values] = DB::$statements[0];
		$this->assertArrayHasKey('startTime', $values);
		$this->assertArrayHasKey('endTime', $values);
		$this->assertStringContainsString(':startTime', $sql);
		$this->assertStringContainsString(':endTime', $sql);
	}

	public function testQueryIsValidUnderOnlyFullGroupBy(): void {
		$this->cmd()->getHistoryEnergy();
		$sql = DB::$statements[0][0];
		$this->assertStringContainsString('GROUP BY date(`datetime`)', $sql);
		// Ordering by the raw column is rejected by ONLY_FULL_GROUP_BY: it must order by the grouped expression
		$this->assertStringContainsString('ORDER BY date(`datetime`)', $sql);
		$this->assertDoesNotMatchRegularExpression('/ORDER BY `datetime`/', $sql);
	}

	public function testNoMultiStatementFallbackIsSent(): void {
		$this->cmd()->getHistoryEnergy();
		$this->assertStringNotContainsString('sql_mode', DB::$statements[0][0]);
		$this->assertCount(1, DB::$statements);
	}

	public function testDatabaseErrorsAreNotSwallowed(): void {
		DB::$throw = new Exception('boom');
		$this->expectExceptionMessage('boom');
		$this->cmd()->getHistoryEnergy();
		$this->assertCount(1, DB::$statements);
	}
}

<?php

require_once __DIR__ . '/../Support/GraphStubs.php';

/** The outdoor temperature is only drawn next to the consumption: it never enters the categories or the totals. */
final class EnergyTemperatureTest extends EnergyTestCase {

	protected function setUp(): void {
		parent::setUp();
		jeeObject::reset();
		DB::$statements = array();
	}

	private function weather(): GraphCmdDouble {
		$cmd = new GraphCmdDouble('temperature');
		$cmd->id = 55;
		cmd::$byString['#[Maison][Meteo][Temperature]#'] = $cmd;
		return $cmd;
	}

	public function testNoSettingMeansNoCurveAndNoQuery(): void {
		new jeeObject(1, 'home');
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(), $result['temperature']);
		$this->assertSame(array(), DB::$statements);
	}

	public function testUnknownCommandGivesNoCurve(): void {
		new jeeObject(1, 'home');
		config::$values['energy::outdoorTemperature'] = '#[Nowhere][None][None]#';
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(), $result['temperature']);
		$this->assertSame(array(), DB::$statements);
		$this->assertSame('error', log::$lines[0][1], 'the ignored setting is logged as an error');
	}

	public function testSpacesCollapsedByThePickerStillFindTheCommand(): void {
		$cmd = new GraphCmdDouble('temperature');
		$cmd->id = 872;
		$cmd->name = '[Jardin][Sonde du Jardin  (XiaomiHome)][Température]';
		// registered under its real name, with two spaces
		cmd::$byString['#[Jardin][Sonde du Jardin  (XiaomiHome)][Température]#'] = $cmd;
		new jeeObject(1, 'home');
		config::$values['energy::outdoorTemperature'] = '#[Jardin][Sonde du Jardin (XiaomiHome)][Température]#';
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(872, DB::$statements[0][1]['cmd_id']);
	}

	public function testTheCommandIsReadFromTheSettingOfTheEnergyType(): void {
		$this->weather();
		new jeeObject(1, 'home');
		config::$values['energy::outdoorTemperatureGas'] = '#[Maison][Meteo][Temperature]#';
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1, 'electricity');
		$this->assertSame(array(), DB::$statements, 'the electricity setting is empty');
		energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1, 'gas');
		$this->assertSame(55, DB::$statements[0][1]['cmd_id']);
	}

	public function testDailyMeansAreSortedAndNonNumericIgnored(): void {
		$this->weather();
		new jeeObject(1, 'home');
		config::$values['energy::outdoorTemperature'] = '#[Maison][Meteo][Temperature]#';
		$points = (new ReflectionMethod('energy', 'outdoorTemperature'));
		$points->setAccessible(true);
		DB::$rows = array(array('2026-01-03', '4.26'), array('2026-01-01', '-1.04'), array('2026-01-02', null));
		$result = $points->invoke(null, 'electricity', '2026-01-01 00:00:00', '2026-01-31');
		$this->assertSame(array(
			array(floatval(strtotime('2026-01-01 UTC')) * 1000, -1.0),
			array(floatval(strtotime('2026-01-03 UTC')) * 1000, 4.3),
		), $result);
	}

	public function testTemperatureNeverChangesTheTotals(): void {
		$this->weather();
		new jeeObject(1, 'home');
		config::$values['energy::outdoorTemperature'] = '#[Maison][Meteo][Temperature]#';
		DB::$rows = array(array('2026-01-01', '5'));
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertCount(1, $result['temperature']);
		$this->assertSame(0.0, $result['total']['consumption']);
		$this->assertSame(array(), $result['totalConsumption']);
		foreach ($result['category'] as $points) {
			$this->assertSame(array(), $points);
		}
	}

	public function testASqlFailureDoesNotBreakThePanelAndIsLogged(): void {
		$this->weather();
		new jeeObject(1, 'home');
		config::$values['energy::outdoorTemperature'] = '#[Maison][Meteo][Temperature]#';
		DB::$throw = new RuntimeException('database gone');
		$result = energy::getGraph('2026-01-01 00:00:00', '2026-01-31', 1);
		$this->assertSame(array(), $result['temperature']);
		$this->assertStringContainsString('database gone', json_encode(log::$lines));
		$this->assertStringContainsString('error', json_encode(log::$lines));
	}
}

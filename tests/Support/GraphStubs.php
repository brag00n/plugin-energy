<?php
/*
 * Doubles needed by energy::getGraph(): jeeObject and a command returning canned histories.
 */

class jeeObject {
	public static $objects = array();

	public $id;
	public $name;
	public $childs = array();
	public $eqLogics = array();

	public function __construct(int $id, string $name) {
		$this->id = $id;
		$this->name = $name;
		self::$objects[$id] = $this;
	}

	public static function byId($_id) {
		return self::$objects[$_id] ?? null;
	}

	public static function reset() {
		self::$objects = array();
	}

	public function getId() {
		return $this->id;
	}

	public function getName() {
		return $this->name;
	}

	public function getChilds() {
		return $this->childs;
	}

	public function getEqLogic($_onlyEnable = true, $_onlyVisible = false, $_eqType_name = null) {
		return $this->eqLogics;
	}
}

class HistoryValueDouble {
	private $datetime;
	private $value;

	public function __construct(string $datetime, $value) {
		$this->datetime = $datetime;
		$this->value = $value;
	}

	public function getDatetime() {
		return $this->datetime;
	}

	public function getValue() {
		return $this->value;
	}
}

/** Command double whose getHistoryEnergy()/getHistory() return what the test configured. */
class GraphCmdDouble extends cmd {
	public $energyRows = array();
	public $powerRows = array();

	public function __construct(string $logicalId, array $energyRows = array(), $currentValue = 0) {
		$this->logicalId = $logicalId;
		$this->type = 'info';
		$this->energyRows = $energyRows;
		$this->value = $currentValue;
	}

	public function getHistoryEnergy($_startTime = null, $_endTime = null) {
		return $this->energyRows;
	}

	public function getHistory($_start = null, $_end = null) {
		return $this->powerRows;
	}
}

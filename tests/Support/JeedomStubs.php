<?php
/*
 * Minimal in-memory doubles of the Jeedom core, only what the energy plugin calls.
 * They are deliberately simple: the goal is to run the plugin's own logic, not to emulate Jeedom.
 */

class eqLogic {
	/** @var eqLogic[] registry of all equipments, filled by the tests */
	public static $registry = array();
	public static $saveCount = 0;

	public $id = 0;
	public $name = 'eq';
	public $eqType_name = 'energy';
	public $isEnable = 1;
	public $configuration = array();
	public $cmds = array();
	public $rights = true;
	public $category = array();
	public $objectName = '';

	public static function byType($_type, $_onlyEnable = false) {
		$return = array();
		foreach (self::$registry as $eq) {
			if ($eq->eqType_name == $_type && (!$_onlyEnable || $eq->isEnable == 1)) {
				$return[] = $eq;
			}
		}
		return $return;
	}

	public static function byId($_id) {
		foreach (self::$registry as $eq) {
			if ($eq->id == $_id) {
				return $eq;
			}
		}
		return null;
	}

	public static function reset() {
		self::$registry = array();
		self::$saveCount = 0;
		jeedom::$expressions = array();
		config::$values = array();
		log::$lines = array();
		history::$emptied = array();
		cmd::$values = array();
	}

	public function getId() {
		return $this->id;
	}

	public function getName() {
		return $this->name;
	}

	public function getHumanName() {
		return '[' . $this->objectName . '][' . $this->name . ']';
	}

	public function getEqType_name() {
		return $this->eqType_name;
	}

	public function setEqType_name($_value) {
		$this->eqType_name = $_value;
	}

	public function getConfiguration($_key = '', $_default = '') {
		if (!array_key_exists($_key, $this->configuration) || $this->configuration[$_key] === null) {
			return $_default;
		}
		return $this->configuration[$_key];
	}

	public function setConfiguration($_key, $_value) {
		$this->configuration[$_key] = $_value;
	}

	public function setCategory($_key, $_value) {
		$this->category[$_key] = $_value;
	}

	public function hasRight($_right) {
		return $this->rights;
	}

	/** Mimics eqLogic::save(): runs the plugin hooks like the real core does. */
	public function save() {
		self::$saveCount++;
		$isNew = ($this->id == 0);
		if ($isNew) {
			$this->id = count(self::$registry) + 1;
			if (method_exists($this, 'preInsert')) {
				$this->preInsert();
			}
		} elseif (method_exists($this, 'preUpdate')) {
			$this->preUpdate();
		}
		if (!in_array($this, self::$registry, true)) {
			self::$registry[] = $this;
		}
		if (method_exists($this, 'postSave')) {
			$this->postSave();
		}
	}

	public function remove() {
		self::$registry = array_values(array_filter(self::$registry, function ($eq) {
			return $eq !== $this;
		}));
	}

	/** getCmd(null, $logicalId) or getCmd('info') like the real core. */
	public function getCmd($_type = null, $_logicalId = null) {
		$return = array();
		foreach ($this->cmds as $cmd) {
			if ($_logicalId !== null && $cmd->getLogicalId() != $_logicalId) {
				continue;
			}
			if ($_type !== null && $cmd->getType() != $_type) {
				continue;
			}
			if ($_logicalId !== null) {
				return $cmd;
			}
			$return[] = $cmd;
		}
		return ($_logicalId !== null) ? null : $return;
	}

	public function addCmdDouble($_cmd) {
		$_cmd->eqLogic_id = $this->id;
		$_cmd->eqLogicRef = $this;
		$this->cmds[] = $_cmd;
	}
}

class cmd {
	public static $values = array();
	private static $nextId = 100;

	public $id = 0;
	public $logicalId = '';
	public $name = '';
	public $type = 'info';
	public $subType = '';
	public $unite = '';
	public $isVisible = 0;
	public $isHistorized = 0;
	public $template = array();
	public $configuration = array();
	public $eqLogic_id = 0;
	public $eqLogicRef = null;
	public $collectDate = '';
	public $value = 0;
	public $events = array();
	public $history = array();

	/** Like the core, replaces #id# by the value of the command: here, the one set with jeedom::$expressions. */
	public static function cmdToValue($_string) {
		if (array_key_exists($_string, jeedom::$expressions)) {
			return jeedom::$expressions[$_string];
		}
		return $_string;
	}

	public function getId() {
		if ($this->id == 0) {
			$this->id = self::$nextId++;
		}
		return $this->id;
	}

	public function getLogicalId() {
		return $this->logicalId;
	}

	public function setLogicalId($_value) {
		$this->logicalId = $_value;
	}

	public function setName($_value) {
		$this->name = $_value;
	}

	public function getName() {
		return $this->name;
	}

	public function setType($_value) {
		$this->type = $_value;
	}

	public function getType() {
		return $this->type;
	}

	public function setSubType($_value) {
		$this->subType = $_value;
	}

	public function getSubType() {
		return $this->subType;
	}

	public function setUnite($_value) {
		$this->unite = $_value;
	}

	public function getUnite() {
		return $this->unite;
	}

	public function setIsVisible($_value) {
		$this->isVisible = $_value;
	}

	public function getIsVisible() {
		return $this->isVisible;
	}

	public function setIsHistorized($_value) {
		$this->isHistorized = $_value;
	}

	public function setTemplate($_key, $_value) {
		$this->template[$_key] = $_value;
	}

	public function setConfiguration($_key, $_value) {
		$this->configuration[$_key] = $_value;
	}

	public function getConfiguration($_key = '', $_default = '') {
		return array_key_exists($_key, $this->configuration) ? $this->configuration[$_key] : $_default;
	}

	public function setEqLogic_id($_value) {
		$this->eqLogic_id = $_value;
	}

	public function setCollectDate($_value) {
		$this->collectDate = $_value;
	}

	public function execCmd() {
		return $this->value;
	}

	public function event($_value) {
		$this->value = $_value;
		$this->events[] = array($this->collectDate, $_value);
	}

	public function save() {
		$eq = eqLogic::byId($this->eqLogic_id);
		if ($eq !== null && !in_array($this, $eq->cmds, true)) {
			$this->getId();
			$eq->addCmdDouble($this);
		}
	}

	public function getHistory($_start = null, $_end = null) {
		return $this->history;
	}
}

class jeedom {
	/** @var array expression => value, filled by the tests */
	public static $expressions = array();

	public static function evaluateExpression($_expression) {
		if (array_key_exists($_expression, self::$expressions)) {
			return self::$expressions[$_expression];
		}
		return $_expression;
	}
}

class config {
	public static $values = array();

	public static function byKey($_key, $_plugin = 'core', $_default = '') {
		$k = $_plugin . '::' . $_key;
		return array_key_exists($k, self::$values) ? self::$values[$k] : $_default;
	}
}

class log {
	public static $lines = array();

	public static function add($_log, $_level, $_message) {
		self::$lines[] = array($_log, $_level, $_message);
	}
}

class history {
	public static $emptied = array();

	public static function emptyHistory($_cmd_id) {
		self::$emptied[] = $_cmd_id;
	}
}

/** Database double: records the statements instead of running them. */
class DB {
	const FETCH_TYPE_ALL = 1;
	const FETCH_TYPE_ROW = 2;
	public static $statements = array();
	public static $throw = null;

	public static function Prepare($_sql, $_values, $_fetch) {
		self::$statements[] = array($_sql, $_values);
		if (self::$throw !== null) {
			throw self::$throw;
		}
		return array();
	}
}

/** Mimics the evaluate() helper of the Jeedom core for plain numeric strings. */
function evaluate($_string) {
	return is_numeric($_string) ? $_string + 0 : $_string;
}

function __($_string, $_plugin = '') {
	return $_string;
}

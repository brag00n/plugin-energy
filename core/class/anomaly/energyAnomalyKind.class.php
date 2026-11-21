<?php
/*
 * ANOMALY DETECTION - THE ENERGIES
 *
 * What differs from one energy to another is isolated here, one class per energy:
 *   - energyAnomalyElectricity  (energyAnomalyElectricity.class.php)
 *   - energyAnomalyWater        (energyAnomalyWater.class.php)
 *   - energyAnomalyGas          (energyAnomalyGas.class.php)
 *
 * An energy gives: the unit of its quantity, how a quantity credited over some time becomes a rate (rule R1), and
 * optionally its own default for the tuning parameters of the rules. The rules themselves (energyAnomalyRules)
 * are the same for all. To handle another energy, add a class and one line in energyAnomaly::kindFor().
 */
abstract class energyAnomalyKind {

	/** Name of the energy, used in the settings (e.g. "Water" for energyJumpWaterMargin). */
	abstract public function name();

	/** Unit of the quantity credited by the plugin for this energy (kWh, L...). */
	abstract public function unit();

	/** Unit of the rate (W, L/h...). */
	abstract public function rateUnit();

	/** Rate of a cycle: the quantity credited over the given duration, in the rate unit. */
	abstract public function rate($_quantity, $_duration);

	/**
	 * Defaults of the tuning parameters of the rules that differ for this energy, by parameter name (Margin,
	 * MinActive, WindowDays, LearnStep, RegimeCount, RegimeHours). Empty: the defaults of the rules apply.
	 * The settings of the plugin still win over these.
	 */
	public function defaults() {
		return array();
	}

	/** Quantity per hour, the common part of every rate. */
	protected function perHour($_quantity, $_duration) {
		return $_quantity / ($_duration / 3600);
	}
}

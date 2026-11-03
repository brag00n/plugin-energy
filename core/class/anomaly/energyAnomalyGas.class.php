<?php
/*
 * ANOMALY DETECTION - GAS
 *
 * The plugin counts gas in kWh (see energy::postSave()), so the rate is in W like electricity.
 * If a gas meter is counted in m3 instead, change unit() and rateUnit() here (and rate(): m3 per hour) so that the
 * logs show the right unit; the detection itself only compares the meter with its own history and does not depend
 * on the unit.
 */
class energyAnomalyGas extends energyAnomalyKind {

	public function name() {
		return 'Gas';
	}

	public function unit() {
		return 'kWh';
	}

	public function rateUnit() {
		return 'W';
	}

	public function rate($_quantity, $_duration) {
		return $this->perHour($_quantity, $_duration) * 1000;
	}
}

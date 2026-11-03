<?php
/*
 * ANOMALY DETECTION - WATER
 *
 * The quantity is in litres, the rate in litres per hour. A water equipment only has meters (index delta).
 * A leak shows as a rate far above the usual one of the meter.
 */
class energyAnomalyWater extends energyAnomalyKind {

	public function name() {
		return 'Water';
	}

	public function unit() {
		return 'L';
	}

	public function rateUnit() {
		return 'L/h';
	}

	public function rate($_quantity, $_duration) {
		return $this->perHour($_quantity, $_duration);
	}
}

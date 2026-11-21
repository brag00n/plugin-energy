<?php
/*
 * ANOMALY DETECTION - ELECTRICITY
 *
 * The quantity is in kWh, the rate in W (kWh per hour x 1000), which is the unit everybody reads for a power.
 * The lines that bring energy to an electric equipment are of two kinds (see energy::cron15()):
 *   - "index delta": the difference of an energy counter between two cycles;
 *   - "integrated power": the last power seen times the time elapsed, when the line has no counter.
 */
class energyAnomalyElectricity extends energyAnomalyKind {

	public function name() {
		return 'Electricity';
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

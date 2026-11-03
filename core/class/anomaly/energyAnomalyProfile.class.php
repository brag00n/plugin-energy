<?php
/*
 * ANOMALY DETECTION - THE STORAGE OF THE PROFILE
 *
 * What the detector has learned about one equipment, stored in the configuration key "jumpProfile" of this
 * equipment (like the other states of the plugin, e.g. "previous<key>"):
 *
 *   days     {YYYY-MM-DD: highest normal rate of the day}   one value per day over the window (rule R2)
 *   active   number of cycles with a non zero rate           guard of rule R4
 *   abnormal [[timestamp, rate], ...]                        abnormal cycles of the last hours (rules R8, R9)
 *
 * To forget everything learned about an equipment: remove its "jumpProfile" configuration (see forget()).
 * Anything unreadable in the stored value is ignored, so a corrupted value only means "nothing learned yet".
 */
class energyAnomalyProfile {

	const KEY = 'jumpProfile';

	/** Empty profile. */
	public static function blank() {
		return array('days' => array(), 'active' => 0, 'abnormal' => array());
	}

	public static function load($_energy) {
		$raw = $_energy->getConfiguration(self::KEY, '');
		$data = is_array($raw) ? $raw : json_decode((string) $raw, true);
		$profile = self::blank();
		if (!is_array($data)) {
			return $profile;
		}
		if (isset($data['active']) && is_numeric($data['active']) && $data['active'] > 0) {
			$profile['active'] = intval($data['active']);
		}
		if (isset($data['abnormal']) && is_array($data['abnormal'])) {
			foreach ($data['abnormal'] as $entry) {
				if (is_array($entry) && count($entry) == 2 && is_numeric($entry[0]) && is_numeric($entry[1]) && $entry[0] > 0 && $entry[1] > 0) {
					$profile['abnormal'][] = array(intval($entry[0]), floatval($entry[1]));
				}
			}
		}
		if (isset($data['days']) && is_array($data['days'])) {
			foreach ($data['days'] as $day => $rate) {
				if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && is_numeric($rate) && $rate > 0) {
					$profile['days'][$day] = floatval($rate);
				}
			}
		}
		return $profile;
	}

	/** Puts the profile back in the equipment. The caller saves the equipment (energy::cron15() does it once per cycle). */
	public static function store($_energy, $_profile) {
		$_energy->setConfiguration(self::KEY, json_encode($_profile));
	}

	/** Forgets everything learned about an equipment. */
	public static function forget($_energy) {
		$_energy->setConfiguration(self::KEY, '');
	}
}

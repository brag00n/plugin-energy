<?php

use PHPUnit\Framework\TestCase;

/** Static checks on the plugin files: metadata, translations, syntax and the security regressions fixed so far. */
final class PluginFilesTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	private function root(): string {
		return str_replace('\\', '/', realpath(self::ROOT));
	}

	private function read(string $path): string {
		$content = file_get_contents(self::ROOT . '/' . $path);
		$this->assertNotFalse($content, $path);
		return $content;
	}

	private function json(string $path): array {
		$data = json_decode($this->read($path), true);
		$this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ': ' . json_last_error_msg());
		return $data;
	}

	/** @return string[] */
	private function phpFiles(): array {
		$files = array();
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root(), FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$path = str_replace('\\', '/', $file->getPathname());
			// Judge the path relative to the plugin root: the plugin itself lives under a folder that may contain "tests"
			$relative = substr($path, strlen($this->root()) + 1);
			if (substr($relative, -4) !== '.php' || preg_match('#^(tests|3rdparty|\.git|\.vscode)/#', $relative)) {
				continue;
			}
			$files[] = $path;
		}
		sort($files);
		return $files;
	}

	public function testInfoJsonIsValidAndComplete(): void {
		$info = $this->json('plugin_info/info.json');
		foreach (array('id', 'name', 'description', 'licence', 'author', 'require', 'category', 'language', 'compatibility') as $key) {
			$this->assertArrayHasKey($key, $info, $key);
		}
		$this->assertSame('energy', $info['id']);
	}

	public function testPluginRequiresJeedom4(): void {
		$info = $this->json('plugin_info/info.json');
		$this->assertGreaterThanOrEqual(4.0, floatval($info['require']), 'The code relies on jeeObject (Jeedom 4+)');
	}

	public function testEveryDeclaredLanguageHasADescription(): void {
		$info = $this->json('plugin_info/info.json');
		foreach ($info['language'] as $language) {
			$this->assertArrayHasKey($language, $info['description'], $language);
			$this->assertNotSame('', trim($info['description'][$language]), $language);
		}
	}

	public function testDescriptionsAreTranslated(): void {
		$info = $this->json('plugin_info/info.json');
		$french = $info['description']['fr_FR'];
		foreach ($info['language'] as $language) {
			if ($language === 'fr_FR') {
				continue;
			}
			$this->assertNotSame($french, $info['description'][$language], $language . ' still carries the French text');
		}
	}

	public function testEveryDeclaredLanguageHasATranslationFile(): void {
		$info = $this->json('plugin_info/info.json');
		foreach ($info['language'] as $language) {
			$this->assertFileExists(self::ROOT . '/core/i18n/' . $language . '.json', $language);
		}
	}

	public function testTranslationFilesAreValidAndShareTheSameKeys(): void {
		$reference = $this->json('core/i18n/fr_FR.json');
		foreach (glob(self::ROOT . '/core/i18n/*.json') as $file) {
			$data = $this->json('core/i18n/' . basename($file));
			$this->assertSame(array_keys($reference), array_keys($data), basename($file) . ': files differ');
			foreach ($reference as $source => $strings) {
				$this->assertSame(array_keys($strings), array_keys($data[$source]), basename($file) . ' / ' . $source);
			}
		}
	}

	public function testConfigIniIsValid(): void {
		$config = parse_ini_file(self::ROOT . '/core/config/energy.config.ini', true);
		$this->assertIsArray($config);
		$this->assertSame('fixed', $config['energy']['rateMode']);
	}

	public function testAllPhpFilesHaveNoSyntaxError(): void {
		$files = $this->phpFiles();
		$this->assertNotEmpty($files);
		foreach ($files as $file) {
			$output = array();
			exec('"' . PHP_BINARY . '" -n -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
			$this->assertSame(0, $code, $file . ': ' . implode(' ', $output));
		}
	}

	public function testAjaxRequiresAnAdministratorForEmptyHistory(): void {
		$ajax = $this->read('core/ajax/energy.ajax.php');
		$position = strpos($ajax, "init('action') == 'emptyHistory'");
		$this->assertNotFalse($position);
		$block = substr($ajax, $position, 400);
		$this->assertStringContainsString("isConnect('admin')", $block);
	}

	public function testAjaxEmptyHistoryChecksTheEquipmentType(): void {
		$ajax = $this->read('core/ajax/energy.ajax.php');
		$this->assertStringContainsString("getEqType_name() != 'energy'", $ajax);
	}

	public function testDeadApplyCostToActionIsGone(): void {
		$this->assertStringNotContainsString('applyCostTo', $this->read('core/ajax/energy.ajax.php'));
	}

	public function testAjaxValidatesTheGetDataInputs(): void {
		$ajax = $this->read('core/ajax/energy.ajax.php');
		$this->assertStringContainsString("in_array(\$energyType, array('electricity', 'water', 'gas'))", $ajax);
		$this->assertMatchesRegularExpression('/preg_match\(\'\/\^\\\\d\{4\}-\\\\d\{2\}-\\\\d\{2\}\$\/\'/', $ajax);
	}

	public function testPanelNeverEchoesRawRequestParameters(): void {
		$panel = $this->read('desktop/php/panel.php');
		// After the validation block, request parameters must only be read through the validated variables
		$afterValidation = substr($panel, strpos($panel, "sendVarToJs('object_id'"));
		foreach (array('energyType', 'groupBy', 'startDate', 'endDate') as $parameter) {
			$this->assertStringNotContainsString("init('" . $parameter . "'", $afterValidation, $parameter . ' is read unvalidated');
		}
	}

	public function testPanelWhitelistsItsParameters(): void {
		$panel = $this->read('desktop/php/panel.php');
		$this->assertStringContainsString("array('electricity', 'water', 'gas')", $panel);
		$this->assertStringContainsString("array('day', 'month')", $panel);
		$this->assertStringContainsString('\\d{4}-\\d{2}-\\d{2}', $panel);
	}

	public function testPanelElectricityLinkHasItsAmpersand(): void {
		$this->assertStringNotContainsString("\$groupBy . 'energyType=", $this->read('desktop/php/panel.php'));
	}

	public function testInstallNeverRemovesEquipments(): void {
		$install = $this->read('plugin_info/install.php');
		$this->assertStringNotContainsString('->remove()', preg_replace('/\$cron->remove\(\);/', '', $install));
	}

	public function testClassKeepsTheDeprecatedSqlFallbackOut(): void {
		$class = $this->read('core/class/energy.class.php');
		$this->assertStringNotContainsString('SET sql_mode', $class);
	}

	public function testMobileAndDesktopPanelsCallTheSameAjaxEndpoint(): void {
		foreach (array('desktop/js/panel.js', 'mobile/js/panel.js') as $file) {
			$this->assertStringContainsString('plugins/energy/core/ajax/energy.ajax.php', $this->read($file), $file);
			$this->assertStringContainsString("action: 'getData'", $this->read($file), $file);
		}
	}

	public function testEveryAjaxActionCalledByTheJavascriptExists(): void {
		$ajax = $this->read('core/ajax/energy.ajax.php');
		foreach (array('desktop/js/energy.js', 'desktop/js/panel.js', 'mobile/js/panel.js') as $file) {
			preg_match_all("/action:\s*'([A-Za-z]+)'/", $this->read($file), $matches);
			foreach ($matches[1] as $action) {
				$this->assertStringContainsString("init('action') == '" . $action . "'", $ajax, $action . ' called by ' . $file);
			}
		}
	}

	/** A tooltip is a "?" in superscript after the label, never the label itself. */
	public function testTooltipsAreQuestionMarksInSuperscript(): void {
		foreach (array('desktop/js/energy.js', 'plugin_info/configuration.php') as $file) {
			$content = $this->read($file);
			$this->assertSame(0, preg_match('/<label[^>]*\btooltips\b/', $content), $file . ': tooltip on a label');
			preg_match_all('/\btooltips\b/', $content, $all);
			preg_match_all('/<sup><i class="fas fa-question-circle tooltips" title="[^"]+"><\/i><\/sup>/', $content, $ok);
			$this->assertCount(count($all[0]), $ok[0], $file . ': every tooltip must be a <sup> question mark');
		}
	}

	/** The messages of the ajax errors come wrapped in a span by Jeedom; the modals show the text, and the buttons wait for the read. */
	public function testTheModalsShowThePlainTextOfAnErrorAndWaitForTheReadOfThePeriods(): void {
		$rates = $this->read('desktop/js/rates.js');
		$this->assertStringContainsString('rates.plain(data.result)', $rates);
		$this->assertStringContainsString('rates.loaded && saved.indexOf', $rates);
		$this->assertStringContainsString('catchup.plain(data.result)', $this->read('desktop/js/catchup.js'));
	}

	/** The tariff periods table and its cost catch-up modal chain ajax calls too, and must be loaded through include_file. */
	public function testTheTariffPeriodsScriptIsLoadedAndDoesNotTriggerTheLoadingScreen(): void {
		$this->assertStringContainsString("include_file('desktop', 'rates', 'js', 'energy')", $this->read('plugin_info/configuration.php'));
		$this->assertStringContainsString('global: false', $this->read('desktop/js/rates.js'));
		$this->assertStringContainsString('id="table_ratePeriods"', $this->read('plugin_info/configuration.php'));
	}

	/** The import modal chains ajax calls: Jeedom's blurred loading screen (global ajax events) would hide its progress. */
	public function testTheCatchupAjaxCallsDoNotTriggerTheLoadingScreen(): void {
		$js = $this->read('desktop/js/catchup.js');
		$this->assertStringContainsString('global: false', $js);
		$this->assertStringContainsString('catchup.imported = _ok;', $js);
	}

	/**
	 * Jeedom only autoloads the class named like the plugin file (energy): the helper classes are loaded by energy.class.php.
	 * The ajax file uses energyCatchup, so it must load energy.class.php itself before (a "Class not found" is a silent 500).
	 */
	public function testTheAjaxFileLoadsTheClassesItUses(): void {
		$ajax = $this->read('core/ajax/energy.ajax.php');
		$this->assertStringContainsString('energyCatchup::', $ajax);
		$this->assertMatchesRegularExpression("#require_once dirname\(__FILE__\) \. '/\.\./class/energy\.class\.php';#", $ajax);
		$this->assertLessThan(strpos($ajax, 'energyCatchup::'), strpos($ajax, "energy.class.php'"), 'the class file must be loaded before the first use');
		$this->assertStringContainsString("require_once dirname(__FILE__) . '/energyCatchup.class.php';", $this->read('core/class/energy.class.php'));
	}

	/**
	 * Jeedom builds the INSERT of a new equipment (and of a command) from the properties of the eqLogic / cmd class: a
	 * property declared in class energy or energyCmd becomes a column that does not exist and no equipment can be created
	 * ("Unknown column 'nowOverride' in 'field list'"). The state of the plugin lives in energyRuntime.
	 */
	public function testTheEquipmentClassesDeclareNoProperty(): void {
		foreach (array('energy', 'energyCmd') as $class) {
			$own = array();
			foreach ((new ReflectionClass($class))->getProperties() as $property) {
				if ($property->getDeclaringClass()->getName() === $class) {
					$own[] = $property->getName();
				}
			}
			$this->assertSame(array(), $own, 'class ' . $class . ' must not declare properties: ' . implode(', ', $own));
		}
	}
}

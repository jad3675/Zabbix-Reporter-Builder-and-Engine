<?php declare(strict_types = 1);

/*
 * Shared entry point for the frontend actions, the CLI runner and the tests.
 *
 * Everything under lib/ is framework-free except Api/FrontendApi.php, which is only
 * loaded inside the Zabbix frontend. That is what lets bin/reporter.php run the exact
 * same sections over JSON-RPC.
 */

if (defined('REPORTER_ROOT')) {
	return;
}

define('REPORTER_ROOT', dirname(__DIR__));
define('REPORTER_VERSION', '1.2.1');

spl_autoload_register(static function (string $class): void {
	$prefix = 'Modules\\Reporter\\Lib\\';

	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}

	// Same convention as Zabbix's own module autoloader: namespace segments map to
	// lowercase directories, the class name keeps its case.
	$parts = explode('\\', substr($class, strlen($prefix)));
	$name = array_pop($parts);
	$file = REPORTER_ROOT.'/lib/'.($parts ? strtolower(implode('/', $parts)).'/' : '').$name.'.php';

	if (is_file($file)) {
		require_once $file;
	}
});

/**
 * Load Composer's autoloader only when PDF or mail is actually needed, so ordinary
 * page views never register third-party classes inside the Zabbix frontend.
 */
function reporter_vendor(): bool {
	static $loaded = null;

	if ($loaded === null) {
		$autoload = REPORTER_ROOT.'/vendor/autoload.php';
		$loaded = is_file($autoload);

		if ($loaded) {
			require_once $autoload;
		}
	}

	return $loaded;
}

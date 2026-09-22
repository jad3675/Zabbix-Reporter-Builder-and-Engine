<?php declare(strict_types = 1);

/*
 * JSON-RPC front for FakeApi, for exercising the CLI runner end to end:
 *   php -S 127.0.0.1:8089 tests/fake_server.php
 * Serves August 2026 data. Rejects any request without the expected Bearer token.
 */

require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/FakeApi.php';

header('Content-Type: application/json');

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-token') {
	echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32602, 'message' => 'Not authorized.', 'data' => ''], 'id' => 1]);
	return;
}

$cache = sys_get_temp_dir().'/reporter-fakeapi.ser';

if (is_file($cache)) {
	$api = unserialize((string) file_get_contents($cache));
}
else {
	$api = new FakeApi(strtotime('2026-08-01 00:00 UTC'), strtotime('2026-08-31 23:59:59 UTC'), 60);
	file_put_contents($cache, serialize($api));
}

$req = json_decode((string) file_get_contents('php://input'), true);
file_put_contents(sys_get_temp_dir().'/reporter-fakeapi.log', $req['method']."\n", FILE_APPEND);

try {
	$params = (array) $req['params'];
	$result = $api->call($req['method'], $params);

	if (!empty($params['countOutput'])) {
		$result = (string) count($result);
	}
	echo json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $req['id']]);
}
catch (Throwable $e) {
	echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32500, 'message' => 'Application error.',
		'data' => $e->getMessage()], 'id' => $req['id']]);
}

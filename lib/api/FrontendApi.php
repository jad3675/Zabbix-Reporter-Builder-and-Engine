<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Api;

/**
 * Runs API calls in-process as the logged-in frontend user, so every report shows
 * exactly what that user is permitted to see. Only loaded inside the Zabbix frontend.
 */
final class FrontendApi implements ApiClient {

	/** Object name to API service accessor. Anything not listed cannot be called. */
	private const SERVICES = [
		'host' => 'Host',
		'hostgroup' => 'HostGroup',
		'item' => 'Item',
		'trend' => 'Trend',
		'event' => 'Event',
		'alert' => 'Alert',
		'problem' => 'Problem',
		'maintenance' => 'Maintenance',
		'mediatype' => 'MediaType'
	];

	public function call(string $method, array $params): array {
		[$object, $verb] = array_pad(explode('.', $method, 2), 2, '');
		$service = self::SERVICES[$object] ?? null;

		if ($service === null || $verb !== 'get') {
			throw new ApiException(sprintf('API method "%s" is not permitted.', $method));
		}

		$result = call_user_func(['API', $service])->get($params);

		if ($result === false) {
			$messages = [];

			foreach ((array) get_and_clear_messages() as $message) {
				$messages[] = is_array($message) ? (string) ($message['message'] ?? '') : (string) $message;
			}

			throw new ApiException(sprintf('%s failed: %s', $method,
				implode('; ', array_filter($messages)) ?: 'no reason given'
			));
		}

		return is_array($result) ? $result : ['count' => $result];
	}

	public function identity(): string {
		return 'user:'.(\CWebUser::$data['userid'] ?? '0');
	}
}

<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Api;

/**
 * JSON-RPC client for the CLI runner. Authenticates with an API token (Bearer header,
 * Zabbix 6.4+). Give the token's user a read-only role scoped to the customer's groups.
 */
final class JsonRpcApi implements ApiClient {

	private string $url;
	private string $token;
	private bool $verify_tls;
	private int $timeout;
	private int $id = 0;

	public function __construct(string $url, string $token, bool $verify_tls = true, int $timeout = 120) {
		if (!preg_match('~\.php$~', $url)) {
			$url = rtrim($url, '/').'/api_jsonrpc.php';
		}

		$this->url = $url;
		$this->token = $token;
		$this->verify_tls = $verify_tls;
		$this->timeout = $timeout;
	}

	public function call(string $method, array $params): array {
		if (!function_exists('curl_init')) {
			throw new ApiException('The PHP curl extension is required for the CLI runner.');
		}

		$body = json_encode([
			'jsonrpc' => '2.0',
			'method' => $method,
			'params' => $params === [] ? new \stdClass() : $params,
			'id' => ++$this->id
		], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$ch = curl_init($this->url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json-rpc',
				'Authorization: Bearer '.$this->token
			],
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_SSL_VERIFYPEER => $this->verify_tls,
			CURLOPT_SSL_VERIFYHOST => $this->verify_tls ? 2 : 0,
			CURLOPT_ENCODING => ''
		]);

		$raw = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($raw === false) {
			throw new ApiException(sprintf('%s: transport error: %s', $method, $error));
		}

		$data = json_decode((string) $raw, true);

		if (!is_array($data)) {
			throw new ApiException(sprintf('%s: HTTP %d from %s, and the answer is not JSON. Check that the URL points at the Zabbix frontend.',
				$method, $status, $this->url));
		}

		if (isset($data['error'])) {
			throw new ApiException(sprintf('%s failed: %s %s', $method,
				(string) ($data['error']['message'] ?? ''), (string) ($data['error']['data'] ?? '')
			));
		}

		$result = $data['result'] ?? [];

		return is_array($result) ? $result : ['count' => $result];
	}

	public function identity(): string {
		return 'token:'.substr(hash('sha256', $this->token), 0, 16);
	}
}

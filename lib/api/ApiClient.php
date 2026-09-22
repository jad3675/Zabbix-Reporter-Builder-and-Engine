<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Api;

interface ApiClient {

	/**
	 * Call an API method such as "host.get" and return its result.
	 *
	 * @throws ApiException
	 */
	public function call(string $method, array $params): array;

	/**
	 * Stable identity of whoever the calls run as. Part of the cache key, because the
	 * same report returns different data for users with different permissions.
	 */
	public function identity(): string;
}

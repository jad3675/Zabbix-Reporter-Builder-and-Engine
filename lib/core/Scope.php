<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

use Modules\Reporter\Lib\Api\ApiClient;
use Modules\Reporter\Lib\Api\BudgetExceeded;

/**
 * Turns a definition's scope (host group names or wildcards, plus host tag filters) into
 * the list of monitored hosts the report covers. Resolved with the caller's permissions:
 * a user who cannot see a group gets a report without it.
 */
final class Scope {

	/**
	 * @return array{hosts: array<string, array>, groups: array<string, string>, unmatched: string[]}
	 */
	public static function resolve(ApiClient $api, array $scope, int $max_hosts): array {
		$groups = [];
		$unmatched = [];

		foreach ($scope['groups'] ?? [] as $pattern) {
			$params = ['output' => ['groupid', 'name'], 'limit' => 5000];

			if (strpos($pattern, '*') !== false) {
				$params['search'] = ['name' => $pattern];
				$params['searchWildcardsEnabled'] = true;
			}
			else {
				$params['filter'] = ['name' => $pattern];
			}

			$found = $api->call('hostgroup.get', $params);

			if (!$found) {
				$unmatched[] = $pattern;
			}

			foreach ($found as $g) {
				$groups[(string) $g['groupid']] = (string) $g['name'];
			}
		}

		if (($scope['groups'] ?? []) && !$groups) {
			throw new \RuntimeException(sprintf('No host group you can see matches: %s.', implode(', ', $unmatched)));
		}

		$params = [
			'output' => ['hostid', 'host', 'name'],
			'monitored_hosts' => true,
			'selectTags' => ['tag', 'value'],
			'selectHostGroups' => ['groupid'],
			'sortfield' => 'name',
			'limit' => $max_hosts + 1
		];

		if ($groups) {
			$params['groupids'] = array_map('strval', array_keys($groups));
		}

		if ($scope['host_tags'] ?? []) {
			$params['tags'] = TagFilter::toApi($scope['host_tags']);
			$params['evaltype'] = ($scope['tag_logic'] ?? 'and') === 'or' ? 2 : 0;
		}

		$rows = $api->call('host.get', $params);

		if (count($rows) > $max_hosts) {
			throw new BudgetExceeded(sprintf(
				'Scope matches more than %d hosts. Split the report or narrow the scope; the limit is max_hosts.',
				$max_hosts
			));
		}

		$hosts = [];

		foreach ($rows as $h) {
			$tags = [];

			foreach ($h['tags'] ?? [] as $t) {
				$tags[$t['tag']][] = (string) $t['value'];
			}

			$hosts[(string) $h['hostid']] = [
				'hostid' => (string) $h['hostid'],
				'host' => (string) $h['host'],
				'name' => (string) $h['name'],
				'tags' => $tags,
				'groupids' => array_map(static fn($g) => (string) $g['groupid'],
					$h['hostgroups'] ?? $h['groups'] ?? [])
			];
		}

		return ['hosts' => $hosts, 'groups' => $groups, 'unmatched' => $unmatched];
	}
}

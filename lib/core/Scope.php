<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

use Modules\Reporter\Lib\Api\ApiClient;
use Modules\Reporter\Lib\Api\BudgetExceeded;

/**
 * Turns a definition's scope into the monitored hosts a report covers. Resolved with the
 * caller's permissions: a user who cannot see a group gets a report without it.
 *
 * Four ways to name devices, and a report may mix them:
 *   groups          exact host group names, picked from the group list
 *   group_patterns  wildcards, e.g. Acme/* for every group under Acme
 *   hosts           individual devices, picked by name
 *   host_tags       tag filters, applied to the hosts the groups select
 *
 * Groups and tags narrow each other; individually named devices are added on top, tags
 * or not, because naming a device is an explicit "include this".
 */
final class Scope {

	/**
	 * @return array{hosts: array<string, array>, groups: array<string, string>, unmatched: string[]}
	 */
	public static function resolve(ApiClient $api, array $scope, int $max_hosts): array {
		$groups = [];
		$unmatched = [];
		$names = $scope['groups'] ?? [];
		$patterns = $scope['group_patterns'] ?? [];
		$host_names = $scope['hosts'] ?? [];

		if ($names) {
			$found = $api->call('hostgroup.get', ['output' => ['groupid', 'name'], 'filter' => ['name' => $names],
				'limit' => 5000]);

			foreach ($found as $g) {
				$groups[(string) $g['groupid']] = (string) $g['name'];
			}

			foreach (array_diff($names, array_values($groups)) as $missing) {
				$unmatched[] = $missing;
			}
		}

		foreach ($patterns as $pattern) {
			$found = $api->call('hostgroup.get', ['output' => ['groupid', 'name'], 'search' => ['name' => $pattern],
				'searchWildcardsEnabled' => true, 'limit' => 5000]);

			if (!$found) {
				$unmatched[] = $pattern;
			}

			foreach ($found as $g) {
				$groups[(string) $g['groupid']] = (string) $g['name'];
			}
		}

		if (($names || $patterns) && !$groups) {
			throw new \RuntimeException(sprintf('No host group you can see matches: %s.', implode(', ', $unmatched)));
		}

		$common = [
			'output' => ['hostid', 'host', 'name'],
			'monitored_hosts' => true,
			'selectTags' => ['tag', 'value'],
			'selectHostGroups' => ['groupid'],
			'sortfield' => 'name',
			'limit' => $max_hosts + 1
		];

		$rows = [];

		if ($groups || ($scope['host_tags'] ?? []) || (!$groups && !$host_names)) {
			$params = $common;

			if ($groups) {
				$params['groupids'] = array_map('strval', array_keys($groups));
			}

			if ($scope['host_tags'] ?? []) {
				$params['tags'] = TagFilter::toApi($scope['host_tags']);
				$params['evaltype'] = ($scope['tag_logic'] ?? 'and') === 'or' ? 2 : 0;
			}

			$rows = $api->call('host.get', $params);
		}

		if ($host_names) {
			$named = $api->call('host.get', $common + ['filter' => ['name' => $host_names]]);
			$seen = [];

			foreach ($named as $h) {
				$seen[] = (string) $h['name'];
			}

			foreach (array_diff($host_names, $seen) as $missing) {
				$unmatched[] = $missing;
			}

			$rows = array_merge($rows, $named);
		}

		$hosts = [];

		foreach ($rows as $h) {
			$hostid = (string) $h['hostid'];

			if (isset($hosts[$hostid])) {
				continue;
			}

			$tags = [];

			foreach ($h['tags'] ?? [] as $t) {
				$tags[$t['tag']][] = (string) $t['value'];
			}

			$hosts[$hostid] = [
				'hostid' => $hostid,
				'host' => (string) $h['host'],
				'name' => (string) $h['name'],
				'tags' => $tags,
				'groupids' => array_map(static fn($g) => (string) $g['groupid'],
					$h['hostgroups'] ?? $h['groups'] ?? [])
			];
		}

		if (count($hosts) > $max_hosts) {
			throw new BudgetExceeded(sprintf(
				'Scope matches more than %d hosts. Split the report or narrow the scope; the limit is max_hosts.',
				$max_hosts
			));
		}

		uasort($hosts, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

		return ['hosts' => $hosts, 'groups' => $groups, 'unmatched' => array_values(array_unique($unmatched))];
	}
}

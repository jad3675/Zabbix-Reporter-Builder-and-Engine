<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use Modules\Reporter\Lib\Api\ApiException;
use Modules\Reporter\Lib\Core\Context;

/**
 * Turns the ids that show up in audit details into names: host group 197 becomes
 * "Servers/Linux". One batched call per object type, ids chunked, results memoized for
 * the whole report. A type the caller cannot read is skipped rather than failing the
 * section, so a missing permission costs a name, not the report.
 */
final class Names {

	/** type => [api method, id parameter, name field] */
	private const SOURCES = [
		'hostgroup' => ['hostgroup.get', 'groupids', 'name'],
		'templategroup' => ['templategroup.get', 'groupids', 'name'],
		'host' => ['host.get', 'hostids', 'name'],
		'template' => ['template.get', 'templateids', 'name'],
		'item' => ['item.get', 'itemids', 'name'],
		'trigger' => ['trigger.get', 'triggerids', 'description'],
		'usergroup' => ['usergroup.get', 'usrgrpids', 'name'],
		'user' => ['user.get', 'userids', 'username'],
		'mediatype' => ['mediatype.get', 'mediatypeids', 'name'],
		'proxy' => ['proxy.get', 'proxyids', 'name'],
		'action' => ['action.get', 'actionids', 'name'],
		'script' => ['script.get', 'scriptids', 'name'],
		'maintenance' => ['maintenance.get', 'maintenanceids', 'name'],
		'dashboard' => ['dashboard.get', 'dashboardids', 'name'],
		'service' => ['service.get', 'serviceids', 'name']
	];

	/**
	 * @param array<string, string[]> $wanted  type => ids
	 * @return array<string, array<string, string>> type => id => name
	 */
	public static function resolve(Context $ctx, array $wanted): array {
		$out = [];

		foreach ($wanted as $type => $ids) {
			if (!isset(self::SOURCES[$type]) || !$ids) {
				continue;
			}

			[$method, $param, $field] = self::SOURCES[$type];
			$id_field = rtrim($param, 's');
			$id_field = $type === 'hostgroup' || $type === 'templategroup' ? 'groupid'
				: ($type === 'usergroup' ? 'usrgrpid' : $id_field);
			$names = [];

			foreach ($ctx->chunks(array_values(array_unique($ids)), $ctx->limit('chunk_ids', 1000)) as $chunk) {
				try {
					foreach ($ctx->api->call($method, [$param => $chunk, 'output' => [$id_field, $field]]) as $row) {
						$names[(string) $row[$id_field]] = (string) $row[$field];
					}
				}
				catch (ApiException $e) {
					// No permission for this object type, or it does not exist here.
					break;
				}
			}

			$out[$type] = $names;
		}

		return $out;
	}
}

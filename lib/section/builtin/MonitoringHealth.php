<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * The state of the monitoring itself: items Zabbix could not collect, and interfaces it
 * could not reach, at the time the report is produced. This is the section that shows a
 * customer the service is being maintained rather than just running.
 */
final class MonitoringHealth extends AbstractSection {

	private const INTERFACE_TYPES = [1 => 'Agent', 2 => 'SNMP', 3 => 'IPMI', 4 => 'JMX'];

	public function type(): string {
		return 'monitoring_health';
	}

	public function label(): string {
		return 'Monitoring health';
	}

	public function description(): string {
		return 'Items Zabbix cannot collect and interfaces it cannot reach right now, by device. A clean list means the data behind this report is complete.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'show_items', 'label' => 'List unsupported items', 'type' => 'bool', 'default' => true],
			['name' => 'show_interfaces', 'label' => 'List unreachable interfaces', 'type' => 'bool', 'default' => true],
			['name' => 'display_limit', 'label' => 'Rows in the PDF', 'type' => 'int', 'default' => 25, 'min' => 5,
				'max' => 500]
		], self::commonOptions(false));
	}

	public function run(Context $ctx, array $o): SectionResult {
		$hosts = $this->hosts($ctx, $o);
		$hostids = array_map('strval', array_keys($hosts));
		$unsupported = [];
		$interfaces = [];

		foreach ($ctx->chunks($hostids, $ctx->limit('chunk_hosts', 500)) as $chunk) {
			foreach ($ctx->api->call('item.get', [
				'output' => ['itemid', 'hostid', 'name', 'key_', 'error'],
				'hostids' => $chunk,
				'monitored' => true,
				'filter' => ['state' => 1],
				'limit' => $ctx->limit('max_items', 20000)
			]) as $item) {
				$unsupported[] = [
					'host' => $ctx->hostName($item['hostid']),
					'item' => $item['name'],
					'key' => $item['key_'],
					'error' => mb_substr((string) $item['error'], 0, 300)
				];
			}

			if (!$o['show_interfaces']) {
				continue;
			}

			foreach ($ctx->api->call('host.get', [
				'output' => ['hostid'],
				'hostids' => $chunk,
				'selectInterfaces' => ['type', 'ip', 'dns', 'useip', 'port', 'available', 'error']
			]) as $host) {
				foreach ($host['interfaces'] ?? [] as $i) {
					if ((int) $i['available'] !== 2) {
						continue;
					}

					$interfaces[] = [
						'host' => $ctx->hostName($host['hostid']),
						'type' => self::INTERFACE_TYPES[(int) $i['type']] ?? 'Other',
						'address' => ((int) $i['useip'] === 1 ? $i['ip'] : $i['dns']).':'.$i['port'],
						'error' => mb_substr((string) $i['error'], 0, 300)
					];
				}
			}
		}

		$by_host = count(array_unique(array_column($unsupported, 'host')));
		$result = (new SectionResult())->kpis([
			['label' => 'Devices monitored', 'value' => count($hosts), 'format' => 'int'],
			['label' => 'Items not collecting', 'value' => count($unsupported), 'format' => 'int',
				'hint' => $by_host > 0 ? sprintf('on %d device(s)', $by_host) : ''],
			['label' => 'Interfaces unreachable', 'value' => count($interfaces), 'format' => 'int']
		]);

		usort($unsupported, static fn($a, $b) => [$a['host'], $a['item']] <=> [$b['host'], $b['item']]);
		usort($interfaces, static fn($a, $b) => $a['host'] <=> $b['host']);

		if ($o['show_items']) {
			$result->table('Items not collecting', [
				['key' => 'host', 'label' => 'Device'],
				['key' => 'item', 'label' => 'Item'],
				['key' => 'key', 'label' => 'Key', 'screen' => false],
				['key' => 'error', 'label' => 'Reason']
			], $unsupported, ['display_limit' => $o['display_limit'], 'sheet' => 'Items not collecting',
				'empty' => 'Every item on these devices is collecting data.']);
		}

		if ($o['show_interfaces']) {
			$result->table('Interfaces unreachable', [
				['key' => 'host', 'label' => 'Device'],
				['key' => 'type', 'label' => 'Interface'],
				['key' => 'address', 'label' => 'Address'],
				['key' => 'error', 'label' => 'Reason']
			], $interfaces, ['display_limit' => $o['display_limit'], 'sheet' => 'Interfaces unreachable',
				'empty' => 'Every interface on these devices is reachable.']);
		}

		return $result->note('This is the state now, not during the period: Zabbix keeps the current error only.');
	}
}

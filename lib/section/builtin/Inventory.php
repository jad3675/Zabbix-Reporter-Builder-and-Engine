<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * What is under management, counted by one inventory field: vendor, model, OS, site,
 * whatever the customer's inventory is filled in with. Answers "what are we paying you
 * to watch" without anyone exporting a spreadsheet by hand.
 */
final class Inventory extends AbstractSection {

	private const FIELDS = [
		'type' => 'Type',
		'type_full' => 'Type (detailed)',
		'vendor' => 'Vendor',
		'model' => 'Model',
		'os' => 'Operating system',
		'os_short' => 'Operating system (short)',
		'hardware' => 'Hardware',
		'software' => 'Software',
		'location' => 'Location',
		'site_city' => 'Site city',
		'contact' => 'Contact',
		'tag' => 'Inventory tag',
		'chassis' => 'Chassis',
		'serialno_a' => 'Serial number'
	];

	public function type(): string {
		return 'inventory';
	}

	public function label(): string {
		return 'Inventory summary';
	}

	public function description(): string {
		return 'Devices under management, grouped by an inventory field.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'field', 'label' => 'Group by', 'type' => 'select', 'default' => 'type',
				'choices' => self::FIELDS],
			['name' => 'second_field', 'label' => 'Also show', 'type' => 'select', 'default' => '',
				'choices' => ['' => 'Nothing'] + self::FIELDS,
				'hint' => 'Adds a device list with this field, in the spreadsheet.'],
			['name' => 'chart', 'label' => 'Bar chart', 'type' => 'bool', 'default' => true],
			['name' => 'list_devices', 'label' => 'List every device', 'type' => 'bool', 'default' => false],
			['name' => 'display_limit', 'label' => 'Rows in the PDF', 'type' => 'int', 'default' => 25, 'min' => 5,
				'max' => 500]
		], self::commonOptions(false));
	}

	public function run(Context $ctx, array $o): SectionResult {
		$hosts = $this->hosts($ctx, $o);
		$field = (string) $o['field'];
		$second = (string) $o['second_field'];
		$select = array_values(array_unique(array_filter([$field, $second])));
		$inventory = [];

		foreach ($ctx->chunks(array_map('strval', array_keys($hosts)), $ctx->limit('chunk_hosts', 500)) as $chunk) {
			foreach ($ctx->api->call('host.get', [
				'output' => ['hostid'],
				'hostids' => $chunk,
				'selectInventory' => $select
			]) as $host) {
				$inventory[(string) $host['hostid']] = is_array($host['inventory'] ?? null) ? $host['inventory'] : [];
			}
		}

		$counts = [];
		$devices = [];

		foreach ($hosts as $hostid => $host) {
			$value = trim((string) ($inventory[(string) $hostid][$field] ?? ''));
			$label = $value !== '' ? $value : 'Not set';
			$counts[$label] = ($counts[$label] ?? 0) + 1;
			$devices[] = [
				'host' => $host['name'],
				'value' => $label,
				'second' => trim((string) ($inventory[(string) $hostid][$second] ?? ''))
			];
		}

		arsort($counts);
		$rows = [];
		$total = max(1, count($hosts));

		foreach ($counts as $label => $count) {
			$rows[] = ['value' => $label, 'devices' => $count, 'share' => 100 * $count / $total];
		}

		$filled = count($hosts) - ($counts['Not set'] ?? 0);
		$result = (new SectionResult())->kpis([
			['label' => 'Devices', 'value' => count($hosts), 'format' => 'int'],
			['label' => self::FIELDS[$field].' values', 'value' => count(array_diff_key($counts, ['Not set' => 0])),
				'format' => 'int'],
			['label' => 'With this field filled in', 'value' => $total > 0 ? 100 * $filled / $total : null,
				'format' => 'pct1', 'hint' => sprintf('%d of %d', $filled, count($hosts))]
		]);

		if ($o['chart'] && $rows) {
			$result->chart('hbar', '', array_map(static fn($r) => ['label' => $r['value'], 'value' => $r['devices']],
				array_slice($rows, 0, 10)), ['format' => 'int']);
		}

		$result->table(self::FIELDS[$field], [
			['key' => 'value', 'label' => self::FIELDS[$field]],
			['key' => 'devices', 'label' => 'Devices', 'format' => 'int'],
			['key' => 'share', 'label' => 'Share', 'format' => 'pct1']
		], $rows, ['display_limit' => $o['display_limit'], 'sheet' => 'Inventory by '.$field,
			'empty' => 'No devices in scope.']);

		if ($o['list_devices']) {
			usort($devices, static fn($a, $b) => [$a['value'], $a['host']] <=> [$b['value'], $b['host']]);
			$columns = [
				['key' => 'host', 'label' => 'Device'],
				['key' => 'value', 'label' => self::FIELDS[$field]]
			];

			if ($second !== '') {
				$columns[] = ['key' => 'second', 'label' => self::FIELDS[$second]];
			}

			$result->table('Devices', $columns, $devices, ['display_limit' => $o['display_limit'],
				'sheet' => 'Inventory devices']);
		}

		return $result->note('Read from host inventory. Devices whose inventory is not filled in show as "Not set".');
	}
}

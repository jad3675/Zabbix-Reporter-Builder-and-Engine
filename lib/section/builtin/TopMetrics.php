<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * Top (or bottom) N for any numeric metric: highest average CPU, busiest links, warmest
 * inlets. Items are chosen by tag, key or name, so the same section works for agent and
 * SNMP templates. Two passes: period aggregates for every candidate item, then daily
 * series only for the winners.
 */
final class TopMetrics extends AbstractSection {

	public function type(): string {
		return 'top_metrics';
	}

	public function label(): string {
		return 'Top metrics';
	}

	public function description(): string {
		return 'Metrics over the period, from hourly trend data.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'layout', 'label' => 'Layout', 'type' => 'select', 'default' => 'ranking',
				'choices' => ['ranking' => 'One ranking across every device',
					'per_device' => 'Top items for each device']],
			['name' => 'item_tags', 'label' => 'Item tags', 'type' => 'tags', 'default' => 'component=cpu',
				'hint' => 'One per line: tag, tag=value, tag~contains, !tag'],
			['name' => 'tag_logic', 'label' => 'Tag logic', 'type' => 'select', 'default' => 'and',
				'choices' => ['and' => 'An item must have all of them', 'or' => 'Any one of them is enough'],
				'hint' => 'Use "any" for component=cpu plus component=memory on the same section.'],
			['name' => 'keys', 'label' => 'Item key patterns', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated wildcards, e.g. system.cpu.util*, *cpu.util[*]'],
			['name' => 'names', 'label' => 'Item name patterns', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated wildcards, used when no key pattern is set'],
			['name' => 'aggregate', 'label' => 'Rank by', 'type' => 'select', 'default' => 'avg',
				'choices' => ['avg' => 'Average over the period', 'max' => 'Peak over the period',
					'last' => 'Latest hourly average']],
			['name' => 'order', 'label' => 'Order', 'type' => 'select', 'default' => 'desc',
				'choices' => ['desc' => 'Highest first', 'asc' => 'Lowest first']],
			['name' => 'per_host', 'label' => 'One row per device', 'type' => 'bool', 'default' => true,
				'hint' => 'Ranking layout: when a device has several matching items, keep the one that ranks highest.'],
			['name' => 'limit', 'label' => 'Rows', 'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 200,
				'hint' => 'Ranking layout: rows in total. Per-device layout: devices listed.'],
			['name' => 'per_device_limit', 'label' => 'Items per device', 'type' => 'int', 'default' => 3, 'min' => 1,
				'max' => 50, 'hint' => 'Per-device layout only.'],
			['name' => 'device_order', 'label' => 'Order devices by', 'type' => 'select', 'default' => 'value',
				'choices' => ['value' => 'Their highest value first', 'name' => 'Name'],
				'hint' => 'Per-device layout only.'],
			['name' => 'group_items', 'label' => 'Keep each tag or pattern separate', 'type' => 'bool',
				'default' => true,
				'hint' => 'Per-device layout: with several tags or patterns, take the top items for each one instead of ranking them against each other. Percentages and bytes do not compare.'],
			['name' => 'sparkline', 'label' => 'Daily trend line', 'type' => 'bool', 'default' => true],
			['name' => 'chart', 'label' => 'Bar chart', 'type' => 'bool', 'default' => true],
			['name' => 'min_value', 'label' => 'Ignore values below', 'type' => 'float', 'default' => 0,
				'hint' => 'Keeps idle devices out of a "highest first" list.'],
			['name' => 'units', 'label' => 'Units override', 'type' => 'text', 'default' => '',
				'hint' => 'Leave empty to use the item\'s own units.']
		], self::commonOptions(false));
	}

	public function validateOptions(array $o): array {
		return (!$o['item_tags'] && $o['keys'] === '' && $o['names'] === '')
			? ['Set item tags, a key pattern or a name pattern.']
			: [];
	}

	/** Which of the section's tags or patterns this item matched. */
	private function selectorOf(array $item, array $o, array $selectors, bool $match_keys): string {
		if ($o['item_tags']) {
			foreach ($o['item_tags'] as $i => $filter) {
				foreach ($item['tags'] ?? [] as $tag) {
					if ($tag['tag'] !== $filter['tag']) {
						continue;
					}

					$value = (string) $tag['value'];

					switch ($filter['operator']) {
						case 'equals': $hit = $value === $filter['value']; break;
						case 'contains': $hit = $filter['value'] === '' || stripos($value, $filter['value']) !== false; break;
						case 'not_equals': $hit = $value !== $filter['value']; break;
						case 'not_exists': $hit = false; break;
						default: $hit = true;
					}

					if ($hit) {
						return $selectors[$i];
					}
				}
			}

			return '';
		}

		$subject = $match_keys ? $item['key'] : $item['name'];

		foreach ($selectors as $pattern) {
			if (fnmatch($pattern, $subject, FNM_CASEFOLD | FNM_NOESCAPE)) {
				return $pattern;
			}
		}

		return '';
	}

	public function run(Context $ctx, array $o): SectionResult {
		$items = $ctx->items(['tags' => $o['item_tags'], 'keys' => $o['keys'], 'names' => $o['names'],
			'tag_logic' => $o['tag_logic']]);
		$hosts = $this->hosts($ctx, $o);
		$items = array_filter($items, static fn($i) => isset($hosts[$i['hostid']]));
		$result = new SectionResult();

		if (!$items) {
			return $result->text('No items on the devices in scope match this selector.');
		}

		$summary = $ctx->trendSummary(array_keys($items));
		$agg = $o['aggregate'];
		// With several tags or patterns, each item is labelled with the one that matched,
		// so the per-device layout can keep them apart. Ranking a CPU percentage against
		// a memory figure in bytes only ever ranks the bytes.
		$selectors = $o['item_tags']
			? array_map(static fn($t) => $t['value'] !== '' ? $t['tag'].'='.$t['value'] : $t['tag'], $o['item_tags'])
			: self::patterns($o['keys'] !== '' ? $o['keys'] : $o['names']);
		$match_keys = $o['keys'] !== '';

		// One row per matching item, then either ranked together or grouped by device.
		$rows = [];

		foreach ($summary as $itemid => $stats) {
			if (!isset($items[$itemid])) {
				continue;
			}

			$item = $items[$itemid];
			$value = $stats[$agg];

			if ($value < (float) $o['min_value']) {
				continue;
			}

			$rows[] = [
				'itemid' => $itemid,
				'hostid' => $item['hostid'],
				'group' => $this->selectorOf($item, $o, $selectors, $match_keys),
				'host' => $ctx->hostName($item['hostid']),
				'item' => $item['name'],
				'value' => $value,
				'avg' => $stats['avg'],
				'max' => $stats['max'],
				'units' => $o['units'] !== '' ? $o['units'] : $item['units']
			];
		}

		$desc = $o['order'] === 'desc';
		$rank = static fn(array $a, array $b) => $desc ? $b['value'] <=> $a['value'] : $a['value'] <=> $b['value'];
		$per_device = $o['layout'] === 'per_device';

		$grouped = $per_device && $o['group_items'] && count($selectors) > 1;

		if ($per_device) {
			$by_host = [];

			foreach ($rows as $row) {
				$by_host[$row['hostid']][] = $row;
			}

			foreach ($by_host as &$group) {
				usort($group, $rank);

				if ($grouped) {
					// Top N for each tag or pattern, in the order they were written.
					$kept = [];

					foreach ($selectors as $selector) {
						$of_selector = array_values(array_filter($group,
							static fn($r) => $r['group'] === $selector));
						$kept = array_merge($kept, array_slice($of_selector, 0, (int) $o['per_device_limit']));
					}

					$group = $kept;
				}
				else {
					$group = array_slice($group, 0, (int) $o['per_device_limit']);
				}
			}
			unset($group);

			$by_host = array_filter($by_host);

			uasort($by_host, static function ($a, $b) use ($o, $rank) {
				return $o['device_order'] === 'name'
					? strnatcasecmp($a[0]['host'], $b[0]['host'])
					: $rank($a[0], $b[0]);
			});

			$top = [];

			foreach (array_slice($by_host, 0, (int) $o['limit'], true) as $group) {
				foreach ($group as $i => $row) {
					// The device name is printed once per device on screen; the export
					// keeps it on every row so the spreadsheet can be sorted.
					$row['host_display'] = $i === 0 ? $row['host'] : '';
					$top[] = $row;
				}
			}
		}
		else {
			usort($rows, $rank);
			$top = array_slice($rows, 0, (int) $o['limit']);
		}

		if ($o['sparkline'] && $top) {
			$daily = $ctx->trendDaily(array_column($top, 'itemid'));
			$days = count($ctx->period->dayStarts());

			foreach ($top as &$row) {
				$series = array_fill(0, $days, null);

				foreach ($daily[$row['itemid']] ?? [] as $d => $v) {
					$series[$d] = $v;
				}

				$row['trend'] = $series;
			}
			unset($row);
		}

		if ($o['chart'] && $top) {
			// Per device, the chart compares each device's best item.
			$chart_rows = $per_device
				? array_values(array_filter($top, static fn($r) => $r['host_display'] !== ''))
				: $top;
			$result->chart('hbar', '', array_map(static fn($r) => [
				'label' => $r['host'], 'value' => $r['value'], 'units' => $r['units']
			], array_slice($chart_rows, 0, 10)), ['format' => 'units']);
		}

		$columns = $per_device
			? [
				['key' => 'host_display', 'label' => 'Device', 'export' => false],
				['key' => 'host', 'label' => 'Device', 'screen' => false]
			]
			: [['key' => 'host', 'label' => 'Device']];

		if ($grouped) {
			$columns[] = ['key' => 'group', 'label' => 'Group'];
		}

		$columns[] = ['key' => 'item', 'label' => 'Item'];
		$columns[] = ['key' => 'avg', 'label' => 'Average', 'format' => 'units'];
		$columns[] = ['key' => 'max', 'label' => 'Peak', 'format' => 'units'];

		if ($agg === 'last') {
			$columns[] = ['key' => 'value', 'label' => 'Latest', 'format' => 'units'];
		}

		if ($o['sparkline']) {
			$columns[] = ['key' => 'trend', 'label' => 'Daily average', 'format' => 'spark', 'export' => false];
		}

		$columns[] = ['key' => 'units', 'label' => 'Units', 'screen' => false];

		$result->table($per_device ? 'By device' : 'Ranking', $columns, $top,
			['sheet' => mb_substr('Top metrics '.($o['keys'] ?: 'items'), 0, 31)]);

		if ($per_device) {
			$devices = count(array_unique(array_column($rows, 'hostid')));
			$shown = count(array_filter($top, static fn($r) => $r['host_display'] !== ''));

			if ($devices > $shown) {
				$result->note(sprintf('%d device(s) matched; the %d with the highest values are listed.', $devices, $shown));
			}
		}

		$without = count($items) - count($summary);
		$result->note(sprintf('%d matching item(s) considered.%s', count($items),
			$without > 0 ? sprintf(' %d had no trend data in the period.', $without) : ''));

		return $result;
	}
}

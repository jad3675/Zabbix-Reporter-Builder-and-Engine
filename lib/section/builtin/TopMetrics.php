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
		return 'Ranks devices by a metric over the period, from hourly trend data.';
	}

	public function options(): array {
		return [
			['name' => 'item_tags', 'label' => 'Item tags', 'type' => 'tags', 'default' => 'component=cpu',
				'hint' => 'One per line: tag, tag=value, tag~contains, !tag'],
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
				'hint' => 'When a device has several matching items, keep the one that ranks highest.'],
			['name' => 'limit', 'label' => 'Rows', 'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 200],
			['name' => 'sparkline', 'label' => 'Daily trend line', 'type' => 'bool', 'default' => true],
			['name' => 'chart', 'label' => 'Bar chart', 'type' => 'bool', 'default' => true]
		];
	}

	public function validateOptions(array $o): array {
		return (!$o['item_tags'] && $o['keys'] === '' && $o['names'] === '')
			? ['Set item tags, a key pattern or a name pattern.']
			: [];
	}

	public function run(Context $ctx, array $o): SectionResult {
		$items = $ctx->items(['tags' => $o['item_tags'], 'keys' => $o['keys'], 'names' => $o['names']]);
		$result = new SectionResult();

		if (!$items) {
			return $result->text('No items on the devices in scope match this selector.');
		}

		$summary = $ctx->trendSummary(array_keys($items));
		$agg = $o['aggregate'];
		$candidates = [];

		foreach ($summary as $itemid => $s) {
			$item = $items[$itemid];
			$value = $s[$agg];

			if ($o['per_host']) {
				$h = $item['hostid'];
				$better = !isset($candidates[$h])
					|| ($o['order'] === 'desc' ? $value > $candidates[$h]['value'] : $value < $candidates[$h]['value']);

				if (!$better) {
					continue;
				}

				$key = $h;
			}
			else {
				$key = $itemid;
			}

			$candidates[$key] = [
				'itemid' => $itemid,
				'host' => $ctx->hostName($item['hostid']),
				'item' => $item['name'],
				'value' => $value,
				'avg' => $s['avg'],
				'max' => $s['max'],
				'units' => $item['units']
			];
		}

		$desc = $o['order'] === 'desc';
		usort($candidates, static fn($a, $b) => $desc ? $b['value'] <=> $a['value'] : $a['value'] <=> $b['value']);
		$top = array_slice($candidates, 0, $o['limit']);

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
			$result->chart('hbar', '', array_map(static fn($r) => [
				'label' => $r['host'], 'value' => $r['value'], 'units' => $r['units']
			], array_slice($top, 0, 10)), ['format' => 'units']);
		}

		$columns = [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'item', 'label' => 'Item'],
			['key' => 'avg', 'label' => 'Average', 'format' => 'units'],
			['key' => 'max', 'label' => 'Peak', 'format' => 'units']
		];

		if ($agg === 'last') {
			$columns[] = ['key' => 'value', 'label' => 'Latest', 'format' => 'units'];
		}

		if ($o['sparkline']) {
			$columns[] = ['key' => 'trend', 'label' => 'Daily average', 'format' => 'spark', 'export' => false];
		}

		$columns[] = ['key' => 'units', 'label' => 'Units', 'screen' => false];

		$result->table('Ranking', $columns, $top, ['sheet' => mb_substr('Top metrics '.($o['keys'] ?: 'items'), 0, 31)]);

		$without = count($items) - count($summary);
		$result->note(sprintf('%d matching item(s) considered.%s', count($items),
			$without > 0 ? sprintf(' %d had no trend data in the period.', $without) : ''));

		return $result;
	}
}

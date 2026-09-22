<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Core\Stats;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * Days until full. Fits a straight line through each volume's daily average utilization
 * and projects when it crosses the threshold. Deliberately simple and explained in the
 * report, because a customer will act on these dates.
 */
final class CapacityGrowth extends AbstractSection {

	public function type(): string {
		return 'capacity_growth';
	}

	public function label(): string {
		return 'Capacity outlook';
	}

	public function description(): string {
		return 'Projects when volumes will cross the utilization threshold, from a straight-line fit of daily averages.';
	}

	public function options(): array {
		return [
			['name' => 'item_tags', 'label' => 'Item tags', 'type' => 'tags', 'default' => 'component=storage'],
			['name' => 'keys', 'label' => 'Item key patterns', 'type' => 'text', 'default' => '*pused*',
				'hint' => 'Items must report percent used, e.g. vfs.fs.dependent.size[*,pused]'],
			['name' => 'names', 'label' => 'Item name patterns', 'type' => 'text', 'default' => ''],
			['name' => 'threshold', 'label' => 'Threshold (%)', 'type' => 'float', 'default' => 90, 'min' => 1,
				'max' => 100],
			['name' => 'horizon', 'label' => 'List volumes crossing within (days)', 'type' => 'int', 'default' => 180,
				'min' => 7, 'max' => 1825],
			['name' => 'min_days', 'label' => 'Minimum days of data', 'type' => 'int', 'default' => 7, 'min' => 3,
				'max' => 60],
			['name' => 'limit', 'label' => 'Rows', 'type' => 'int', 'default' => 25, 'min' => 5, 'max' => 500]
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

		$daily = $ctx->trendDaily(array_keys($items));
		$threshold = (float) $o['threshold'];
		$now_day = count($ctx->period->dayStarts()) - 1;
		$rows = [];
		$over = 0;
		$growing = 0;
		$insufficient = 0;

		foreach ($daily as $itemid => $series) {
			if (count($series) < $o['min_days']) {
				$insufficient++;

				continue;
			}

			$fit = Stats::linreg(array_map('floatval', array_keys($series)), array_values($series));

			if ($fit === null) {
				continue;
			}

			$current = end($series);
			$slope = $fit['slope'];
			$days_left = null;

			if ($current >= $threshold) {
				$days_left = 0.0;
				$over++;
			}
			elseif ($slope > 0.0001) {
				$fitted_now = $fit['slope'] * $now_day + $fit['intercept'];
				$days_left = max(0.0, ($threshold - max($current, $fitted_now)) / $slope);
				$growing++;
			}

			if ($days_left === null || $days_left > $o['horizon']) {
				continue;
			}

			$item = $items[$itemid];
			$rows[] = [
				'host' => $ctx->hostName($item['hostid']),
				'item' => $item['name'],
				'current' => $current,
				'growth30' => $slope * 30,
				'days_left' => $days_left,
				'date' => $ctx->period->till + (int) round($days_left * 86400),
				'fit' => $fit['r2'],
				'trend' => array_values($series)
			];
		}

		usort($rows, static fn($a, $b) => [$a['days_left'], -$a['current']] <=> [$b['days_left'], -$b['current']]);

		$result->kpis([
			['label' => 'Volumes analysed', 'value' => count($daily) - $insufficient, 'format' => 'int'],
			['label' => 'Already over threshold', 'value' => $over, 'format' => 'int'],
			['label' => sprintf('Crossing within %d days', $o['horizon']),
				'value' => count($rows) - $over, 'format' => 'int']
		]);

		$result->table('Volumes', [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'item', 'label' => 'Volume'],
			['key' => 'current', 'label' => 'Used', 'format' => 'pct1'],
			['key' => 'growth30', 'label' => '30-day growth', 'format' => 'pp'],
			['key' => 'days_left', 'label' => 'Days left', 'format' => 'int'],
			['key' => 'date', 'label' => 'Crosses on', 'format' => 'date'],
			['key' => 'fit', 'label' => 'Fit (R²)', 'format' => 'number2'],
			['key' => 'trend', 'label' => 'Trend', 'format' => 'spark', 'export' => false]
		], $rows, [
			'display_limit' => $o['limit'],
			'sheet' => 'Capacity outlook',
			'empty' => sprintf('No volume is over %s%% or projected to cross it within %d days.',
				rtrim(rtrim(number_format($threshold, 1), '0'), '.'), $o['horizon'])
		]);

		$result->note(sprintf('Threshold %s%%. Growth is in percentage points. Projection is a straight line through daily averages; a low fit (R²) means the growth is irregular and the date is a rough guide.',
			rtrim(rtrim(number_format($threshold, 1), '0'), '.')));

		if ($insufficient > 0) {
			$result->note(sprintf('%d volume(s) had fewer than %d days of data and were not projected.', $insufficient,
				$o['min_days']));
		}

		return $result;
	}
}

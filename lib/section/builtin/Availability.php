<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * Reachability from ICMP ping trends. icmpping returns 1 or 0, so the trend's hourly
 * average is the fraction of checks that answered, and the period average weighted by
 * sample count is availability.
 */
final class Availability extends AbstractSection {

	public function type(): string {
		return 'availability';
	}

	public function label(): string {
		return 'Availability';
	}

	public function description(): string {
		return 'Share of ICMP ping checks each device answered during the period.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'keys', 'label' => 'Ping item key patterns', 'type' => 'text', 'default' => 'icmpping,icmpping[*]'],
			['name' => 'target', 'label' => 'Target (%)', 'type' => 'float', 'default' => 99.9, 'min' => 50,
				'max' => 100],
			['name' => 'show', 'label' => 'Devices listed', 'type' => 'select', 'default' => 'below',
				'choices' => ['below' => 'Below target', 'worst' => 'Lowest availability', 'all' => 'All']],
			['name' => 'display_limit', 'label' => 'Rows in the PDF', 'type' => 'int', 'default' => 25, 'min' => 5,
				'max' => 500],
			['name' => 'no_data', 'label' => 'Devices without ping data', 'type' => 'select', 'default' => 'exclude',
				'choices' => ['exclude' => 'Leave out of the average', 'zero' => 'Count as 0% available',
					'list' => 'Leave out, but list them']]
		], self::commonOptions(false));
	}

	public function validateOptions(array $o): array {
		return $o['keys'] === '' ? ['Set the ping item key pattern.'] : [];
	}

	public function run(Context $ctx, array $o): SectionResult {
		$hosts = $this->hosts($ctx, $o);
		$items = array_filter($ctx->items(['tags' => [], 'keys' => $o['keys'], 'names' => '']),
			static fn($i) => isset($hosts[$i['hostid']]));
		$summary = $items ? $ctx->trendSummary(array_keys($items)) : [];
		$period_hours = $ctx->period->seconds() / 3600;
		$target = (float) $o['target'];

		// If a device has several ping items, the worst one speaks for it.
		$per_host = [];

		foreach ($summary as $itemid => $s) {
			$h = $items[$itemid]['hostid'];
			$avail = 100 * max(0.0, min(1.0, $s['avg']));

			if (!isset($per_host[$h]) || $avail < $per_host[$h]['availability']) {
				$coverage = min(1.0, $s['hours'] / max(1, $period_hours));
				$per_host[$h] = [
					'host' => $ctx->hostName($h),
					'availability' => $avail,
					'downtime' => (1 - $avail / 100) * $ctx->period->seconds() * $coverage,
					'coverage' => 100 * $coverage
				];
			}
		}

		$missing = array_diff_key($hosts, $per_host);
		$no_data = count($missing);

		if ($o['no_data'] === 'zero') {
			foreach ($missing as $hostid => $host) {
				$per_host[$hostid] = ['host' => $host['name'], 'availability' => 0.0,
					'downtime' => $ctx->period->seconds(), 'coverage' => 0.0];
			}
		}
		elseif ($o['no_data'] === 'list') {
			foreach ($missing as $hostid => $host) {
				$per_host[$hostid] = ['host' => $host['name'], 'availability' => null, 'downtime' => null,
					'coverage' => 0.0];
			}
		}
		$measured = array_filter($per_host, static fn($r) => $r['availability'] !== null);
		$below = array_filter($measured, static fn($r) => $r['availability'] < $target);
		$met = count($measured) - count($below);
		$mean = $measured ? array_sum(array_column($measured, 'availability')) / count($measured) : null;

		$result = (new SectionResult())->kpis([
			['label' => 'Average availability', 'value' => $mean, 'format' => 'pct3'],
			['label' => sprintf('Devices at or above %s%%', self::num($target)), 'value' => $met, 'format' => 'int',
				'hint' => sprintf('of %d measured', count($measured))],
			['label' => 'Devices below target', 'value' => count($below), 'format' => 'int'],
			['label' => 'Devices without ping data', 'value' => $no_data, 'format' => 'int']
		]);

		$list = $o['show'] === 'below' ? $below : $per_host;
		uasort($list, static fn($a, $b) => [$a['availability'] ?? -1, $a['host']] <=> [$b['availability'] ?? -1, $b['host']]);

		$result->table('Devices', [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'availability', 'label' => 'Availability', 'format' => 'pct3'],
			['key' => 'downtime', 'label' => 'Estimated unreachable time', 'format' => 'duration'],
			['key' => 'coverage', 'label' => 'Hours with data', 'format' => 'pct1']
		], array_values($list), [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Availability',
			'empty' => sprintf('Every measured device met the %s%% target.', self::num($target))
		]);

		if ($o['no_data'] === 'zero' && $no_data > 0) {
			$result->note(sprintf('%d device(s) had no ping data and are counted as 0%% available.', $no_data));
		}

		$result->note('Measured from hourly ICMP ping trends. Maintenance windows are included; hours without data are excluded rather than counted as down.');

		return $result;
	}

	private static function num(float $v): string {
		return rtrim(rtrim(number_format($v, 3), '0'), '.');
	}
}

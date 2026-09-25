<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Format;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\Trends;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * How long a metric spent above a line, whether or not a trigger ever fired. Useful for
 * the conversation about a link that is fine on average and saturated every afternoon.
 */
final class ThresholdBreaches extends AbstractSection {

	public function type(): string {
		return 'threshold_breaches';
	}

	public function label(): string {
		return 'Time above threshold';
	}

	public function description(): string {
		return 'Hours each device spent above a chosen value during the period, from hourly trends.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'item_tags', 'label' => 'Item tags', 'type' => 'tags', 'default' => 'component=cpu'],
			['name' => 'keys', 'label' => 'Item key patterns', 'type' => 'text', 'default' => ''],
			['name' => 'names', 'label' => 'Item name patterns', 'type' => 'text', 'default' => ''],
			['name' => 'threshold', 'label' => 'Threshold', 'type' => 'float', 'default' => 80,
				'hint' => 'In the item\'s own units.'],
			['name' => 'basis', 'label' => 'Compare', 'type' => 'select', 'default' => 'value_avg',
				'choices' => ['value_avg' => 'The hourly average', 'value_max' => 'The hourly peak']],
			['name' => 'min_hours', 'label' => 'Only devices above it for at least (hours)', 'type' => 'int',
				'default' => 1, 'min' => 1, 'max' => 10000],
			['name' => 'units', 'label' => 'Units override', 'type' => 'text', 'default' => ''],
			['name' => 'chart', 'label' => 'Bar chart', 'type' => 'bool', 'default' => true],
			['name' => 'display_limit', 'label' => 'Rows in the PDF', 'type' => 'int', 'default' => 25, 'min' => 5,
				'max' => 500]
		], self::commonOptions(false));
	}

	public function validateOptions(array $o): array {
		return (!$o['item_tags'] && $o['keys'] === '' && $o['names'] === '')
			? ['Set item tags, a key pattern or a name pattern.']
			: [];
	}

	public function run(Context $ctx, array $o): SectionResult {
		$hosts = $this->hosts($ctx, $o);
		$items = array_filter($ctx->items(['tags' => $o['item_tags'], 'keys' => $o['keys'], 'names' => $o['names']]),
			static fn($i) => isset($hosts[$i['hostid']]));
		$result = new SectionResult();

		if (!$items) {
			return $result->text('No items on the devices in scope match this selector.');
		}

		$threshold = (float) $o['threshold'];
		$above = Trends::hoursAbove($ctx, array_keys($items), $threshold, (string) $o['basis']);
		$rows = [];
		$breached = 0;

		foreach ($above as $itemid => $a) {
			if ($a['hours'] > 0) {
				$breached++;
			}

			if ($a['hours'] < (int) $o['min_hours']) {
				continue;
			}

			$item = $items[$itemid];
			$rows[] = [
				'host' => $ctx->hostName($item['hostid']),
				'item' => $item['name'],
				'hours' => $a['hours'],
				'share' => $a['total'] > 0 ? 100 * $a['hours'] / $a['total'] : 0,
				'peak' => $a['peak'],
				'last' => $a['last_above'] ?: null,
				'units' => $o['units'] !== '' ? $o['units'] : $item['units']
			];
		}

		usort($rows, static fn($a, $b) => [$b['hours'], $b['peak']] <=> [$a['hours'], $a['peak']]);
		$label = Format::units($threshold, $rows ? (string) $rows[0]['units'] : (string) $o['units']);

		$result->kpis([
			['label' => 'Items measured', 'value' => count($above), 'format' => 'int'],
			['label' => 'Above '.$label.' at some point', 'value' => $breached, 'format' => 'int'],
			['label' => 'Listed here', 'value' => count($rows), 'format' => 'int',
				'hint' => sprintf('at least %d hour(s)', (int) $o['min_hours'])]
		]);

		if ($o['chart'] && $rows) {
			$result->chart('hbar', '', array_map(static fn($r) => ['label' => $r['host'], 'value' => $r['hours']],
				array_slice($rows, 0, 10)), ['format' => 'int']);
		}

		$result->table('Above '.$label, [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'item', 'label' => 'Item'],
			['key' => 'hours', 'label' => 'Hours above', 'format' => 'int'],
			['key' => 'share', 'label' => 'Share of the period', 'format' => 'pct1'],
			['key' => 'peak', 'label' => 'Peak', 'format' => 'units'],
			['key' => 'last', 'label' => 'Last above', 'format' => 'datetime'],
			['key' => 'units', 'label' => 'Units', 'screen' => false]
		], $rows, ['display_limit' => $o['display_limit'], 'sheet' => 'Time above threshold',
			'empty' => sprintf('Nothing was above %s for %d hour(s) or more.', $label, (int) $o['min_hours'])]);

		return $result->note(sprintf('An hour counts when %s is above the threshold. Hours with no data are not counted.',
			$o['basis'] === 'value_max' ? 'the hourly peak' : 'the hourly average'));
	}
}

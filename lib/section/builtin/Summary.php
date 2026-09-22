<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Period;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\Alerts;
use Modules\Reporter\Lib\Section\AbstractSection;

final class Summary extends AbstractSection {

	public function type(): string {
		return 'summary';
	}

	public function label(): string {
		return 'Summary';
	}

	public function description(): string {
		return 'Headline numbers for the period: devices covered, problems raised and resolved, and notifications sent.';
	}

	public function options(): array {
		return [
			self::sevOption(),
			['name' => 'daily_chart', 'label' => 'Problems per day chart', 'type' => 'bool', 'default' => true],
			['name' => 'media_table', 'label' => 'Notifications by media type', 'type' => 'bool', 'default' => true]
		];
	}

	public function run(Context $ctx, array $o): SectionResult {
		$min = (int) $o['min_severity'];
		$problems = array_filter($ctx->problems(), static fn($p) => $p['severity'] >= $min);
		$alerts = $ctx->alerts();

		$resolved = 0;
		$ttr = [];
		$high = 0;

		foreach ($problems as $p) {
			if ($p['severity'] >= 4) {
				$high++;
			}

			if ($p['r_clock'] !== null && $p['r_clock'] <= $ctx->period->till) {
				$resolved++;
				$ttr[] = $p['r_clock'] - $p['clock'];
			}
		}

		$sent = 0;
		$failed = 0;
		$by_media = [];

		foreach ($alerts as $a) {
			$m = $a['mediatypeid'];
			$by_media[$m] ??= ['sent' => 0, 'failed' => 0, 'pending' => 0];

			if ($a['status'] === Alerts::SENT) {
				$sent++;
				$by_media[$m]['sent']++;
			}
			elseif ($a['status'] === Alerts::FAILED) {
				$failed++;
				$by_media[$m]['failed']++;
			}
			else {
				$by_media[$m]['pending']++;
			}
		}

		$affected = [];

		foreach ($problems as $p) {
			foreach ($p['hostids'] as $h) {
				$affected[$h] = true;
			}
		}

		$total = count($problems);
		$result = (new SectionResult())->kpis([
			['label' => 'Devices covered', 'value' => count($ctx->hosts), 'format' => 'int'],
			['label' => 'Devices with problems', 'value' => count($affected), 'format' => 'int'],
			['label' => 'Problems raised', 'value' => $total, 'format' => 'int'],
			['label' => 'High or disaster', 'value' => $high, 'format' => 'int'],
			['label' => 'Resolved in period', 'value' => $total > 0 ? 100 * $resolved / $total : null,
				'format' => 'pct', 'hint' => sprintf('%d of %d', $resolved, $total)],
			['label' => 'Median time to resolve', 'value' => $ttr ? self::median($ttr) : null, 'format' => 'duration'],
			['label' => 'Notifications sent', 'value' => $sent, 'format' => 'int',
				'hint' => $failed > 0 ? sprintf('%d failed', $failed) : '']
		]);

		if ($o['daily_chart']) {
			$starts = $ctx->period->dayStarts();
			$series = array_fill(0, 6, array_fill(0, count($starts), 0));

			foreach ($problems as $p) {
				$series[$p['severity']][Period::dayIndex($starts, $p['clock'])]++;
			}

			$labels = array_map(static fn($t) => (new \DateTimeImmutable('@'.$t))
				->setTimezone(new \DateTimeZone($ctx->timezone()))->format('j'), $starts);

			$result->chart('severity', 'Problems raised per day', ['labels' => $labels, 'series' => $series]);
		}

		if ($o['media_table'] && $by_media) {
			// Media types are visible only to Super admins; fall back to names the
			// Settings page recorded, then to the id.
			$names = array_map('strval', (array) \Modules\Reporter\Lib\Core\Config::get('media_names', []));

			foreach ($ctx->chunks(array_keys($by_media), 100) as $ids) {
				foreach ($ctx->api->call('mediatype.get', ['output' => ['mediatypeid', 'name'], 'mediatypeids' => $ids]) as $m) {
					$names[(string) $m['mediatypeid']] = (string) $m['name'];
				}
			}

			$rows = [];

			foreach ($by_media as $id => $c) {
				$rows[] = ['media' => $names[$id] ?? 'Media type '.$id] + $c;
			}

			usort($rows, static fn($a, $b) => $b['sent'] <=> $a['sent']);

			$result->table('Notifications by media type', [
				['key' => 'media', 'label' => 'Media type'],
				['key' => 'sent', 'label' => 'Sent', 'format' => 'int'],
				['key' => 'failed', 'label' => 'Failed', 'format' => 'int'],
				['key' => 'pending', 'label' => 'Not yet sent', 'format' => 'int']
			], $rows, ['sheet' => 'Notifications by media']);
		}

		if ($min > 0) {
			$result->note(sprintf('Counts include problems of severity %s and above.', self::SEVERITIES[$min]));
		}

		$result->note('Problems are counted when raised inside the period. Notifications include recovery messages.');

		return $result;
	}

	private static function median(array $values): float {
		sort($values);
		$n = count($values);
		$mid = intdiv($n, 2);

		return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
	}
}

<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\Alerts;
use Modules\Reporter\Lib\Data\Problems;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * The month-end question: for each device, how many problems did Zabbix raise, how long
 * were they open, and how many people did it notify about them.
 */
final class ProblemsByHost extends AbstractSection {

	public function type(): string {
		return 'problems_by_host';
	}

	public function label(): string {
		return 'Problems and notifications by device';
	}

	public function description(): string {
		return 'Per device: problems raised in the period by severity, time spent in a problem state, and notifications sent.';
	}

	public function options(): array {
		return array_merge([
			self::sevOption(),
			['name' => 'group_by_tag', 'label' => 'Roll up by host tag', 'type' => 'text', 'default' => '',
				'hint' => 'For example "site". Adds a summary table per tag value.'],
			['name' => 'sort', 'label' => 'Sort devices by', 'type' => 'select', 'default' => 'problems',
				'choices' => ['problems' => 'Problems', 'open_time' => 'Time in problem state',
					'notifications' => 'Notifications', 'name' => 'Device name']],
			['name' => 'include_quiet', 'label' => 'List devices with no problems', 'type' => 'bool', 'default' => false],
			['name' => 'display_limit', 'label' => 'Devices shown in the PDF', 'type' => 'int', 'default' => 50,
				'min' => 5, 'max' => 500, 'hint' => 'The spreadsheet export always has every device.'],
			['name' => 'chart', 'label' => 'Chart the top devices', 'type' => 'bool', 'default' => true],
			['name' => 'count', 'label' => 'Count', 'type' => 'select', 'default' => 'all',
				'choices' => ['all' => 'Every problem raised', 'resolved' => 'Only problems that were resolved']],
			['name' => 'show_severity_columns', 'label' => 'Severity counts in the spreadsheet', 'type' => 'bool',
				'default' => true],
			['name' => 'show_mttr', 'label' => 'Mean time to resolve column', 'type' => 'bool', 'default' => true],
			['name' => 'show_notifications', 'label' => 'Notification columns', 'type' => 'bool', 'default' => true]
		], self::commonOptions());
	}

	public function run(Context $ctx, array $o): SectionResult {
		$from = $ctx->period->from;
		$till = $ctx->period->till;
		$min = (int) $o['min_severity'];
		$rows = [];

		foreach ($this->hosts($ctx, $o) as $hostid => $host) {
			$rows[$hostid] = [
				'host' => $host['name'],
				'tag' => $o['group_by_tag'] !== '' ? $ctx->hostTag($hostid, $o['group_by_tag']) : '',
				'sev' => [0, 0, 0, 0, 0, 0],
				'problems' => 0,
				'open_time' => 0,
				'ttr_sum' => 0,
				'ttr_n' => 0,
				'notifications' => 0,
				'failed' => 0,
				'change' => null
			];
		}

		foreach ($this->problems($ctx, $o) as $p) {
			$open = Problems::openSeconds($p, $from, $till);
			$resolved = $p['r_clock'] !== null && $p['r_clock'] <= $till;

			if ($o['count'] === 'resolved' && !$resolved) {
				continue;
			}

			foreach ($p['hostids'] as $h) {
				if (!isset($rows[$h])) {
					continue;
				}

				$rows[$h]['sev'][$p['severity']]++;
				$rows[$h]['problems']++;
				$rows[$h]['open_time'] += $open;

				if ($resolved) {
					$rows[$h]['ttr_sum'] += $p['r_clock'] - $p['clock'];
					$rows[$h]['ttr_n']++;
				}
			}
		}

		foreach ($this->alerts($ctx, $o) as $a) {
			foreach ($a['hostids'] as $h) {
				if (!isset($rows[$h])) {
					continue;
				}

				if ($a['status'] === Alerts::SENT) {
					$rows[$h]['notifications']++;
				}
				elseif ($a['status'] === Alerts::FAILED) {
					$rows[$h]['failed']++;
				}
			}
		}

		// Same devices over the previous period, for the change column.
		$previous = $ctx->previous();

		if ($previous !== null) {
			$before = [];

			foreach ($this->problems($ctx, $o, $previous) as $p) {
				foreach ($p['hostids'] as $h) {
					$before[$h] = ($before[$h] ?? 0) + 1;
				}
			}

			foreach ($rows as $h => &$r) {
				$r['change'] = $r['problems'] - ($before[$h] ?? 0);
			}
			unset($r);
		}

		$quiet = 0;

		foreach ($rows as $h => &$r) {
			$r['mttr'] = $r['ttr_n'] > 0 ? $r['ttr_sum'] / $r['ttr_n'] : null;

			foreach (self::SEVERITIES as $s => $name) {
				$r['sev_'.$s] = $r['sev'][$s];
			}

			if ($r['problems'] === 0 && $r['notifications'] === 0) {
				$quiet++;
			}
		}
		unset($r);

		$listed = $o['include_quiet']
			? $rows
			: array_filter($rows, static fn($r) => $r['problems'] > 0 || $r['notifications'] > 0);

		$sort = $o['sort'];
		uasort($listed, static function ($a, $b) use ($sort) {
			if ($sort === 'name') {
				return strnatcasecmp($a['host'], $b['host']);
			}

			return [$b[$sort], $b['problems'], $a['host']] <=> [$a[$sort], $a['problems'], $b['host']];
		});

		$result = new SectionResult();

		if ($o['group_by_tag'] !== '') {
			$result->table(sprintf('By %s', $o['group_by_tag']), [
				['key' => 'tag', 'label' => ucfirst($o['group_by_tag'])],
				['key' => 'devices', 'label' => 'Devices', 'format' => 'int'],
				['key' => 'affected', 'label' => 'With problems', 'format' => 'int'],
				['key' => 'sev', 'label' => 'Severity mix', 'format' => 'sevstrip', 'export' => false],
				['key' => 'problems', 'label' => 'Problems', 'format' => 'int'],
				['key' => 'open_time', 'label' => 'Time in problem state', 'format' => 'duration'],
				['key' => 'notifications', 'label' => 'Notifications', 'format' => 'int']
			], $this->rollup($rows), ['sheet' => 'By '.$o['group_by_tag']]);
		}

		if ($o['chart']) {
			$top = array_slice(array_filter($listed, static fn($r) => $r['problems'] > 0), 0, 10);

			if ($top) {
				$result->chart('hbar', 'Devices with the most problems', array_map(static fn($r) => [
					'label' => $r['host'], 'value' => $r['problems'], 'sev' => $r['sev']
				], array_values($top)), ['format' => 'int']);
			}
		}

		$columns = [['key' => 'host', 'label' => 'Device']];

		if ($o['group_by_tag'] !== '') {
			$columns[] = ['key' => 'tag', 'label' => ucfirst($o['group_by_tag'])];
		}

		$columns[] = ['key' => 'sev', 'label' => 'Severity mix', 'format' => 'sevstrip', 'export' => false];

		if ($o['show_severity_columns']) {
			foreach (self::SEVERITIES as $s => $name) {
				if ($s >= $min) {
					$columns[] = ['key' => 'sev_'.$s, 'label' => $name, 'format' => 'int', 'screen' => false];
				}
			}
		}

		$columns[] = ['key' => 'problems', 'label' => 'Problems', 'format' => 'int'];

		if ($previous !== null) {
			$columns[] = ['key' => 'change', 'label' => 'Change', 'format' => 'delta'];
		}

		$columns[] = ['key' => 'open_time', 'label' => 'Time in problem state', 'format' => 'duration'];

		if ($o['show_mttr']) {
			$columns[] = ['key' => 'mttr', 'label' => 'Mean time to resolve', 'format' => 'duration'];
		}

		if ($o['show_notifications']) {
			$columns[] = ['key' => 'notifications', 'label' => 'Notifications', 'format' => 'int'];
			$columns[] = ['key' => 'failed', 'label' => 'Failed', 'format' => 'int'];
		}

		$result->table('Devices', $columns, array_values($listed), [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Problems by device',
			'empty' => 'No device raised a problem or sent a notification in this period.'
		]);

		if (!$o['include_quiet'] && $quiet > 0) {
			$result->note(sprintf('%d device(s) had no problems and sent no notifications and are not listed.', $quiet));
		}

		if ($o['count'] === 'resolved') {
			$result->note('Only problems that were resolved inside the period are counted.');
		}

		$result->note('Time in problem state is clipped to the period; problems still open at the end count up to the period end.');

		return $result;
	}

	private function rollup(array $rows): array {
		$groups = [];

		foreach ($rows as $r) {
			$key = $r['tag'] !== '' ? $r['tag'] : '(no tag)';
			$g = &$groups[$key];
			$g ??= ['tag' => $key, 'devices' => 0, 'affected' => 0, 'sev' => [0, 0, 0, 0, 0, 0], 'problems' => 0,
				'open_time' => 0, 'notifications' => 0];
			$g['devices']++;
			$g['affected'] += $r['problems'] > 0 ? 1 : 0;
			$g['problems'] += $r['problems'];
			$g['open_time'] += $r['open_time'];
			$g['notifications'] += $r['notifications'];

			foreach ($r['sev'] as $s => $n) {
				$g['sev'][$s] += $n;
			}

			unset($g);
		}

		uasort($groups, static fn($a, $b) => [$b['problems'], $a['tag']] <=> [$a['problems'], $b['tag']]);

		return array_values($groups);
	}
}

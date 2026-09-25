<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * How long problems waited before someone acknowledged them, and how long until they
 * were resolved. The acknowledgement side is what an SLA conversation usually turns on,
 * and it comes from the problem update log rather than from anything this module invents.
 */
final class ResponseTimes extends AbstractSection {

	/** Bit set on a problem update that acknowledges it. */
	private const ACTION_ACKNOWLEDGE = 2;

	public function type(): string {
		return 'response_times';
	}

	public function label(): string {
		return 'Response times';
	}

	public function description(): string {
		return 'Time to acknowledge and time to resolve, from the problem update log.';
	}

	public function options(): array {
		return array_merge([
			self::sevOption('min_severity', 'Minimum severity'),
			['name' => 'group_by', 'label' => 'Break down by', 'type' => 'select', 'default' => 'severity',
				'choices' => ['severity' => 'Severity', 'host' => 'Device', 'tag' => 'A host tag']],
			['name' => 'tag', 'label' => 'Host tag', 'type' => 'text', 'default' => '',
				'hint' => 'Used when breaking down by a host tag, for example "site".'],
			['name' => 'slowest', 'label' => 'Also list the slowest', 'type' => 'int', 'default' => 10, 'min' => 0,
				'max' => 200, 'hint' => '0 leaves the list out.'],
			['name' => 'display_limit', 'label' => 'Rows in the PDF', 'type' => 'int', 'default' => 25, 'min' => 5,
				'max' => 500]
		], self::commonOptions());
	}

	public function run(Context $ctx, array $o): SectionResult {
		$problems = $this->problems($ctx, $o);
		$till = $ctx->period->till;
		$acked_at = $this->acknowledgedAt($ctx, array_keys($problems));

		$groups = [];
		$slowest = [];
		$unacked = 0;
		$all_ack = [];
		$all_res = [];

		foreach ($problems as $id => $p) {
			$hostid = $p['hostids'][0] ?? null;

			switch ($o['group_by']) {
				case 'host':
					$key = $hostid !== null ? $ctx->hostName($hostid) : 'Unknown';
					break;

				case 'tag':
					$key = $hostid !== null && $o['tag'] !== '' ? $ctx->hostTag($hostid, (string) $o['tag']) : '';
					$key = $key !== '' ? $key : '(no tag)';
					break;

				default:
					$key = self::SEVERITIES[$p['severity']];
			}

			$g = &$groups[$key];
			$g ??= ['group' => $key, 'problems' => 0, 'acknowledged' => 0, 'ack_sum' => 0, 'res_sum' => 0, 'resolved' => 0];
			$g['problems']++;

			$ack = isset($acked_at[$id]) ? $acked_at[$id] - $p['clock'] : null;

			if ($ack !== null) {
				$g['acknowledged']++;
				$g['ack_sum'] += $ack;
				$all_ack[] = $ack;
			}
			else {
				$unacked++;
			}

			if ($p['r_clock'] !== null && $p['r_clock'] <= $till) {
				$g['resolved']++;
				$g['res_sum'] += $p['r_clock'] - $p['clock'];
				$all_res[] = $p['r_clock'] - $p['clock'];
			}

			unset($g);

			$slowest[] = [
				'host' => $hostid !== null ? $ctx->hostName($hostid) : '',
				'name' => $p['name'],
				'severity' => $p['severity'],
				'start' => $p['clock'],
				'ack' => $ack,
				'resolve' => $p['r_clock'] !== null && $p['r_clock'] <= $till ? $p['r_clock'] - $p['clock'] : null
			];
		}

		$rows = [];

		foreach ($groups as $g) {
			$rows[] = [
				'group' => $g['group'],
				'problems' => $g['problems'],
				'acknowledged' => $g['problems'] > 0 ? 100 * $g['acknowledged'] / $g['problems'] : null,
				'mtta' => $g['acknowledged'] > 0 ? $g['ack_sum'] / $g['acknowledged'] : null,
				'mttr' => $g['resolved'] > 0 ? $g['res_sum'] / $g['resolved'] : null
			];
		}

		usort($rows, static fn($a, $b) => $b['problems'] <=> $a['problems']);

		$result = (new SectionResult())->kpis([
			['label' => 'Problems', 'value' => count($problems), 'format' => 'int'],
			['label' => 'Acknowledged', 'value' => $problems ? 100 * (count($problems) - $unacked) / count($problems) : null,
				'format' => 'pct1', 'hint' => sprintf('%d never acknowledged', $unacked)],
			['label' => 'Median time to acknowledge', 'value' => self::median($all_ack), 'format' => 'duration'],
			['label' => 'Median time to resolve', 'value' => self::median($all_res), 'format' => 'duration']
		]);

		$label = ['severity' => 'Severity', 'host' => 'Device'][$o['group_by']] ?? ucfirst((string) $o['tag']);
		$result->table('By '.mb_strtolower($label), [
			['key' => 'group', 'label' => $label],
			['key' => 'problems', 'label' => 'Problems', 'format' => 'int'],
			['key' => 'acknowledged', 'label' => 'Acknowledged', 'format' => 'pct1'],
			['key' => 'mtta', 'label' => 'Mean time to acknowledge', 'format' => 'duration'],
			['key' => 'mttr', 'label' => 'Mean time to resolve', 'format' => 'duration']
		], $rows, ['display_limit' => $o['display_limit'], 'sheet' => 'Response times',
			'empty' => 'No problems were raised in this period.']);

		if ($o['slowest'] > 0) {
			usort($slowest, static fn($a, $b) => ($b['ack'] ?? PHP_INT_MAX) <=> ($a['ack'] ?? PHP_INT_MAX));
			$result->table('Slowest to acknowledge', [
				['key' => 'start', 'label' => 'Started', 'format' => 'datetime'],
				['key' => 'host', 'label' => 'Device'],
				['key' => 'name', 'label' => 'Problem'],
				['key' => 'severity', 'label' => 'Severity', 'format' => 'severity'],
				['key' => 'ack', 'label' => 'Time to acknowledge', 'format' => 'duration'],
				['key' => 'resolve', 'label' => 'Time to resolve', 'format' => 'duration']
			], array_slice($slowest, 0, (int) $o['slowest']), ['display_limit' => $o['display_limit'],
				'sheet' => 'Slowest to acknowledge']);
		}

		return $result->note('Time to acknowledge is measured to the first acknowledgement in the problem update log. Problems nobody acknowledged are left out of that average and counted separately.');
	}

	/** @return array<string, int> eventid => clock of the first acknowledgement */
	private function acknowledgedAt(Context $ctx, array $eventids): array {
		$out = [];

		foreach ($ctx->chunks($eventids, $ctx->limit('chunk_ids', 1000)) as $chunk) {
			foreach ($ctx->api->call('event.get', [
				'output' => ['eventid'],
				'eventids' => $chunk,
				'selectAcknowledges' => ['clock', 'action']
			]) as $event) {
				foreach ($event['acknowledges'] ?? [] as $update) {
					if (((int) $update['action'] & self::ACTION_ACKNOWLEDGE) === 0) {
						continue;
					}

					$id = (string) $event['eventid'];
					$clock = (int) $update['clock'];
					$out[$id] = isset($out[$id]) ? min($out[$id], $clock) : $clock;
				}
			}
		}

		return $out;
	}

	private static function median(array $values): ?float {
		if (!$values) {
			return null;
		}

		sort($values);
		$n = count($values);
		$mid = intdiv($n, 2);

		return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
	}
}

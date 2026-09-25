<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\Alerts;
use Modules\Reporter\Lib\Data\Problems;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * Every problem in the period, one row each. Usually the appendix: a handful of rows in
 * the PDF and the full list in the spreadsheet, so "what exactly happened on the 14th"
 * has an answer.
 */
final class ProblemLog extends AbstractSection {

	public function type(): string {
		return 'problem_log';
	}

	public function label(): string {
		return 'Problem log';
	}

	public function description(): string {
		return 'Every problem raised in the period, with when it started, how long it lasted and how many notifications went out.';
	}

	public function options(): array {
		return array_merge([
			self::sevOption(),
			['name' => 'sort', 'label' => 'Order', 'type' => 'select', 'default' => 'start',
				'choices' => ['start' => 'When it started', 'duration' => 'Longest first',
					'severity' => 'Most severe first']],
			['name' => 'open_only', 'label' => 'Only problems still open at the end of the period', 'type' => 'bool',
				'default' => false],
			['name' => 'display_limit', 'label' => 'Rows in the PDF', 'type' => 'int', 'default' => 25, 'min' => 5,
				'max' => 1000, 'hint' => 'The spreadsheet always has every row.']
		], self::commonOptions());
	}

	public function run(Context $ctx, array $o): SectionResult {
		$till = $ctx->period->till;
		$notifications = [];

		foreach ($this->alerts($ctx, $o) as $a) {
			if ($a['status'] === Alerts::SENT) {
				$notifications[$a['eventid']] = ($notifications[$a['eventid']] ?? 0) + 1;
			}
		}

		$rows = [];
		$open = 0;

		foreach ($this->problems($ctx, $o) as $p) {
			$resolved = $p['r_clock'] !== null && $p['r_clock'] <= $till;

			if (!$resolved) {
				$open++;
			}

			if ($o['open_only'] && $resolved) {
				continue;
			}

			$rows[] = [
				'host' => implode(', ', array_map(fn($h) => $ctx->hostName($h), $p['hostids'])),
				'name' => $p['name'],
				'severity' => $p['severity'],
				'start' => $p['clock'],
				'end' => $resolved ? $p['r_clock'] : null,
				'duration' => Problems::openSeconds($p, $ctx->period->from, $till),
				'status' => $resolved ? 'Resolved' : 'Still open',
				'notifications' => $notifications[$p['eventid']] ?? 0
			];
		}

		usort($rows, static function ($a, $b) use ($o) {
			switch ($o['sort']) {
				case 'duration': return $b['duration'] <=> $a['duration'];
				case 'severity': return [$b['severity'], $b['duration']] <=> [$a['severity'], $a['duration']];
				default: return $a['start'] <=> $b['start'];
			}
		});

		$result = (new SectionResult())->table('Problems', [
			['key' => 'start', 'label' => 'Started', 'format' => 'datetime'],
			['key' => 'host', 'label' => 'Device'],
			['key' => 'name', 'label' => 'Problem'],
			['key' => 'severity', 'label' => 'Severity', 'format' => 'severity'],
			['key' => 'duration', 'label' => 'Duration', 'format' => 'duration'],
			['key' => 'status', 'label' => 'Status'],
			['key' => 'end', 'label' => 'Resolved', 'format' => 'datetime', 'screen' => false],
			['key' => 'notifications', 'label' => 'Notifications', 'format' => 'int']
		], $rows, [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Problem log',
			'empty' => 'No problems were raised in this period.'
		]);

		if (!$o['open_only'] && $open > 0) {
			$result->note(sprintf('%d problem(s) were still open at the end of the period; their duration counts up to the period end.', $open));
		}

		return $result;
	}
}

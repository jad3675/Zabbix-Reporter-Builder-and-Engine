<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use Modules\Reporter\Lib\Core\Context;

/**
 * Messages Zabbix sent (or tried to send) during the period for trigger events on hosts
 * in scope. Recovery messages are included: they are notifications a person received.
 *
 * Each alert: alertid, eventid, clock, status (1 sent, 2 failed, 0/3 pending),
 * mediatypeid, hostids.
 */
final class Alerts {

	public const SENT = 1;
	public const FAILED = 2;

	public static function fetch(Context $ctx): array {
		$period = $ctx->period;
		$alerts = [];
		$scope = array_flip($ctx->hostids());

		foreach ($ctx->chunks($ctx->hostids(), $ctx->limit('chunk_hosts', 500)) as $hostids) {
			Paginator::byClock($ctx->api, 'alert.get', [
				'output' => ['alertid', 'eventid', 'clock', 'status', 'mediatypeid', 'alerttype'],
				'selectHosts' => ['hostid'],
				'hostids' => $hostids,
				'eventsource' => 0,
				'eventobject' => 0
			], 'alertid', $period->from, $period->till, $ctx->limit('page_limit', 10000),
				static function (array $a) use (&$alerts, $scope): void {
					$id = (string) $a['alertid'];

					// Messages only; alert.get cannot filter on alerttype, so drop
					// remote command executions here.
					if ((int) ($a['alerttype'] ?? 0) !== 0 || isset($alerts[$id])) {
						return;
					}

					$hostids = [];

					foreach ($a['hosts'] ?? [] as $h) {
						if (isset($scope[$h['hostid']])) {
							$hostids[] = (string) $h['hostid'];
						}
					}

					$alerts[$id] = [
						'alertid' => $id,
						'eventid' => (string) $a['eventid'],
						'clock' => (int) $a['clock'],
						'status' => (int) $a['status'],
						'mediatypeid' => (string) $a['mediatypeid'],
						'hostids' => $hostids
					];
				}
			);
		}

		return $alerts;
	}
}

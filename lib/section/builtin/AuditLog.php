<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Api\ApiException;
use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Filters;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\Names;
use Modules\Reporter\Lib\Data\Paginator;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * Who changed what, from Zabbix's own audit log.
 *
 * Two things make this more useful than the stock Audit log page. First, the details
 * field is flattened: a template update that touched twelve fields becomes twelve rows,
 * so a macro deleted inside a host update stops hiding behind an "Update". Second, a
 * bulk operation shares one recordsetid, so 200 hosts changed at once collapse to a
 * single row with a count instead of 200 rows of noise.
 *
 * Two limits worth knowing, both Zabbix's rather than this module's. Reading the audit
 * log needs Super admin, so a scheduled run with an ordinary API token cannot include
 * this section. And housekeeping trims the table, so the period has to be inside the
 * audit retention set under Administration > Audit log.
 */
final class AuditLog extends AbstractSection {

	private const ACTION_ADD = 0;
	private const ACTION_UPDATE = 1;
	private const ACTION_DELETE = 2;

	private const ACTIONS = [
		0 => 'Add',
		1 => 'Update',
		2 => 'Delete',
		4 => 'Logout',
		7 => 'Execute',
		8 => 'Login',
		9 => 'Failed login',
		10 => 'History clear',
		11 => 'Configuration refresh',
		12 => 'Push'
	];

	/** Resource ids as of 7.0; unknown ones are shown by number rather than guessed at. */
	private const RESOURCES = [
		0 => 'User', 3 => 'Media type', 4 => 'Host', 5 => 'Action', 6 => 'Graph', 11 => 'User group',
		13 => 'Trigger', 14 => 'Host group', 15 => 'Item', 16 => 'Image', 17 => 'Value map', 18 => 'Service',
		19 => 'Map', 22 => 'Web scenario', 23 => 'Discovery rule', 25 => 'Script', 26 => 'Proxy',
		27 => 'Maintenance', 28 => 'Regular expression', 29 => 'Macro', 30 => 'Template',
		31 => 'Trigger prototype', 32 => 'Icon map', 33 => 'Dashboard', 34 => 'Event correlation',
		35 => 'Graph prototype', 36 => 'Item prototype', 37 => 'Host prototype', 38 => 'Autoregistration',
		39 => 'Module', 40 => 'Settings', 41 => 'Housekeeping', 42 => 'Authentication',
		43 => 'Template dashboard', 44 => 'User role', 45 => 'API token', 46 => 'Scheduled report',
		47 => 'HA node', 48 => 'SLA', 49 => 'User directory', 50 => 'Template group', 51 => 'Connector',
		52 => 'LLD rule', 53 => 'History', 54 => 'MFA', 55 => 'Proxy group', 56 => 'LLD rule prototype'
	];

	/**
	 * Audit details name objects by id. The last part of the field path says what kind
	 * of object it is, so "...groups[903].groupid: 301" can be looked up and printed as
	 * a name. Paths ending in one of these are resolved.
	 */
	private const ID_FIELDS = [
		'groupid' => 'hostgroup',
		'hostid' => 'host',
		'templateid' => 'template',
		'itemid' => 'item',
		'triggerid' => 'trigger',
		'usrgrpid' => 'usergroup',
		'userid' => 'user',
		'mediatypeid' => 'mediatype',
		'proxyid' => 'proxy',
		'actionid' => 'action',
		'scriptid' => 'script',
		'maintenanceid' => 'maintenance',
		'dashboardid' => 'dashboard',
		'serviceid' => 'service'
	];

	/**
	 * Row ids of link tables. They repeat the number already in the field path's index
	 * and tell a reader nothing, so they are dropped unless asked for.
	 */
	private const INTERNAL_IDS = ['uuid', 'formulaid', 'rightid', 'hostgroupid', 'hostmacroid', 'globalmacroid', 'interfaceid', 'tagid',
		'mediaid', 'widgetid', 'widget_fieldid', 'dashboard_pageid', 'operationid', 'opmessage_grpid',
		'opmessage_usrid', 'opconditionid', 'conditionid', 'filterid', 'selementid', 'linkid', 'sysmapid',
		'item_tagid', 'functionid', 'parameterid', 'preprocessingid', 'lldmacropathid', 'overrideid', 'ugsetid'];

	/** Numeric fields that mean something other than their number. */
	private const ENUMS = [
		'permission' => [0 => 'Deny', 2 => 'Read', 3 => 'Read-write'],
		'gui_access' => [0 => 'System default', 1 => 'Internal', 2 => 'LDAP', 3 => 'Disabled'],
		'users_status' => [0 => 'Enabled', 1 => 'Disabled'],
		'severity' => [0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High',
			5 => 'Disaster'],
		'evaltype' => [0 => 'And/Or', 1 => 'And', 2 => 'Or', 3 => 'Custom expression'],
		'maintenance_type' => [0 => 'With data collection', 1 => 'No data collection'],
		'inventory_mode' => [-1 => 'Disabled', 0 => 'Manual', 1 => 'Automatic'],
		'value_type' => [0 => 'Numeric (float)', 1 => 'Character', 2 => 'Log', 3 => 'Numeric (unsigned)', 4 => 'Text',
			5 => 'Binary'],
		'active' => [0 => 'Enabled', 1 => 'Disabled'],
		'status' => [0 => 'Enabled', 1 => 'Disabled'],
		'conditiontype' => [0 => 'Host group', 1 => 'Host', 2 => 'Trigger', 3 => 'Event name',
			4 => 'Trigger severity', 6 => 'Time period', 7 => 'Discovered host IP', 8 => 'Discovered service type',
			9 => 'Discovered service port', 10 => 'Discovery status', 11 => 'Uptime or downtime',
			12 => 'Received value', 13 => 'Template', 14 => 'Problem is acknowledged', 16 => 'Problem is suppressed',
			18 => 'Discovery rule', 19 => 'Discovery check', 20 => 'Proxy', 21 => 'Discovery object',
			22 => 'Host name', 23 => 'Event type', 24 => 'Host metadata', 25 => 'Event tag',
			26 => 'Event tag value', 27 => 'Service', 28 => 'Service name'],
		'operator' => [0 => 'equals', 1 => 'does not equal', 2 => 'contains', 3 => 'does not contain', 4 => 'in',
			5 => 'is at least', 6 => 'is at most', 7 => 'not in', 8 => 'matches', 9 => 'does not match',
			10 => 'Yes', 11 => 'No', 12 => 'exists', 13 => 'does not exist'],
		'operationtype' => [0 => 'Send message', 1 => 'Run script', 2 => 'Add host', 3 => 'Remove host',
			4 => 'Add to host group', 5 => 'Remove from host group', 6 => 'Link template', 7 => 'Unlink template',
			8 => 'Enable host', 9 => 'Disable host', 10 => 'Set host inventory mode', 11 => 'Send recovery message',
			12 => 'Send update message', 13 => 'Add host tags', 14 => 'Remove host tags'],
		'eventsource' => [0 => 'Triggers', 1 => 'Discovery', 2 => 'Autoregistration', 3 => 'Services',
			4 => 'Internal']
	];

	/** Condition types whose "value" is an id rather than text. */
	private const CONDITION_ID_TYPES = [
		0 => 'hostgroup',
		1 => 'host',
		2 => 'trigger',
		13 => 'template',
		20 => 'proxy',
		27 => 'service'
	];

	/** Template, template group and template dashboard: their "groupid" is a template group. */
	private const TEMPLATE_RESOURCES = [30, 43, 50];

	/** Recordsets already emitted, reset per run so one process can run many reports. */
	private array $seen_recordsets = [];

	public function type(): string {
		return 'audit_log';
	}

	public function label(): string {
		return 'Configuration changes';
	}

	public function description(): string {
		return 'Who changed what during the period, from the Zabbix audit log, with each changed field on its own line.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'actions', 'label' => 'Include', 'type' => 'select', 'default' => 'changes',
				'choices' => [
					'changes' => 'Configuration changes (add, update, delete)',
					'deletes' => 'Deletions only, including ones inside an update',
					'all' => 'Everything, including logins and script runs'
				]],
			['name' => 'resources', 'label' => 'Only these resources', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated, e.g. Host, Template, Dashboard, Macro. Empty means all.'],
			['name' => 'users', 'label' => 'Only these users', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated name patterns. Empty means all.'],
			['name' => 'exclude_users', 'label' => 'Skip these users', 'type' => 'text', 'default' => '',
				'hint' => 'For the automation accounts, e.g. *api*, provisioning'],
			['name' => 'skip_system', 'label' => 'Skip changes made by Zabbix itself', 'type' => 'bool',
				'default' => true, 'hint' => 'Server-side housekeeping, autoregistration and discovery.'],
			['name' => 'expand_details', 'label' => 'One row per changed field', 'type' => 'bool', 'default' => true],
			['name' => 'resolve_ids', 'label' => 'Look up names for ids', 'type' => 'bool', 'default' => true,
				'hint' => 'Turns "groupid: 197" into the group\'s name. One extra query per object type.'],
			['name' => 'compose', 'label' => 'Write conditions, operations and macros as sentences', 'type' => 'bool',
				'default' => true,
				'hint' => 'Six rows describing one action condition become one line: "Event tag value: notify.class contains cpu".'],
			['name' => 'hide_internal_ids', 'label' => 'Hide internal row ids', 'type' => 'bool', 'default' => true,
				'hint' => 'Drops rightid, hostmacroid and the like, which only repeat the number already in the field path.'],
			['name' => 'max_fields', 'label' => 'Fields shown per change', 'type' => 'int', 'default' => 12,
				'min' => 1, 'max' => 200, 'hint' => 'An "Add" lists every field of the new object; this trims the tail.'],
			['name' => 'collapse_bulk', 'label' => 'Collapse bulk operations', 'type' => 'bool', 'default' => true,
				'hint' => 'One row per operation with a count, instead of one per object. Zabbix groups them by recordsetid.'],
			['name' => 'by_user_table', 'label' => 'Summary by user', 'type' => 'bool', 'default' => true],
			['name' => 'max_entries', 'label' => 'Entries to read', 'type' => 'int', 'default' => 5000, 'min' => 100,
				'max' => 100000, 'hint' => 'A busy instance logs a lot; this caps the read.'],
			['name' => 'display_limit', 'label' => 'Rows in the PDF', 'type' => 'int', 'default' => 50, 'min' => 5,
				'max' => 2000, 'hint' => 'The spreadsheet export has every row.']
		], self::commonOptions(false));
	}

	public function run(Context $ctx, array $o): SectionResult {
		$this->seen_recordsets = [];
		$entries = $this->fetch($ctx, $o);
		$result = new SectionResult();

		$names = [];

		if ($o['resolve_ids']) {
			// Live names first, then anything the audit log itself remembers about
			// objects that have since been deleted.
			$names = Names::resolve($ctx, $this->idsIn($entries));

			// Live names win; the audit log only fills in what no longer exists or was
			// renamed since.
			foreach ($this->namesFromAudit($entries) as $type => $found) {
				$names[$type] = array_filter(($names[$type] ?? []) + $found);
			}
		}
		$users = [];
		$by_user = [];
		$rows = [];
		$deletions = 0;

		foreach ($entries as $entry) {
			$user = $entry['username'] !== '' ? $entry['username'] : 'Zabbix';
			$users[$user] = true;
			$u = &$by_user[$user];
			$u ??= ['user' => $user, 'changes' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'last' => 0];
			$u['changes']++;
			$u['last'] = max($u['last'], $entry['clock']);

			switch ($entry['action']) {
				case self::ACTION_ADD: $u['added']++; break;
				case self::ACTION_UPDATE: $u['updated']++; break;
				case self::ACTION_DELETE: $u['deleted']++; break;
			}

			unset($u);

			if ($entry['action'] === self::ACTION_DELETE) {
				$deletions++;
			}

			foreach ($this->toRows($entry, $o, $names) as $row) {
				$rows[] = $row;
			}
		}

		// The object count is only known once every entry has been seen.
		foreach ($rows as &$row) {
			$row['objects'] = $this->seen_recordsets[$row['recordsetid']] ?? 1;
		}
		unset($row);

		usort($rows, static fn($a, $b) => $b['clock'] <=> $a['clock']);

		$result->kpis([
			['label' => 'Changes', 'value' => count($entries), 'format' => 'int'],
			['label' => 'People and accounts', 'value' => count($users), 'format' => 'int'],
			['label' => 'Deletions', 'value' => $deletions, 'format' => 'int'],
			['label' => 'Objects touched', 'value' => count(array_unique(array_map(
				static fn($r) => $r['resource'].':'.$r['name'], $rows))), 'format' => 'int']
		]);

		if ($o['by_user_table'] && $by_user) {
			usort($by_user, static fn($a, $b) => $b['changes'] <=> $a['changes']);
			$result->table('By user', [
				['key' => 'user', 'label' => 'User'],
				['key' => 'changes', 'label' => 'Changes', 'format' => 'int'],
				['key' => 'added', 'label' => 'Added', 'format' => 'int'],
				['key' => 'updated', 'label' => 'Updated', 'format' => 'int'],
				['key' => 'deleted', 'label' => 'Deleted', 'format' => 'int'],
				['key' => 'last', 'label' => 'Last change', 'format' => 'datetime']
			], $by_user, ['sheet' => 'Changes by user']);
		}

		$columns = [
			['key' => 'clock', 'label' => 'When', 'format' => 'datetime'],
			['key' => 'user', 'label' => 'User'],
			['key' => 'action', 'label' => 'Action'],
			['key' => 'resource', 'label' => 'Resource'],
			['key' => 'name', 'label' => 'Name'],
			['key' => 'objects', 'label' => 'Objects', 'format' => 'int']
		];

		if ($o['expand_details']) {
			$columns[] = ['key' => 'field', 'label' => 'Field'];
			$columns[] = ['key' => 'change', 'label' => 'Change'];
		}

		$columns[] = ['key' => 'ip', 'label' => 'From', 'screen' => false];
		$columns[] = ['key' => 'recordsetid', 'label' => 'Operation', 'screen' => false];

		$result->table('Changes', $columns, $rows, [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Configuration changes',
			'empty' => 'Nothing was changed in this period, as far as the audit log goes.'
		]);

		$result->note('From the Zabbix audit log, which covers the whole instance rather than this report\'s devices. Entries older than the audit housekeeping period are gone and cannot appear here.');

		if ($o['collapse_bulk']) {
			$result->note('Operations that changed several objects at once are shown once, with the number of objects.');
		}

		return $result;
	}

	/** @return array[] audit entries, newest first */
	private function fetch(Context $ctx, array $o): array {
		$params = [
			'output' => ['auditid', 'userid', 'username', 'clock', 'ip', 'action', 'resourcetype', 'resourceid',
				'resourcename', 'recordsetid', 'details']
		];

		if ($o['actions'] === 'changes' || $o['actions'] === 'deletes') {
			// Deletions inside an update are found by reading the details, so updates
			// have to come back either way.
			$params['filter'] = ['action' => [self::ACTION_ADD, self::ACTION_UPDATE, self::ACTION_DELETE]];
		}

		$resources = Filters::patterns((string) $o['resources']);

		if ($resources) {
			$ids = [];

			foreach (self::RESOURCES as $id => $name) {
				if (Filters::matches($name, $resources)) {
					$ids[] = $id;
				}
			}

			if (!$ids) {
				throw new \RuntimeException(sprintf('No audit resource matches "%s". Try names like Host, Template, Dashboard or Macro.',
					(string) $o['resources']));
			}

			$params['filter']['resourcetype'] = $ids;
		}

		$users = Filters::patterns((string) $o['users']);
		$exclude = Filters::patterns((string) $o['exclude_users']);
		$max = (int) $o['max_entries'];
		$entries = [];
		$truncated = false;

		try {
			Paginator::byClock($ctx->api, 'auditlog.get', $params, 'auditid', $ctx->period->from, $ctx->period->till,
				min($max, $ctx->limit('page_limit', 10000)),
				static function (array $e) use (&$entries, &$truncated, $users, $exclude, $o, $max): void {
					if ($truncated) {
						return;
					}

					$username = (string) $e['username'];
					$system = $username === '' || (string) $e['userid'] === '0';

					if ($o['skip_system'] && $system) {
						return;
					}

					if ($users && !Filters::matches($username, $users)) {
						return;
					}

					if ($exclude && Filters::matches($username, $exclude)) {
						return;
					}

					$action = (int) $e['action'];
					$details = json_decode((string) $e['details'], true);
					$details = is_array($details) ? $details : [];

					if ($o['actions'] === 'deletes') {
						$nested = false;

						foreach ($details as $detail) {
							$nested = $nested || (($detail[0] ?? '') === 'delete');
						}

						if ($action !== self::ACTION_DELETE && !$nested) {
							return;
						}
					}

					$entries[] = [
						'auditid' => (string) $e['auditid'],
						'username' => $username,
						'clock' => (int) $e['clock'],
						'ip' => (string) $e['ip'],
						'action' => $action,
						'resourcetype' => (int) $e['resourcetype'],
						'resourceid' => (string) $e['resourceid'],
						'resourcename' => (string) $e['resourcename'],
						'recordsetid' => (string) $e['recordsetid'],
						'details' => $details
					];

					if (count($entries) >= $max) {
						$truncated = true;
					}
				}
			);
		}
		catch (ApiException $e) {
			if (stripos($e->getMessage(), 'permission') !== false) {
				throw new \RuntimeException('Reading the audit log needs Super admin. A scheduled run uses the API token\'s user, so this section works in the browser for a Super admin but not on a schedule unless that token belongs to one.');
			}

			throw $e;
		}

		if ($truncated) {
			$ctx->warn(sprintf('Stopped after %d audit entries; the period holds more.', $max));
		}

		return $entries;
	}

	/**
	 * One row per entry, or one per changed field when details are expanded. Bulk
	 * operations are collapsed onto the first entry of their recordset.
	 *
	 * @return array[]
	 */
	private function toRows(array $entry, array $o, array $names): array {
		$recordset = $entry['recordsetid'];

		if ($o['collapse_bulk'] && $recordset !== '') {
			if (isset($this->seen_recordsets[$recordset])) {
				$this->seen_recordsets[$recordset]++;

				return [];
			}

			$this->seen_recordsets[$recordset] = 1;
		}

		$base = [
			'clock' => $entry['clock'],
			'user' => $entry['username'] !== '' ? $entry['username'] : 'Zabbix',
			'ip' => $entry['ip'],
			'action' => self::ACTIONS[$entry['action']] ?? ('Action '.$entry['action']),
			'resource' => self::RESOURCES[$entry['resourcetype']] ?? ('Resource '.$entry['resourcetype']),
			'name' => $entry['resourcename'],
			'recordsetid' => $recordset,
			'objects' => 1,
			'field' => '',
			'change' => ''
		];

		if (!$o['expand_details'] || !$entry['details']) {
			if ($entry['details'] === []) {
				$base['change'] = ['Added', 'Updated', 'Deleted'][$entry['action']] ?? $base['action'];
			}

			return [$base];
		}

		$rows = [];
		$details = $entry['details'];
		ksort($details);
		$hidden = 0;
		$composed = $o['compose'] ? $this->compose($details, $entry, $names) : ['rows' => [], 'used' => []];

		foreach ($composed['rows'] as $line) {
			$rows[] = ['field' => $line['field'], 'change' => $line['change']] + $base;
		}

		foreach ($details as $path => $detail) {
			if (isset($composed['used'][$path])) {
				continue;
			}

			$path = (string) $path;
			$leaf = self::leaf($path);

			if ($o['hide_internal_ids'] && in_array($leaf, self::INTERNAL_IDS, true)) {
				continue;
			}

			// The object's own id, which the Name column already covers.
			if ($o['hide_internal_ids'] && substr($leaf, -2) === 'id' && substr_count($path, '.') === 1
					&& (string) ($detail[1] ?? '') === $entry['resourceid']) {
				continue;
			}

			// "usergroup.hostgroup_rights[45]: Added" with its fields listed underneath
			// says nothing the children do not.
			if (!array_key_exists(1, $detail) && self::hasChildren($path, $details)) {
				continue;
			}

			if (count($rows) >= (int) $o['max_fields']) {
				$hidden++;

				continue;
			}

			$rows[] = ['field' => $path,
				'change' => self::describe($detail, $leaf, $entry['resourcetype'], $names, $path)] + $base;
		}

		if ($hidden > 0) {
			$rows[] = ['field' => '', 'change' => sprintf('and %d more field(s)', $hidden)] + $base;
		}

		return $rows ?: [$base];
	}

	/**
	 * Zabbix writes one detail line per field, so a single action condition arrives as
	 * six of them. Where the shape is known and the whole object was added at once,
	 * write it as a sentence instead and mark its fields as used.
	 *
	 * Only four shapes are handled, all with stable field names: action conditions,
	 * action operations, macros, and permission rights. Anything else, including a
	 * partial update of one of these, falls through to the per-field rows.
	 *
	 * @return array{rows: array<int, array{field: string, change: string}>, used: array<string, bool>}
	 */
	private function compose(array $details, array $entry, array $names): array {
		$rows = [];
		$used = [];

		foreach (self::containers($details) as $container) {
			$fields = self::fieldsUnder($container, $details);

			if (!$fields || isset($used[$container])) {
				continue;
			}

			// Only compose a whole object appearing at once: a partial update is clearer
			// field by field.
			foreach ($fields as $detail) {
				if (($detail[0] ?? '') !== 'add') {
					continue 2;
				}
			}

			$values = [];

			foreach ($fields as $path => $detail) {
				if (array_key_exists(1, $detail)) {
					$values[substr($path, strlen($container) + 1)] = (string) $detail[1];
				}
			}

			$text = null;

			// Zabbix leaves out any field equal to its database default, so an "Add" of a
			// plain message operation has no operationtype, and a host group condition
			// has no conditiontype. The defaults are filled back in here.
			if (preg_match('/\.conditions\[\d+\]$/', $container)) {
				$text = self::composeCondition($values, $names);
			}
			elseif (preg_match('/\.(operations|recovery_operations|update_operations)\[\d+\]$/', $container)) {
				$text = self::composeOperation($values, $names);
			}
			elseif (preg_match('/\.macros\[\d+\]$/', $container)) {
				$text = self::composeMacro($values);
			}
			elseif (preg_match('/_rights\[\d+\]$/', $container)) {
				$text = self::composeRight($values, $names, $container);
			}

			if ($text === null) {
				continue;
			}

			$rows[] = ['field' => $container, 'change' => 'Added: '.$text];

			foreach (array_keys($fields) as $path) {
				$used[$path] = true;
			}

			$used[$container] = true;
		}

		return ['rows' => $rows, 'used' => $used];
	}

	/** Paths that look like an object: "...conditions[20]", outermost first. */
	private static function containers(array $details): array {
		$containers = [];

		foreach (array_keys($details) as $path) {
			if (preg_match('/^(.*\[\d+\])\./', (string) $path, $m)) {
				$containers[$m[1]] = substr_count($m[1], '.');
			}
		}

		asort($containers);

		return array_keys($containers);
	}

	/** Every detail under a container, including nested ones such as opmessage_grp[7]. */
	private static function fieldsUnder(string $container, array $details): array {
		$out = [];

		foreach ($details as $path => $detail) {
			if (strncmp((string) $path, $container.'.', strlen($container) + 1) === 0) {
				$out[(string) $path] = $detail;
			}
		}

		return $out;
	}

	private static function composeCondition(array $v, array $names): ?string {
		if (!$v) {
			return null;
		}

		$type = (int) ($v['conditiontype'] ?? 0);
		$label = self::ENUMS['conditiontype'][$type] ?? ('Condition type '.$type);
		$operator = self::ENUMS['operator'][(int) ($v['operator'] ?? 0)] ?? ('operator '.$v['operator']);
		$value = (string) ($v['value'] ?? '');

		if (isset(self::CONDITION_ID_TYPES[$type]) && ctype_digit($value)) {
			$named = $names[self::CONDITION_ID_TYPES[$type]][$value] ?? null;
			$value = $named !== null ? sprintf('%s (%s)', $named, $value) : $value;
		}

		if ($type === 4 && ctype_digit($value)) {
			$value = self::ENUMS['severity'][(int) $value] ?? $value;
		}

		// An event tag value condition names the tag in value2 and compares value.
		$subject = isset($v['value2']) && $v['value2'] !== '' ? (string) $v['value2'] : '';

		$sentence = $subject !== ''
			? sprintf('%s: %s %s %s', $label, $subject, $operator, $value)
			: trim(sprintf('%s %s %s', $label, $operator, $value));

		// The formula refers to conditions by letter; without it "{20} and {21}" has
		// nothing to point at.
		return self::shorten(isset($v['formulaid']) && $v['formulaid'] !== ''
			? sprintf('%s: %s', $v['formulaid'], $sentence)
			: $sentence);
	}

	private static function composeOperation(array $v, array $names): ?string {
		if (!$v) {
			return null;
		}

		$type = (int) ($v['operationtype'] ?? 0);
		$label = self::ENUMS['operationtype'][$type] ?? ('Operation type '.$type);
		$parts = [];

		foreach ($v as $field => $value) {
			if (preg_match('/usrgrpid$/', $field)) {
				$parts[] = 'user group '.self::named($names, 'usergroup', $value);
			}
			elseif (preg_match('/\buserid$/', $field)) {
				$parts[] = 'user '.self::named($names, 'user', $value);
			}
			elseif (preg_match('/groupid$/', $field)) {
				$parts[] = 'host group '.self::named($names, 'hostgroup', $value);
			}
			elseif (preg_match('/templateid$/', $field)) {
				$parts[] = 'template '.self::named($names, 'template', $value);
			}
		}

		$via = '';

		foreach ($v as $field => $value) {
			if (preg_match('/mediatypeid$/', $field) && $value !== '0') {
				$via = ' via '.self::named($names, 'mediatype', $value, false);
			}
		}

		$steps = '';

		if (isset($v['esc_step_from']) || isset($v['esc_step_to'])) {
			$from = (int) ($v['esc_step_from'] ?? 1);
			$to = (int) ($v['esc_step_to'] ?? 1);
			$steps = $from === $to
				? sprintf(', escalation step %d', $from)
				: sprintf(', escalation steps %d to %s', $from, $to === 0 ? 'the end' : (string) $to);
		}

		return self::shorten(sprintf('%s%s%s%s', $label, $parts ? ' to '.implode(', ', $parts) : '', $via, $steps));
	}

	private static function composeMacro(array $v): ?string {
		if (!isset($v['macro'])) {
			return null;
		}

		$text = $v['macro'].' = '.($v['value'] ?? '');

		return self::shorten(($v['description'] ?? '') !== ''
			? sprintf('%s (%s)', $text, $v['description'])
			: $text);
	}

	private static function composeRight(array $v, array $names, string $container): ?string {
		if (!isset($v['id']) || !isset($v['permission'])) {
			return null;
		}

		$type = strpos($container, 'templategroup_rights') !== false ? 'templategroup' : 'hostgroup';
		$permission = self::ENUMS['permission'][(int) $v['permission']] ?? $v['permission'];

		return self::shorten(sprintf('%s: %s', self::named($names, $type, $v['id']), $permission));
	}

	/** "status" means monitored on a host and enabled on most other things. */
	private static function statusLabel(int $resource, string $raw): string {
		$monitored = [4, 37];
		$enabled = [3, 5, 13, 15, 22, 23, 31, 36, 51, 52, 56];

		if (in_array($resource, $monitored, true)) {
			$labels = [0 => 'Monitored', 1 => 'Not monitored'];
		}
		elseif (in_array($resource, $enabled, true)) {
			$labels = self::ENUMS['status'];
		}
		else {
			return $raw;
		}

		return array_key_exists((int) $raw, $labels) ? sprintf('%s (%s)', $labels[(int) $raw], $raw) : $raw;
	}

	private static function named(array $names, string $type, string $id, bool $with_id = true): string {
		$fallbacks = ['hostgroup' => 'templategroup', 'templategroup' => 'hostgroup', 'host' => 'template'];
		$name = $names[$type][$id] ?? ($names[$fallbacks[$type] ?? ''][$id] ?? null);

		if ($name === null) {
			return $id;
		}

		return $with_id ? sprintf('%s (%s)', $name, $id) : $name;
	}

	/** Every id in the details, grouped by the object type its field name implies. */
	private function idsIn(array $entries): array {
		$wanted = [];

		foreach ($entries as $entry) {
			foreach ($entry['details'] as $path => $detail) {
				$type = self::typeOf(self::leaf((string) $path), (string) $path, $entry['resourcetype']);

				if ($type === null) {
					continue;
				}

				foreach ([1, 2] as $i) {
					if (array_key_exists($i, $detail) && ctype_digit((string) $detail[$i])) {
						$wanted[$type][] = (string) $detail[$i];
					}
				}
			}
		}

		// A condition's "value" is an id when its type says so.
		foreach ($entries as $entry) {
			foreach (self::containers($entry['details']) as $container) {
				if (!preg_match('/\.conditions\[\d+\]$/', $container)) {
					continue;
				}

				$fields = self::fieldsUnder($container, $entry['details']);
				$type = $fields[$container.'.conditiontype'][1] ?? '0';
				$value = $fields[$container.'.value'][1] ?? null;

				if ($value !== null && ctype_digit((string) $value)
						&& isset(self::CONDITION_ID_TYPES[(int) $type])) {
					$wanted[self::CONDITION_ID_TYPES[(int) $type]][] = (string) $value;
				}
			}
		}

		// A host id may belong to a template, and a group id to either kind of group.
		// Ask both and use whichever answers.
		if (isset($wanted['host'])) {
			$wanted['template'] = array_merge($wanted['template'] ?? [], $wanted['host']);
		}

		if (isset($wanted['hostgroup']) || isset($wanted['templategroup'])) {
			$groups = array_merge($wanted['hostgroup'] ?? [], $wanted['templategroup'] ?? []);
			$wanted['hostgroup'] = $groups;
			$wanted['templategroup'] = $groups;
		}

		return $wanted;
	}

	/**
	 * Names of objects that no longer exist, recovered from the audit log itself: a host
	 * deleted after it was added still has its name on the entry that created it.
	 *
	 * @return array<string, array<string, string>> type => id => name
	 */
	private function namesFromAudit(array $entries): array {
		$types = array_flip(self::ID_FIELDS);
		$resource_types = [4 => 'host', 30 => 'template', 14 => 'hostgroup', 50 => 'templategroup', 15 => 'item',
			13 => 'trigger', 11 => 'usergroup', 0 => 'user', 3 => 'mediatype', 26 => 'proxy', 5 => 'action',
			25 => 'script', 27 => 'maintenance', 33 => 'dashboard', 48 => 'service'];
		$out = [];
		unset($types);

		foreach ($entries as $entry) {
			$type = $resource_types[$entry['resourcetype']] ?? null;

			if ($type !== null && $entry['resourceid'] !== '' && $entry['resourcename'] !== '') {
				$out[$type][$entry['resourceid']] = $entry['resourcename'];
			}
		}

		return $out;
	}

	private static function leaf(string $path): string {
		$parts = explode('.', $path);
		$leaf = (string) end($parts);

		return (string) preg_replace('/\[[^\]]*\]$/', '', $leaf);
	}

	/** Which object type an id field points at, including the "id" used in rights rows. */
	private static function typeOf(string $leaf, string $path, int $resource = -1): ?string {
		if ($leaf === 'id') {
			if (strpos($path, 'templategroup_rights') !== false) {
				return 'templategroup';
			}

			return strpos($path, 'hostgroup_rights') !== false ? 'hostgroup' : null;
		}

		if ($leaf === 'groupid') {
			return in_array($resource, self::TEMPLATE_RESOURCES, true) ? 'templategroup' : 'hostgroup';
		}

		return self::ID_FIELDS[$leaf] ?? null;
	}

	private static function hasChildren(string $path, array $details): bool {
		foreach (array_keys($details) as $other) {
			if ($other !== $path && strncmp((string) $other, $path.'.', strlen($path) + 1) === 0) {
				return true;
			}
		}

		return false;
	}

	/** ["update", new, old] / ["add", value] / ["delete"], as Zabbix writes them. */
	private static function describe(array $detail, string $leaf = '', int $resource = -1, array $names = [],
			string $path = ''): string {
		$what = (string) ($detail[0] ?? '');
		$raw_new = array_key_exists(1, $detail) ? (string) $detail[1] : null;
		$raw_old = array_key_exists(2, $detail) ? (string) $detail[2] : null;
		$new = $raw_new === null ? null : self::value($raw_new, $leaf, $resource, $names, $path);
		$old = $raw_old === null ? null : self::value($raw_old, $leaf, $resource, $names, $path);

		switch ($what) {
			case 'add':
				return $new === null ? 'Added' : 'Added: '.$new;

			case 'delete':
				return 'Deleted';

			case 'update':
				if ($new === null) {
					return 'Updated';
				}

				// Diff the raw values: comparing the shortened ones would compare two
				// identical truncations of a long text.
				return self::difference((string) $raw_old, (string) $raw_new, $old ?? '', $new);

			default:
				return $what;
		}
	}

	/** A name instead of an id, or a label instead of a code, with the raw value kept. */
	private static function value(string $raw, string $leaf, int $resource, array $names, string $path = ''): string {
		$type = self::typeOf($leaf, $path, $resource);

		if ($type !== null && ctype_digit($raw)) {
			$candidates = [$type];

			if ($type === 'host') {
				$candidates[] = 'template';
			}
			elseif ($type === 'hostgroup') {
				$candidates[] = 'templategroup';
			}
			elseif ($type === 'templategroup') {
				$candidates[] = 'hostgroup';
			}

			foreach ($candidates as $candidate) {
				if (isset($names[$candidate][$raw])) {
					return sprintf('%s (%s)', self::shorten($names[$candidate][$raw]), $raw);
				}
			}
		}

		if (array_key_exists($leaf, self::ENUMS) && is_numeric($raw)) {
			$labels = self::ENUMS[$leaf];

			if ($leaf === 'status') {
				return self::statusLabel($resource, $raw);
			}

			if (array_key_exists((int) $raw, $labels)) {
				return sprintf('%s (%s)', $labels[(int) $raw], $raw);
			}
		}

		return self::shorten($raw);
	}

	/**
	 * Two long strings that differ in the middle are unreadable side by side once both
	 * are truncated, so trim the part they have in common and show only what moved.
	 */
	private static function difference(string $old, string $new, string $old_label, string $new_label): string {
		if ($old === '') {
			return 'Set to '.$new_label;
		}

		if (mb_strlen($old) <= 60 && mb_strlen($new) <= 60) {
			return sprintf('%s to %s', $old_label, $new_label);
		}

		$prefix = 0;
		$max = min(mb_strlen($old), mb_strlen($new));

		while ($prefix < $max && mb_substr($old, $prefix, 1) === mb_substr($new, $prefix, 1)) {
			$prefix++;
		}

		$suffix = 0;

		while ($suffix < $max - $prefix
				&& mb_substr($old, -$suffix - 1, 1) === mb_substr($new, -$suffix - 1, 1)) {
			$suffix++;
		}

		$old_part = mb_substr($old, $prefix, mb_strlen($old) - $prefix - $suffix);
		$new_part = mb_substr($new, $prefix, mb_strlen($new) - $prefix - $suffix);

		$old_part = trim($old_part);
		$new_part = trim($new_part);

		if ($old_part === '' && $new_part === '') {
			return 'Updated, no visible difference';
		}

		if ($old_part === '') {
			return sprintf('added "%s" inside a longer text', self::shorten($new_part));
		}

		if ($new_part === '') {
			return sprintf('removed "%s" from a longer text', self::shorten($old_part));
		}

		return sprintf('"%s" became "%s" inside a longer text', self::shorten($old_part), self::shorten($new_part));
	}

	private static function shorten(string $value): string {
		$value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

		return mb_strlen($value) > 120 ? mb_substr($value, 0, 117).'…' : $value;
	}
}

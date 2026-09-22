<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

use Modules\Reporter\Lib\Section\Registry;

/**
 * A report definition is plain JSON. This class is the only way one gets in: it drops
 * unknown keys, clamps every number, validates every enum, and runs each section's own
 * option normalizer. Whatever the editor, a hand-edited file or an import sends, what is
 * stored and what is run is always this shape.
 */
final class Definition {

	public const MAX_SECTIONS = 20;

	public static function blank(): array {
		return [
			'id' => '',
			'name' => '',
			'description' => '',
			'timezone' => date_default_timezone_get(),
			'period' => ['type' => 'previous_month', 'n' => 30],
			'scope' => ['groups' => [], 'host_tags' => [], 'tag_logic' => 'and'],
			'branding' => ['title' => 'Monthly service report', 'customer' => '', 'accent' => '#1f5f8b',
				'logo' => '', 'paper' => 'Letter', 'footer' => ''],
			'sections' => [
				['type' => 'summary', 'title' => '', 'options' => []],
				['type' => 'problems_by_host', 'title' => '', 'options' => []]
			],
			'schedule' => ['enabled' => false, 'cycle' => 'monthly', 'day' => 1, 'hour' => 6],
			'delivery' => ['formats' => ['pdf', 'xlsx'], 'email_to' => [], 'email_subject' => '', 'email_body' => '']
		];
	}

	/**
	 * @return array{0: array, 1: string[]}  normalized definition and errors
	 */
	public static function normalize($input, Registry $registry): array {
		$errors = [];
		$in = is_array($input) ? $input : [];
		$blank = self::blank();
		$def = [];

		$def['id'] = strtolower(trim((string) ($in['id'] ?? '')));

		if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $def['id'])) {
			$errors[] = 'ID must be 1 to 64 characters: lowercase letters, digits and hyphens, starting with a letter or digit.';
		}

		$def['name'] = mb_substr(trim((string) ($in['name'] ?? '')), 0, 128);

		if ($def['name'] === '') {
			$errors[] = 'Name is required.';
		}

		$def['description'] = mb_substr(trim((string) ($in['description'] ?? '')), 0, 1000);

		$tz = (string) ($in['timezone'] ?? $blank['timezone']);
		$def['timezone'] = in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';

		// Period.
		$period = is_array($in['period'] ?? null) ? $in['period'] : [];
		$type = (string) ($period['type'] ?? 'previous_month');
		$def['period'] = [
			'type' => array_key_exists($type, Period::TYPES) ? $type : 'previous_month',
			'n' => max(1, min(400, (int) ($period['n'] ?? 30)))
		];

		// Scope.
		$scope = is_array($in['scope'] ?? null) ? $in['scope'] : [];
		$groups = $scope['groups'] ?? [];

		if (is_string($groups)) {
			$groups = preg_split('/\r\n|\r|\n/', $groups);
		}

		$def['scope'] = [
			'groups' => array_slice(array_values(array_unique(array_filter(array_map(
				static fn($g) => mb_substr(trim((string) $g), 0, 255), is_array($groups) ? $groups : []
			), 'strlen'))), 0, 100),
			'host_tags' => is_string($scope['host_tags'] ?? null)
				? TagFilter::parse($scope['host_tags'])
				: TagFilter::normalize($scope['host_tags'] ?? []),
			'tag_logic' => ($scope['tag_logic'] ?? 'and') === 'or' ? 'or' : 'and'
		];

		if (!$def['scope']['groups'] && !$def['scope']['host_tags']) {
			$errors[] = 'Scope needs at least one host group or host tag. A report over "everything" is refused on purpose.';
		}

		// Branding.
		$b = is_array($in['branding'] ?? null) ? $in['branding'] : [];
		$accent = (string) ($b['accent'] ?? $blank['branding']['accent']);
		$logo = basename((string) ($b['logo'] ?? ''));
		$def['branding'] = [
			'title' => mb_substr(trim((string) ($b['title'] ?? $blank['branding']['title'])), 0, 128),
			'customer' => mb_substr(trim((string) ($b['customer'] ?? '')), 0, 128),
			'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : $blank['branding']['accent'],
			'logo' => preg_match('/^[A-Za-z0-9._-]{1,128}\.(png|jpe?g|svg)$/i', $logo) ? $logo : '',
			'paper' => in_array($b['paper'] ?? '', ['Letter', 'A4'], true) ? $b['paper'] : 'Letter',
			'footer' => mb_substr(trim((string) ($b['footer'] ?? '')), 0, 200)
		];

		// Sections.
		$def['sections'] = [];
		$sections = is_array($in['sections'] ?? null) ? array_values($in['sections']) : [];

		if (!$sections) {
			$errors[] = 'Add at least one section.';
		}

		if (count($sections) > self::MAX_SECTIONS) {
			$errors[] = sprintf('A report can have at most %d sections.', self::MAX_SECTIONS);
			$sections = array_slice($sections, 0, self::MAX_SECTIONS);
		}

		foreach ($sections as $i => $section) {
			$stype = is_array($section) ? (string) ($section['type'] ?? '') : '';

			if (!$registry->has($stype)) {
				$errors[] = sprintf('Section %d: unknown type "%s".', $i + 1, $stype);

				continue;
			}

			$impl = $registry->get($stype);
			$options = $impl->normalizeOptions(is_array($section['options'] ?? null) ? $section['options'] : []);

			foreach ($impl->validateOptions($options) as $error) {
				$errors[] = sprintf('Section %d (%s): %s', $i + 1, $impl->label(), $error);
			}

			$def['sections'][] = [
				'type' => $stype,
				'title' => mb_substr(trim((string) ($section['title'] ?? '')), 0, 128),
				'options' => $options
			];
		}

		// Schedule.
		$s = is_array($in['schedule'] ?? null) ? $in['schedule'] : [];
		$cycle = in_array($s['cycle'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $s['cycle'] : 'monthly';
		$def['schedule'] = [
			'enabled' => !empty($s['enabled']),
			'cycle' => $cycle,
			'day' => $cycle === 'weekly'
				? max(1, min(7, (int) ($s['day'] ?? 1)))
				: max(1, min(28, (int) ($s['day'] ?? 1))),
			'hour' => max(0, min(23, (int) ($s['hour'] ?? 6)))
		];

		// Delivery.
		$d = is_array($in['delivery'] ?? null) ? $in['delivery'] : [];
		$formats = array_values(array_intersect(['pdf', 'xlsx', 'csv'], (array) ($d['formats'] ?? ['pdf'])));
		$emails = $d['email_to'] ?? [];

		if (is_string($emails)) {
			$emails = preg_split('/[\s,;]+/', $emails);
		}

		$valid_emails = [];

		foreach ((array) $emails as $email) {
			$email = trim((string) $email);

			if ($email === '') {
				continue;
			}

			if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				$errors[] = sprintf('"%s" is not a valid email address.', $email);
			}
			else {
				$valid_emails[] = $email;
			}
		}

		$def['delivery'] = [
			'formats' => $formats ?: ['pdf'],
			'email_to' => array_slice(array_values(array_unique($valid_emails)), 0, 50),
			'email_subject' => mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($d['email_subject'] ?? ''))), 0, 200),
			'email_body' => mb_substr((string) ($d['email_body'] ?? ''), 0, 4000)
		];

		return [$def, $errors];
	}

	public static function hash(array $def): string {
		$copy = $def;
		unset($copy['schedule'], $copy['delivery']);

		return sha1(json_encode($copy));
	}
}

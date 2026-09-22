<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * The runner's API token and a copy of the chosen Email media type's SMTP settings, in
 * <data_dir>/secrets.json, readable by the web server user only.
 *
 * Same trust model as Zabbix itself: media type passwords sit in the Zabbix database,
 * whose credentials the web server already holds. The page writes these but never shows
 * them again.
 */
final class Secrets {

	private static function file(): string {
		return Config::dataDir().'/secrets.json';
	}

	public static function all(): array {
		$file = self::file();
		$data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];

		return is_array($data) ? $data : [];
	}

	private static function write(array $data): void {
		Config::writeFile(self::file(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", 0600);
	}

	public static function token(): string {
		return (string) (self::all()['api_token'] ?? '');
	}

	public static function setToken(?string $token, string $who): void {
		$data = self::all();

		if ($token === null) {
			unset($data['api_token'], $data['api_token_set']);
		}
		else {
			$data['api_token'] = $token;
			$data['api_token_set'] = ['by' => $who, 'at' => time()];
		}

		self::write($data);
	}

	/** @return array|null  the copied media type, including its password */
	public static function smtp(): ?array {
		$smtp = self::all()['smtp'] ?? null;

		return is_array($smtp) ? $smtp : null;
	}

	public static function setSmtp(?array $mediatype, string $who): void {
		$data = self::all();

		if ($mediatype === null) {
			unset($data['smtp']);
		}
		else {
			$data['smtp'] = self::copyOf($mediatype) + ['copied_by' => $who, 'copied_at' => time()];
		}

		self::write($data);
	}

	/** The fields the mailer needs, from a mediatype.get row. */
	public static function copyOf(array $mediatype): array {
		return [
				'mediatypeid' => (string) $mediatype['mediatypeid'],
				'name' => (string) $mediatype['name'],
				'server' => (string) $mediatype['smtp_server'],
				'port' => (int) $mediatype['smtp_port'],
				'helo' => (string) $mediatype['smtp_helo'],
				'email' => (string) $mediatype['smtp_email'],
				'security' => (int) $mediatype['smtp_security'],
				'verify_peer' => (int) $mediatype['smtp_verify_peer'] === 1,
				'verify_host' => (int) $mediatype['smtp_verify_host'] === 1,
				'authentication' => (int) $mediatype['smtp_authentication'],
				'username' => (string) $mediatype['username'],
				'passwd' => (string) $mediatype['passwd'],
				'fingerprint' => self::fingerprint($mediatype)
		];
	}

	/** Detects a media type that changed after it was copied. */
	public static function fingerprint(array $m): string {
		$fields = ['smtp_server', 'smtp_port', 'smtp_helo', 'smtp_email', 'smtp_security', 'smtp_verify_peer',
			'smtp_verify_host', 'smtp_authentication', 'username', 'passwd'];

		return hash('sha256', json_encode(array_map(static fn($f) => (string) ($m[$f] ?? ''), $fields)));
	}

	/** What the Settings page may show. No secret values. */
	public static function status(): array {
		$data = self::all();
		$smtp = $data['smtp'] ?? null;

		return [
			'token_set' => ($data['api_token'] ?? '') !== '',
			'token_by' => $data['api_token_set']['by'] ?? '',
			'token_at' => $data['api_token_set']['at'] ?? null,
			'smtp' => is_array($smtp) ? [
				'mediatypeid' => $smtp['mediatypeid'],
				'name' => $smtp['name'],
				'server' => $smtp['server'],
				'port' => $smtp['port'],
				'email' => $smtp['email'],
				'fingerprint' => $smtp['fingerprint'],
				'copied_by' => $smtp['copied_by'],
				'copied_at' => $smtp['copied_at']
			] : null
		];
	}
}

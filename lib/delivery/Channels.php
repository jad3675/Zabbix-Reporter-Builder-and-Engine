<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Delivery;

use Modules\Reporter\Lib\Api\JsonRpcApi;
use Modules\Reporter\Lib\Core\Secrets;
use Modules\Reporter\Lib\Core\Settings;

/**
 * Builds the runner's API client and mailer from Settings and Secrets. Used by the CLI
 * runner and by the Settings page's test buttons, so a passing test means the runner
 * will work too.
 */
final class Channels {

	/**
	 * Overrides let the Settings page test what is on the form before it is saved; an
	 * empty override falls back to the saved value.
	 */
	public static function api(?string $url = null, ?string $token = null, ?bool $verify_tls = null): JsonRpcApi {
		$s = Settings::load();
		$url = (string) ($url ?: (getenv('REPORTER_URL') ?: $s['runner']['api_url']));
		$token = (string) ($token ?: (getenv('REPORTER_TOKEN') ?: Secrets::token()));

		if ($url === '') {
			throw new \RuntimeException('No Zabbix URL. Set it on the Settings page.');
		}

		if ($token === '') {
			throw new \RuntimeException('No API token. Set it on the Settings page.');
		}

		return new JsonRpcApi($url, $token, $verify_tls ?? $s['runner']['verify_tls'], 30);
	}

	/** @param array|null $media_copy  from Secrets::copyOf(); null uses the saved copy */
	public static function mailer(?array $media_copy = null): Mailer {
		$smtp = $media_copy ?? Secrets::smtp();

		if ($smtp === null) {
			throw new \RuntimeException('No email media type chosen. Pick one on the Settings page.');
		}

		return new Mailer($smtp);
	}

	public static function testApi(?string $url = null, ?string $token = null, ?bool $verify_tls = null): string {
		$client = self::api($url, $token, $verify_tls);
		$groups = $client->call('hostgroup.get', ['countOutput' => true, 'with_monitored_hosts' => true]);
		$hosts = $client->call('host.get', ['countOutput' => true, 'monitored_hosts' => true]);

		return sprintf('Token accepted. It can see %d host groups with monitored hosts and %d monitored hosts.',
			(int) ($groups['count'] ?? 0), (int) ($hosts['count'] ?? 0));
	}

	public static function testMail(string $to, ?array $media_copy = null): string {
		if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
			throw new \RuntimeException('Not a valid email address.');
		}

		self::mailer($media_copy)->send([$to], 'Zabbix report builder: test message',
			"This is a test from the Zabbix report builder on ".gethostname().".\n\n"
			."If you can read it, scheduled reports can be delivered.\n", []);

		return 'Sent to '.$to.'.';
	}
}

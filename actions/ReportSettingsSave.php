<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use API;
use Modules\Reporter\Lib\Core\Secrets;
use Modules\Reporter\Lib\Core\Settings;

/**
 * AJAX (layout.json), CSRF-checked, Super admin only.
 *
 * Saving copies the chosen Email media type's SMTP settings for the runner, since the
 * runner's own token normally cannot read media types. An empty token field keeps the
 * stored token.
 */
class ReportSettingsSave extends BaseAction {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'settings' => 'required|string',
			'api_token' => 'string',
			'clear_api_token' => 'in 0,1'
		]);

		if (!$ret) {
			$this->jsonResponse(['errors' => ['The request was incomplete.']]);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->isSuperAdmin();
	}

	protected function doAction(): void {
		[$settings, $errors] = Settings::normalize(json_decode($this->getInput('settings'), true));
		$token = trim($this->getInput('api_token', ''));

		if ($token !== '' && !preg_match('/^[0-9a-f]{64}$/', $token)) {
			$errors[] = 'That does not look like a Zabbix API token (64 hexadecimal characters).';
		}

		$media = null;

		if ($settings['delivery']['mediatypeid'] !== '') {
			$found = API::MediaType()->get([
				'output' => ['mediatypeid', 'name', 'type', 'smtp_server', 'smtp_port', 'smtp_helo', 'smtp_email',
					'smtp_security', 'smtp_verify_peer', 'smtp_verify_host', 'smtp_authentication', 'username', 'passwd'],
				'mediatypeids' => [$settings['delivery']['mediatypeid']],
				'filter' => ['type' => MEDIA_TYPE_EMAIL]
			]);
			$media = is_array($found) && $found ? $found[0] : null;

			if ($media === null) {
				$errors[] = 'The chosen email media type no longer exists.';
			}
			elseif ((int) $media['smtp_authentication'] === 2) {
				$errors[] = sprintf('"%s" uses OAuth, which the report builder cannot use. Pick a media type with password or no authentication.', $media['name']);
			}
		}

		if ($errors) {
			$this->jsonResponse(['errors' => $errors]);

			return;
		}

		try {
			$names = API::MediaType()->get(['output' => ['mediatypeid', 'name']]);

			if (is_array($names)) {
				$settings['media_names'] = array_column($names, 'name', 'mediatypeid');
			}

			Settings::save($settings);
			Secrets::setSmtp($media, $this->who());

			if ($this->getInput('clear_api_token', '0') === '1') {
				Secrets::setToken(null, $this->who());
			}
			elseif ($token !== '') {
				Secrets::setToken($token, $this->who());
			}

			$this->jsonResponse(['ok' => true, 'secrets' => Secrets::status()]);
		}
		catch (\Throwable $e) {
			$this->jsonResponse(['errors' => [$e->getMessage()]]);
		}
	}
}

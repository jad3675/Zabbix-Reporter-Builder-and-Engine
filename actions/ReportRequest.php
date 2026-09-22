<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use Modules\Reporter\Lib\Core\Requests;
use Modules\Reporter\Lib\Core\Runner;
use Modules\Reporter\Lib\Delivery\Channels;

/**
 * AJAX (layout.json), CSRF-checked.
 *   test_mail, test_api   Super admin; run here and answer at once
 *   run_report            report editors; queued for the runner, which has the larger budget
 */
class ReportRequest extends BaseAction {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'type' => 'required|in test_mail,test_api,run_report',
			'to' => 'string',
			'id' => 'string',
			'url' => 'string',
			'token' => 'string',
			'verify_tls' => 'in 0,1',
			'mediatypeid' => 'string'
		]);

		if (!$ret) {
			$this->jsonResponse(['errors' => ['The request was incomplete.']]);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!parent::checkPermissions()) {
			return false;
		}

		return $this->getInput('type', '') === 'run_report' ? $this->canEdit() : $this->isSuperAdmin();
	}

	protected function doAction(): void {
		try {
			switch ($this->getInput('type')) {
				case 'test_api':
					$this->jsonResponse(['ok' => true, 'message' => $this->testApi()]);
					break;

				case 'test_mail':
					$this->jsonResponse(['ok' => true, 'message' => Channels::testMail(trim($this->getInput('to', '')),
						$this->formMedia())]);
					break;

				default:
					$id = $this->getInput('id', '');
					$this->loadDefinition($id);
					Requests::enqueue('run_report', ['id' => $id], $this->who());
					$this->jsonResponse(['ok' => true,
						'message' => 'Queued. The runner sends it within five minutes.']);
			}
		}
		catch (\Throwable $e) {
			$this->jsonResponse(['errors' => [self::explain(Runner::scrub($e->getMessage()))]]);
		}
	}

	/** Test the URL, TLS setting and token on the form; empty fields fall back to what is saved. */
	private function testApi(): string {
		$url = trim($this->getInput('url', ''));
		$token = trim($this->getInput('token', ''));

		if ($url !== '') {
			[, $errors] = \Modules\Reporter\Lib\Core\Settings::normalize(['runner' => ['api_url' => $url]]);

			if ($errors) {
				throw new \RuntimeException(implode(' ', $errors));
			}
		}

		if ($token !== '' && !preg_match('/^[0-9a-f]{64}$/', $token)) {
			throw new \RuntimeException('That does not look like a Zabbix API token (64 hexadecimal characters).');
		}

		return Channels::testApi($url ?: null, $token ?: null,
			$this->hasInput('verify_tls') ? $this->getInput('verify_tls') === '1' : null);
	}

	/** The media type selected on the form, read fresh; null means use the saved copy. */
	private function formMedia(): ?array {
		$id = $this->getInput('mediatypeid', '');

		if ($id === '') {
			return null;
		}

		$found = \API::MediaType()->get([
			'output' => ['mediatypeid', 'name', 'type', 'smtp_server', 'smtp_port', 'smtp_helo', 'smtp_email',
				'smtp_security', 'smtp_verify_peer', 'smtp_verify_host', 'smtp_authentication', 'username', 'passwd'],
			'mediatypeids' => [$id],
			'filter' => ['type' => MEDIA_TYPE_EMAIL]
		]);

		if (!is_array($found) || !$found) {
			throw new \RuntimeException('The selected email media type does not exist.');
		}

		return \Modules\Reporter\Lib\Core\Secrets::copyOf($found[0]);
	}

	/** Point at the usual culprit when the web server is not allowed to connect out. */
	private static function explain(string $message): string {
		if (stripos($message, 'Permission denied') !== false) {
			return $message.' On RHEL with SELinux, the web server may not open network connections: setsebool -P httpd_can_network_connect on. The scheduled runner is not affected.';
		}

		return $message;
	}
}

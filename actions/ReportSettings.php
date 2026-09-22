<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use API;
use CControllerResponseData;
use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\DefinitionStore;
use Modules\Reporter\Lib\Core\Requests;
use Modules\Reporter\Lib\Core\RunnerState;
use Modules\Reporter\Lib\Core\Secrets;
use Modules\Reporter\Lib\Core\Settings;
use Modules\Reporter\Lib\Render\PdfRenderer;
use Modules\Reporter\Lib\Render\XlsxWriter;

/**
 * Settings and runner status. Super admin only, whatever the module's view/edit levels.
 */
class ReportSettings extends BaseAction {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->isSuperAdmin();
	}

	protected function doAction(): void {
		$data = [
			'title' => _('Report builder settings'),
			'error' => null,
			'data_dir' => (string) Config::get('data_dir'),
			'limits_spec' => Settings::LIMITS,
			'csrf_save' => \CCsrfTokenHelper::get('reporter.settings.save'),
			'csrf_request' => \CCsrfTokenHelper::get('reporter.request'),
			'suggested_url' => self::suggestedUrl(),
			'checks' => [
				'pdf' => PdfRenderer::available(),
				'xlsx' => XlsxWriter::available(),
				'curl' => function_exists('curl_init'),
				'max_execution_time' => (int) ini_get('max_execution_time')
			]
		];

		try {
			$data['settings'] = Settings::load();
			$data['secrets'] = Secrets::status();
			$data['runner'] = RunnerState::runner();
			$data['pending'] = Requests::pending();
			$data['results'] = Requests::results();

			// Email media types, with a fingerprint so the page can tell when the copy the
			// runner uses has gone stale.
			$data['media_types'] = [];
			$media = API::MediaType()->get([
				'output' => ['mediatypeid', 'name', 'type', 'status', 'smtp_server', 'smtp_port', 'smtp_helo', 'smtp_email',
					'smtp_security', 'smtp_verify_peer', 'smtp_verify_host', 'smtp_authentication', 'username', 'passwd'],
				'filter' => ['type' => MEDIA_TYPE_EMAIL]
			]);

			foreach (is_array($media) ? $media : [] as $m) {
				$data['media_types'][] = [
					'mediatypeid' => (string) $m['mediatypeid'],
					'name' => $m['name'],
					'server' => $m['smtp_server'],
					'port' => (int) $m['smtp_port'],
					'email' => $m['smtp_email'],
					'enabled' => (int) $m['status'] === MEDIA_TYPE_STATUS_ACTIVE,
					'oauth' => (int) $m['smtp_authentication'] === 2,
					'fingerprint' => Secrets::fingerprint($m)
				];
			}

			$data['reports'] = [];

			foreach ((new DefinitionStore())->all() as $id => $raw) {
				if (!empty($raw['schedule']['enabled'])) {
					$data['reports'][] = ['id' => (string) $id, 'name' => (string) ($raw['name'] ?? $id),
						'email_to' => (array) ($raw['delivery']['email_to'] ?? [])] + RunnerState::report((string) $id);
				}
			}
		}
		catch (\Throwable $e) {
			$data['error'] = $e->getMessage();
		}

		$this->setResponse(new CControllerResponseData($data));
	}

	/** Where this frontend is, as a starting point for the runner's Zabbix URL. */
	private static function suggestedUrl(): string {
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
		$path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/zabbix.php'))), '/');

		return ($https ? 'https' : 'http').'://'.$host.$path.'/';
	}
}

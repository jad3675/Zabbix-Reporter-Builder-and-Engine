<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Delivery;

/**
 * SMTP through the bundled PHPMailer, configured from a copy of a Zabbix Email media
 * type (see Core\Secrets::setSmtp).
 */
final class Mailer {

	private array $m;

	public function __construct(array $media_copy) {
		$this->m = $media_copy;
	}

	/**
	 * @param string[] $to
	 * @param array<int, array{path: string, name: string, mime: string}> $attachments
	 */
	public function send(array $to, string $subject, string $body, array $attachments): void {
		if (!reporter_vendor() || !class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
			throw new \RuntimeException('Mail delivery needs the bundled vendor/ directory.');
		}

		$m = $this->m;

		if ((int) $m['authentication'] === 2) {
			throw new \RuntimeException(sprintf('Media type "%s" uses OAuth, which the report builder cannot use. Pick a media type with password or no authentication.', $m['name']));
		}

		$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
		$mail->isSMTP();
		$mail->Host = $m['server'];
		$mail->Port = (int) $m['port'];
		$mail->CharSet = 'UTF-8';
		$mail->Timeout = 60;

		if ($m['helo'] !== '') {
			$mail->Helo = $m['helo'];
		}

		switch ((int) $m['security']) {
			case 2:
				$mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
				break;

			case 1:
				$mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
				break;

			default:
				$mail->SMTPSecure = '';
				$mail->SMTPAutoTLS = false;
		}

		if (empty($m['verify_peer']) || empty($m['verify_host'])) {
			$mail->SMTPOptions = ['ssl' => [
				'verify_peer' => !empty($m['verify_peer']),
				'verify_peer_name' => !empty($m['verify_host']),
				'allow_self_signed' => empty($m['verify_peer'])
			]];
		}

		if ((int) $m['authentication'] === 1) {
			$mail->SMTPAuth = true;
			$mail->Username = $m['username'];
			$mail->Password = $m['passwd'];
		}

		// Zabbix allows "Display Name <address>" in the media type's email field.
		if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $m['email'], $parts)) {
			$mail->setFrom($parts[2], trim($parts[1], " \"'"));
		}
		else {
			$mail->setFrom(trim($m['email']), 'Zabbix');
		}

		foreach ($to as $address) {
			$mail->addAddress($address);
		}

		$mail->Subject = $subject;
		$mail->Body = $body;

		foreach ($attachments as $a) {
			$mail->addAttachment($a['path'], $a['name'], \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, $a['mime']);
		}

		$mail->send();
	}
}

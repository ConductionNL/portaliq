<?php

/**
 * Portaliq Form Failure Digest Mail
 *
 * Writes and sends one digest of failed form submits: the portal's own text
 * for `form-failure-digest` when it wrote one, then one line per form.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One digest mail.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class FormFailureDigestMail {
	/**
	 * The template a portal may write its own text for.
	 */
	public const TEMPLATE = 'form-failure-digest';

	/**
	 * Constructor.
	 *
	 * @param IMailer                   $mailer   Sends the mail.
	 * @param IFactory                  $l10n     The portal's language.
	 * @param LoggerInterface           $logger   Logs a mail that was not sent.
	 * @param MailTemplateRenderer|null $renderer Swaps in a portal's own text.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?MailTemplateRenderer $renderer = null,
	) {
	}//end __construct()

	/**
	 * Write and send one digest; a failure is logged without the recipient.
	 *
	 * @param string                                                          $address The address.
	 * @param array<string, mixed>                                            $portal  The portal.
	 * @param array<string, array{title: string, codes: array<int, string>}> $forms   The failed forms, per route.
	 *
	 * @return bool True when the mail server took it.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public function send(string $address, array $portal, array $forms): bool {
		$name  = trim((string)($portal['title'] ?? $portal['slug'] ?? ''));
		$count = (string)array_sum(array_map(static fn (array $form): int => count($form['codes']), $forms));
		$l     = $this->l10n->get(AppIdentity::APP_ID, (string)(((array)($portal['locales'] ?? []))[0] ?? 'nl'));
		$text  = [
			'subject' => $l->t('Form submits that failed: %1$s', [$name]),
			'body' => $l->t(
				'%1$s submits on %2$s did not reach their destination in the past 24 hours. The residents kept their answers and saw the error code.',
				[$count, $name]
			),
		];
		if ($this->renderer !== null) {
			$text = $this->renderer->render(
				portal: (string)($portal['slug'] ?? ''),
				key: self::TEMPLATE,
				values: ['portal' => $name, 'count' => $count],
				subject: $text['subject'],
				body: $text['body']
			);
		}

		try {
			$mail = $this->mailer->createEMailTemplate('portaliq.form.failuredigest', []);
			$mail->setSubject($text['subject']);
			$mail->addHeader();
			$mail->addHeading($l->t('Form submits that failed'));
			$mail->addBodyText($text['body']);
			foreach (self::lines(forms: $forms) as $line) {
				$mail->addBodyText($line);
			}

			$mail->addFooter();
			$message = $this->mailer->createMessage();
			$message->setTo([$address]);
			$message->useTemplate($mail);

			return (count($this->mailer->send($message)) === 0);
		} catch (Throwable $failure) {
			// The exception's own message is left out: a transport error may quote the recipient.
			$this->logger->warning('Portaliq: form failure digest not sent', ['exception' => get_class($failure), 'app' => AppIdentity::APP_ID]);
			return false;
		}
	}//end send()

	/**
	 * One line per form: its name, its address, how often it failed and the codes.
	 *
	 * @param array<string, array{title: string, codes: array<int, string>}> $forms Per route.
	 *
	 * @return array<int, string> The lines.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public static function lines(array $forms): array {
		$lines = [];
		foreach ($forms as $route => $form) {
			$lines[] = sprintf('%s (%s): %d × %s', $form['title'], $route, count($form['codes']), implode(', ', $form['codes']));
		}

		return $lines;
	}//end lines()
}//end class

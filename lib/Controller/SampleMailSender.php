<?php

/**
 * Portaliq Sample Mail Sender
 *
 * Sends the sample mail of a template to the admin who asked, and answers
 * how it went.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\Mail\RecipientMask;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The sending half of MailTemplateAdminController::test(), built by it for
 * each sample.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
 */
final class SampleMailSender {
	/**
	 * Constructor.
	 *
	 * @param IMailer              $mailer   Sends the sample.
	 * @param MailTemplateRenderer $renderer Fills the sample values.
	 * @param LoggerInterface      $logger   Records a sample that could not be sent.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly MailTemplateRenderer $renderer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a sample mail and answer how it went.
	 *
	 * @param SampleMail $sample The sample.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	public function send(SampleMail $sample): JSONResponse {
		try {
			$failed = $this->mailer->send($this->sampleMessage(sample: $sample));
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: test mail not sent', ['exception' => get_class($failure)]);

			return new JSONResponse(['error' => 'not_sent'], Http::STATUS_BAD_GATEWAY);
		}

		if (count($failed) > 0) {
			return new JSONResponse(['error' => 'not_sent'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['sent' => true, 'to' => (new RecipientMask())->mask(email: $sample->email)]);
	}//end send()

	/**
	 * The sample as a message to the admin's own address only.
	 *
	 * @param SampleMail $sample The sample.
	 *
	 * @return IMessage
	 */
	private function sampleMessage(SampleMail $sample): IMessage {
		$mail = $this->mailer->createEMailTemplate('portaliq.mail.test', []);
		$label = (string)MailTemplateRenderer::TEMPLATES[$sample->templateKey]['label'];
		$line = $this->renderer->preview(key: $sample->templateKey, text: $this->orElse(text: $sample->subject, fallback: $label));
		$mail->setSubject(trim((string)preg_replace('/\s+/', ' ', $line)));
		$mail->addHeader();
		$mail->addBodyText($this->renderer->preview(key: $sample->templateKey, text: $this->orElse(text: $sample->body, fallback: $label)));
		$mail->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$sample->email]);
		$message->useTemplate($mail);

		return $message;
	}//end sampleMessage()

	/**
	 * A text, or the fallback when it is empty.
	 *
	 * @param string $text     The text.
	 * @param string $fallback What to use instead.
	 *
	 * @return string
	 */
	private function orElse(string $text, string $fallback): string {
		if (trim($text) === '') {
			return $fallback;
		}

		return $text;
	}//end orElse()
}//end class

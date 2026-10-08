<?php

/**
 * Portaliq Mail Template Admin Controller (mail-templates-admin-screen)
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

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Settings\PortaliqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The admin's side of the mail templates screen: the kinds of mail, a check
 * and preview of a text, a test mail to the admin's own address, and a
 * resend of a failed mail.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
 */
class MailTemplateAdminController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest             $request  The request.
	 * @param MailTemplateRenderer $renderer The kinds of mail and their variables.
	 * @param MailLog              $mailLog  The send log.
	 * @param IMailer              $mailer   Sends the test mail.
	 * @param IUserSession         $session  Names the admin whose address gets the test mail.
	 * @param LoggerInterface      $logger   Logs a test mail that was not sent.
	 */
	public function __construct(
		IRequest $request,
		private readonly MailTemplateRenderer $renderer,
		private readonly MailLog $mailLog,
		private readonly IMailer $mailer,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The kinds of mail with their variables.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	#[AuthorizedAdminSetting(PortaliqAdmin::class)]
	public function kinds(): JSONResponse {
		$kinds = [];
		foreach (MailTemplateRenderer::TEMPLATES as $key => $kind) {
			$kinds[] = ['key' => $key, 'label' => $kind['label'], 'variables' => $kind['variables']];
		}

		return new JSONResponse(['kinds' => $kinds]);
	}//end kinds()

	/**
	 * Check a text against its kind and preview it with sample values.
	 *
	 * @param string $templateKey The kind of mail.
	 * @param string $subject     The subject line.
	 * @param string $body        The text.
	 *
	 * @return JSONResponse `ok`, the unknown variables, and the preview.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	#[AuthorizedAdminSetting(PortaliqAdmin::class)]
	public function preview(string $templateKey='', string $subject='', string $body=''): JSONResponse {
		if ($this->renderer->knows(key: $templateKey) === false) {
			return new JSONResponse(['error' => 'unknown_template'], Http::STATUS_BAD_REQUEST);
		}

		$unknown = $this->renderer->unknownVariables($templateKey, $subject, $body);

		return new JSONResponse(
			[
				'ok' => ($unknown === []),
				'unknownVariables' => $unknown,
				'subject' => trim((string)preg_replace('/\s+/', ' ', $this->renderer->preview(key: $templateKey, text: $subject))),
				'body' => $this->renderer->preview(key: $templateKey, text: $body),
			]
		);
	}//end preview()

	/**
	 * Send the sample mail of a kind to the admin's own address, and to nobody else.
	 *
	 * @param string $templateKey The kind of mail.
	 * @param string $subject     The subject line to try, or '' for the default.
	 * @param string $body        The text to try, or '' for the default.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	#[AuthorizedAdminSetting(PortaliqAdmin::class)]
	#[UserRateLimit(limit: 20, period: 60)]
	public function test(string $templateKey='', string $subject='', string $body=''): JSONResponse {
		if ($this->renderer->knows(key: $templateKey) === false) {
			return new JSONResponse(['error' => 'unknown_template'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->renderer->unknownVariables($templateKey, $subject, $body) !== []) {
			return new JSONResponse(['error' => 'unknown_variable'], Http::STATUS_BAD_REQUEST);
		}

		$email = trim((string)$this->session->getUser()?->getEMailAddress());
		if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
			return new JSONResponse(['error' => 'no_admin_address'], Http::STATUS_CONFLICT);
		}

		try {
			$mail = $this->mailer->createEMailTemplate('portaliq.mail.test', []);
			$label = (string)MailTemplateRenderer::TEMPLATES[$templateKey]['label'];
			$line = $this->renderer->preview(key: $templateKey, text: $this->orElse(text: $subject, fallback: $label));
			$mail->setSubject(trim((string)preg_replace('/\s+/', ' ', $line)));
			$mail->addHeader();
			$mail->addBodyText($this->renderer->preview(key: $templateKey, text: $this->orElse(text: $body, fallback: $label)));
			$mail->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->useTemplate($mail);
			$failed = $this->mailer->send($message);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: test mail not sent', ['exception' => get_class($failure)]);

			return new JSONResponse(['error' => 'not_sent'], Http::STATUS_BAD_GATEWAY);
		}

		if (count($failed) > 0) {
			return new JSONResponse(['error' => 'not_sent'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['sent' => true, 'to' => MailLog::mask(email: $email)]);
	}//end test()

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

	/**
	 * Send a failed mail again: queue a new row that points back at it.
	 *
	 * @param string $id The failed log row's id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	#[AuthorizedAdminSetting(PortaliqAdmin::class)]
	#[UserRateLimit(limit: 20, period: 60)]
	public function resend(string $id): JSONResponse {
		$result = $this->mailLog->queueRetry(id: $id);
		if ($result === 'not_found') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($result === 'not_failed') {
			return new JSONResponse(['error' => 'not_failed'], Http::STATUS_CONFLICT);
		}

		if ($result === 'error') {
			return new JSONResponse(['error' => 'not_queued'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['status' => 'queued'], Http::STATUS_ACCEPTED);
	}//end resend()
}//end class

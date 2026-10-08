<?php

/**
 * Tests for the mail template admin controller (mail-templates-admin-screen).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MailTemplateAdminController;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\Portaliq\Controller\MailTemplateAdminController
 */
class MailTemplateAdminControllerTest extends TestCase {
	/**
	 * A controller with the given log and mailer.
	 *
	 * @param MailLog|null $log    The log.
	 * @param IMailer|null $mailer The mailer.
	 * @param string       $email  The signed-in admin's address.
	 *
	 * @return MailTemplateAdminController
	 */
	private function controller(?MailLog $log=null, ?IMailer $mailer=null, string $email='admin@example.nl'): MailTemplateAdminController {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($email);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$mailer ??= $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);

		return new MailTemplateAdminController(
			$this->createMock(IRequest::class),
			new MailTemplateRenderer($this->createMock(PortalObjectReader::class)),
			($log ?? $this->createMock(MailLog::class)),
			$mailer,
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Every endpoint is admin-only and none opens to residents or the public.
	 *
	 * @return void
	 */
	public function testEndpointsAreAdminOnly(): void {
		foreach (['kinds', 'preview', 'test', 'resend'] as $name) {
			$method = new ReflectionMethod(MailTemplateAdminController::class, $name);
			self::assertNotEmpty($method->getAttributes(AuthorizedAdminSetting::class), $name);
			self::assertEmpty($method->getAttributes(NoAdminRequired::class), $name);
			self::assertEmpty($method->getAttributes(PublicPage::class), $name);
		}

		self::assertNotEmpty((new ReflectionMethod(MailTemplateAdminController::class, 'resend'))->getAttributes(UserRateLimit::class));
		self::assertNotEmpty((new ReflectionMethod(MailTemplateAdminController::class, 'test'))->getAttributes(UserRateLimit::class));
	}//end testEndpointsAreAdminOnly()

	/**
	 * Preview reports unknown variables and fills the sample values.
	 *
	 * @return void
	 */
	public function testPreview(): void {
		$bad = $this->controller()->preview(templateKey: 'invitation', subject: 'Hi {portal}', body: '{secret}');
		self::assertFalse($bad->getData()['ok']);
		self::assertSame(['secret'], $bad->getData()['unknownVariables']);
		self::assertSame('Hi Gemeente Voorbeeld', $bad->getData()['subject']);
		self::assertSame(400, $this->controller()->preview(templateKey: 'nope')->getStatus());
	}//end testPreview()

	/**
	 * The test mail goes to the admin's own address and nowhere else.
	 *
	 * @return void
	 */
	public function testTestMailGoesToTheAdminOnly(): void {
		$to     = [];
		$mailer = $this->createMock(IMailer::class);
		$mail   = $this->createMock(\OCP\Mail\IEMailTemplate::class);
		$msg    = $this->createMock(\OCP\Mail\IMessage::class);
		$msg->method('setTo')->willReturnCallback(
			function (array $addresses) use (&$to, $msg) {
				$to = $addresses;

				return $msg;
			}
		);
		$mailer->method('createEMailTemplate')->willReturn($mail);
		$mailer->method('createMessage')->willReturn($msg);
		$mailer->method('send')->willReturn([]);

		$response = $this->controller(mailer: $mailer)->test(templateKey: 'invitation');

		self::assertSame(['admin@example.nl'], $to);
		self::assertSame('a***@example.nl', $response->getData()['to']);
		self::assertSame(400, $this->controller()->test(templateKey: 'invitation', body: '{other}')->getStatus());
		self::assertSame(409, $this->controller(email: '')->test(templateKey: 'invitation')->getStatus());
	}//end testTestMailGoesToTheAdminOnly()

	/**
	 * Resend answers by what the log says.
	 *
	 * @return void
	 */
	public function testResend(): void {
		$log = $this->createMock(MailLog::class);
		$log->method('queueRetry')->willReturnOnConsecutiveCalls('queued', 'not_found', 'not_failed', 'error');
		$controller = $this->controller(log: $log);

		self::assertSame(202, $controller->resend(id: 'a')->getStatus());
		self::assertSame(404, $controller->resend(id: 'b')->getStatus());
		self::assertSame(409, $controller->resend(id: 'c')->getStatus());
		self::assertSame(500, $controller->resend(id: 'd')->getStatus());
	}//end testResend()
}//end class

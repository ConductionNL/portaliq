<?php

/**
 * MailTemplateAdminController::test(): every answer of a sample mail, and
 * what is put in it, in order.
 *
 * @category Tests
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MailTemplateAdminController;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The sample mail, as recorded.
 */
class MailTemplateTestMailGoldenTest extends TestCase {
	/**
	 * What the mailer doubles were asked, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A controller whose mailer records each call.
	 *
	 * @param array<string, mixed> $world sendFails (bool), failed (list), email (string), valid (bool).
	 *
	 * @return MailTemplateAdminController
	 */
	private function controller(array $world): MailTemplateAdminController {
		$this->calls = [];
		$user        = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($world['email'] ?? ' admin@example.nl ');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$template = $this->createMock(IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(function (string $subject) {
			$this->calls[] = "setSubject $subject";
		});
		$template->method('addHeader')->willReturnCallback(function () {
			$this->calls[] = 'addHeader';
		});
		$template->method('addBodyText')->willReturnCallback(function (string $text) {
			$this->calls[] = "addBodyText $text";
		});
		$template->method('addFooter')->willReturnCallback(function () {
			$this->calls[] = 'addFooter';
		});
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message) {
			$this->calls[] = 'setTo ' . implode(',', $to);
			return $message;
		});
		$message->method('useTemplate')->willReturnCallback(function () use ($message) {
			$this->calls[] = 'useTemplate';
			return $message;
		});

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(function (string $email) use ($world): bool {
			$this->calls[] = "validate $email";
			return ($world['valid'] ?? true);
		});
		$mailer->method('createEMailTemplate')->willReturnCallback(function (string $id) use ($template) {
			$this->calls[] = "createEMailTemplate $id";
			return $template;
		});
		$mailer->method('createMessage')->willReturnCallback(function () use ($message) {
			$this->calls[] = 'createMessage';
			return $message;
		});
		$mailer->method('send')->willReturnCallback(function () use ($world): array {
			$this->calls[] = 'send';
			if (($world['sendFails'] ?? false) === true) {
				throw new RuntimeException('smtp down');
			}

			return ($world['failed'] ?? []);
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context) {
			$this->calls[] = "warning $message " . json_encode($context);
		});

		return new MailTemplateAdminController(
			$this->createMock(IRequest::class),
			new MailTemplateRenderer($this->createMock(PortalObjectReader::class)),
			$this->createMock(MailLog::class),
			$mailer,
			$session,
			$logger
		);
	}//end controller()

	/**
	 * A sent sample: its subject collapsed to one line, the label standing in
	 * for an empty body, to the admin only, in this order.
	 *
	 * @return void
	 */
	public function testASentSampleIsAsRecorded(): void {
		$response = $this->controller([])->test(templateKey: 'invitation', subject: "Hallo\n  {portal}", body: '');

		$this->assertSame([200, ['sent' => true, 'to' => 'a***@example.nl']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(
			[
				'validate admin@example.nl',
				'createEMailTemplate portaliq.mail.test',
				'setSubject Hallo Gemeente Voorbeeld',
				'addHeader',
				'addBodyText Invitation',
				'addFooter',
				'createMessage',
				'setTo admin@example.nl',
				'useTemplate',
				'send',
			],
			$this->calls
		);

		$this->controller([])->test(templateKey: 'invitation', subject: '  ', body: 'Tekst {portal}');
		$this->assertSame(['setSubject Invitation', 'addBodyText Tekst Gemeente Voorbeeld'], array_values(array_filter($this->calls, static fn (string $call): bool => preg_match('/^(setSubject|addBodyText) /', $call) === 1)));
	}//end testASentSampleIsAsRecorded()

	/**
	 * Every refusal, and what was done before it.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsAsRecorded(): void {
		$unknown = $this->controller([])->test(templateKey: 'nope');
		$this->assertSame([400, ['error' => 'unknown_template']], [$unknown->getStatus(), $unknown->getData()]);
		$this->assertSame([], $this->calls);

		$variable = $this->controller([])->test(templateKey: 'invitation', subject: '{secret}');
		$this->assertSame([400, ['error' => 'unknown_variable']], [$variable->getStatus(), $variable->getData()]);
		$this->assertSame([], $this->calls);

		$invalid = $this->controller(['valid' => false])->test(templateKey: 'invitation');
		$this->assertSame([409, ['error' => 'no_admin_address']], [$invalid->getStatus(), $invalid->getData()]);
		$this->assertSame(['validate admin@example.nl'], $this->calls);

		$down = $this->controller(['sendFails' => true])->test(templateKey: 'invitation');
		$this->assertSame([502, ['error' => 'not_sent']], [$down->getStatus(), $down->getData()]);
		$this->assertSame('warning Portaliq: test mail not sent {"exception":"RuntimeException"}', end($this->calls));

		$bounced = $this->controller(['failed' => ['admin@example.nl']])->test(templateKey: 'invitation');
		$this->assertSame([502, ['error' => 'not_sent']], [$bounced->getStatus(), $bounced->getData()]);
		$this->assertSame('send', end($this->calls));
	}//end testEveryRefusalIsAsRecorded()
}//end class

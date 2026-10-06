<?php

/**
 * EmergencyPushControllerTest
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
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\EmergencyPushController;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\Notifications\PushDeliveryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */
class EmergencyPushControllerTest extends TestCase {
	use StaffActionDoubleTrait;

	public function testSendDeliversToEveryMatchingGuardianAndReportsTheCount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-directie-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('guardiansMatching')->willReturn(['guardian-1', 'guardian-2']);

		$delivery = $this->createMock(PushDeliveryService::class);
		$delivery->expects($this->exactly(2))->method('deliver')->with(
			$this->logicalOr('guardian-1', 'guardian-2'),
			'Alarm',
			'Evacuate',
			true
		)->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('info')->with(
			'Portaliq: emergency push sent',
			['sentBy' => 'staff-directie-1', 'recipientCount' => 2, 'deliveredCount' => 2]
		);

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audienceReader, $delivery, $logger, $this->staffActionAuth(EmergencyPushController::ACTION));
		$response = $controller->send(['schoolRef' => 'school-de-regenboog'], 'Alarm', 'Evacuate');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $response->getData()['recipientCount']);
		$this->assertSame(2, $response->getData()['deliveredCount']);
	}//end testSendDeliversToEveryMatchingGuardianAndReportsTheCount()

	/**
	 * A push the transport could not deliver is never reported or logged as
	 * sent: the interim logging transport delivers nothing.
	 *
	 * @return void
	 */
	public function testAnUndeliveredPushIsNotReportedAsSent(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-directie-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('guardiansMatching')->willReturn(['guardian-1', 'guardian-2', 'guardian-3']);

		$delivery = $this->createMock(PushDeliveryService::class);
		// One delivery throws, one arrives, one answers false: the fan-out
		// goes on past the throw and counts only the one that arrived.
		$delivery->expects($this->exactly(3))->method('deliver')->willReturnCallback(
			static fn (string $subjectRef): bool => match ($subjectRef) {
				'guardian-1' => throw new \RuntimeException('transport down'),
				'guardian-2' => true,
				default => false,
			}
		);

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audienceReader, $delivery, $logger, $this->staffActionAuth(EmergencyPushController::ACTION));
		$response = $controller->send(['schoolRef' => 'school-de-regenboog'], 'Alarm', 'Evacuate');

		$this->assertSame(3, $response->getData()['recipientCount']);
		$this->assertSame(1, $response->getData()['deliveredCount']);
		$this->assertSame(
			[
				['Portaliq: emergency push delivery failed', ['reason' => 'transport down']],
				['Portaliq: emergency push not delivered to every recipient', ['sentBy' => 'staff-directie-1', 'recipientCount' => 3, 'deliveredCount' => 1]],
			],
			$warnings
		);
	}//end testAnUndeliveredPushIsNotReportedAsSent()

	public function testSendReportsZeroForAnEmptyResolvedAudience(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-directie-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('guardiansMatching')->willReturn([]);

		$delivery = $this->createMock(PushDeliveryService::class);
		$delivery->expects($this->never())->method('deliver');

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audienceReader, $delivery, $this->createMock(LoggerInterface::class), $this->staffActionAuth(EmergencyPushController::ACTION));
		$response = $controller->send(['groupRefs' => ['groep-empty']], 'Alarm', 'Evacuate');

		$this->assertSame(0, $response->getData()['recipientCount']);
	}//end testSendReportsZeroForAnEmptyResolvedAudience()

	/**
	 * A signed-in user without portal.send-emergency-push is refused with 403
	 * and nobody is resolved or pushed to (#1094).
	 *
	 * @return void
	 */
	public function testAUserWithoutTheActionIsRefusedBeforeAnyPush(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-leerkracht-5a');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->expects($this->never())->method('guardiansMatching');

		$delivery = $this->createMock(PushDeliveryService::class);
		$delivery->expects($this->never())->method('deliver');

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audienceReader, $delivery, $this->createMock(LoggerInterface::class), $this->staffActionAuth(EmergencyPushController::ACTION, false));
		$response = $controller->send(['schoolRef' => 'school-de-regenboog'], 'Alarm', 'Evacuate');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());
	}//end testAUserWithoutTheActionIsRefusedBeforeAnyPush()

	/**
	 * Without a signed-in Nextcloud user the send is 401 and nothing is pushed.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$delivery = $this->createMock(PushDeliveryService::class);
		$delivery->expects($this->never())->method('deliver');

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $this->createMock(GuardianAudienceFixtureReader::class), $delivery, $this->createMock(LoggerInterface::class), $this->staffActionAuth(EmergencyPushController::ACTION));
		$response = $controller->send(['schoolRef' => 'school-de-regenboog'], 'Alarm', 'Evacuate');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()
}//end class

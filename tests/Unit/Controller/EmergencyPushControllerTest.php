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

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audienceReader, $delivery, $logger);
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
		$audienceReader->method('guardiansMatching')->willReturn(['guardian-1', 'guardian-2']);

		$delivery = $this->createMock(PushDeliveryService::class);
		// One delivery throws, the other answers false: the fan-out goes on.
		$delivery->expects($this->exactly(2))->method('deliver')->willReturnCallback(
			static fn (string $subjectRef): bool => ($subjectRef === 'guardian-1') ? throw new \RuntimeException('transport down') : false
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: emergency push not delivered to every recipient',
			['sentBy' => 'staff-directie-1', 'recipientCount' => 2, 'deliveredCount' => 0]
		);

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audienceReader, $delivery, $logger);
		$response = $controller->send(['schoolRef' => 'school-de-regenboog'], 'Alarm', 'Evacuate');

		$this->assertSame(2, $response->getData()['recipientCount']);
		$this->assertSame(0, $response->getData()['deliveredCount']);
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

		$controller = new EmergencyPushController($this->createMock(IRequest::class), $userSession, $audienceReader, $delivery, $this->createMock(LoggerInterface::class));
		$response = $controller->send(['groupRefs' => ['groep-empty']], 'Alarm', 'Evacuate');

		$this->assertSame(0, $response->getData()['recipientCount']);
	}//end testSendReportsZeroForAnEmptyResolvedAudience()
}//end class

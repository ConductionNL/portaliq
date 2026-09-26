<?php

/**
 * MessageStaffControllerTest
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
 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MessageStaffController;
use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
 */
class MessageStaffControllerTest extends TestCase {

	private function controller(GuardianMessagingLeafInterface $messaging, string $staffUid = 'staff-leerkracht-5a'): MessageStaffController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($staffUid);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new MessageStaffController($this->createMock(IRequest::class), $userSession, $messaging);
	}//end controller()

	public function testCreateGroupThreadRefusesAnUnauthenticatedCaller(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$controller = new MessageStaffController($this->createMock(IRequest::class), $userSession, $this->createMock(GuardianMessagingLeafInterface::class));

		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$controller->createGroupThread('groep-5a');
	}//end testCreateGroupThreadRefusesAnUnauthenticatedCaller()

	public function testCreateGroupThreadReturns403WhenRefused(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('createThread')->willReturn(null);

		$response = $this->controller($messaging)->createGroupThread('groep-4c');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCreateGroupThreadReturns403WhenRefused()

	public function testCreateGroupThreadReturnsTheIdOnSuccess(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->once())->method('createThread')->with('group', [], 'groep-5a', 'staff-leerkracht-5a')->willReturn('thread-1');

		$response = $this->controller($messaging)->createGroupThread('groep-5a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('thread-1', $response->getData()['id']);
	}//end testCreateGroupThreadReturnsTheIdOnSuccess()

	public function testMessagesReturns404WhenNotAParticipant(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('listMessages')->willReturn(null);

		$response = $this->controller($messaging)->messages('thread-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testMessagesReturns404WhenNotAParticipant()

	public function testPostUsesTheCallingStaffMembersOwnUid(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->once())->method('postMessage')->with('thread-1', 'staff-leerkracht-5a', true, 'hi')->willReturn(true);

		$response = $this->controller($messaging)->post('thread-1', 'hi');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testPostUsesTheCallingStaffMembersOwnUid()

	public function testMarkReadReturns404WhenRefused(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('markThreadRead')->willReturn(false);

		$response = $this->controller($messaging)->markRead('thread-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testMarkReadReturns404WhenRefused()
}//end class

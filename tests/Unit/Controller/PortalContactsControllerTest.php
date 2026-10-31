<?php

/**
 * Portaliq Portal Contacts Controller Test
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
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Controller\PortalContactsController;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The contacts routes answer 401 without a session and map the outcomes to statuses.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */
class PortalContactsControllerTest extends TestCase {

	/**
	 * A controller whose session resolves to the given subject.
	 *
	 * @param array<string, mixed>|null $subject The subject, or null for none.
	 * @param PortalContactService $service The service.
	 *
	 * @return PortalContactsController
	 */
	private function controller(?array $subject, PortalContactService $service): PortalContactsController {
		$request = $this->createMock(IRequest::class);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$lookup = $this->createMock(PortalAccountLookup::class);
		$lookup->method('bySubjectRef')->willReturn(['displayName' => 'Ada']);

		return new PortalContactsController($request, $session, $service, $lookup);
	}//end controller()

	/**
	 * Without a session every route answers 401 and the service is not asked.
	 *
	 * @return void
	 */
	public function testWithoutASessionNothingIsAsked(): void {
		$service = $this->createMock(PortalContactService::class);
		$service->expects($this->never())->method($this->anything());
		$controller = $this->controller(subject: null, service: $service);
		$this->assertInstanceOf(PortalProtected::class, $controller);
		foreach ([$controller->index(), $controller->invite('a@b.nl'), $controller->respond('1', true), $controller->resend('1'), $controller->withdraw('1'), $controller->remove('1'), $controller->acceptInvitation('t')] as $response) {
			$this->assertSame(401, $response->getStatus());
		}
	}//end testWithoutASessionNothingIsAsked()

	/**
	 * Outcomes become statuses, and the inviter's name comes from the account.
	 *
	 * @return void
	 */
	public function testOutcomesBecomeStatusesAndTheNameIsAdded(): void {
		$seen    = [];
		$service = $this->createMock(PortalContactService::class);
		$service->method('invite')->willReturnCallback(
			function (array $subject, string $email, string $message) use (&$seen): string {
				$seen = $subject;
				return $email === 'dup@b.nl' ? PortalContactService::DUPLICATE : ($email === 'max@b.nl' ? PortalContactService::LIMIT : PortalContactService::SENT);
			}
		);
		$controller = $this->controller(subject: ['subjectRef' => 'a', 'organisation' => 'o'], service: $service);
		$this->assertSame(200, $controller->invite('ok@b.nl')->getStatus());
		$this->assertSame('Ada', $seen['displayName']);
		$this->assertSame(409, $controller->invite('dup@b.nl')->getStatus());
		$this->assertSame(429, $controller->invite('max@b.nl')->getStatus());
	}//end testOutcomesBecomeStatusesAndTheNameIsAdded()
}//end class

<?php

/**
 * QuietHoursStaffControllerTest
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
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\QuietHoursStaffController;
use OCA\Portaliq\Service\Notifications\QuietHoursPolicy;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */
class QuietHoursStaffControllerTest extends TestCase {

	private function controller(?QuietHoursPolicy $quietHours = null, string $staffUid = 'staff-leerkracht-5a'): QuietHoursStaffController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($staffUid);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new QuietHoursStaffController($this->createMock(IRequest::class), $userSession, $quietHours ?? $this->createMock(QuietHoursPolicy::class));
	}//end controller()

	public function testUpdateRefusesAnUnauthenticatedCaller(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$controller = new QuietHoursStaffController($this->createMock(IRequest::class), $userSession, $this->createMock(QuietHoursPolicy::class));

		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$controller->update('22:00', '07:00');
	}//end testUpdateRefusesAnUnauthenticatedCaller()

	public function testIndexReturnsTheStaffMembersOwnWindow(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->expects($this->once())->method('resolveWindow')->with('staff-leerkracht-5a')->willReturn(['start' => '22:00', 'end' => '07:00']);

		$response = $this->controller($quietHours)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testIndexReturnsTheStaffMembersOwnWindow()

	public function testUpdateReturns400ForAnInvalidWindow(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->method('setWindow')->willReturn(false);

		$response = $this->controller($quietHours)->update('25:00', '07:00');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testUpdateReturns400ForAnInvalidWindow()
}//end class

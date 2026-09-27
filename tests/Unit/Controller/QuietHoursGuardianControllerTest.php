<?php

/**
 * QuietHoursGuardianControllerTest
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

use OCA\Portaliq\Controller\QuietHoursGuardianController;
use OCA\Portaliq\Service\Notifications\QuietHoursPolicy;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */
class QuietHoursGuardianControllerTest extends TestCase {

	private function controller(?array $subject, ?QuietHoursPolicy $quietHours = null): QuietHoursGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new QuietHoursGuardianController($request, $session, $quietHours ?? $this->createMock(QuietHoursPolicy::class));
	}//end controller()

	public function testIndexFailsClosedWithoutAResolvedSubject(): void {
		$response = $this->controller(null)->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testIndexFailsClosedWithoutAResolvedSubject()

	public function testIndexReturnsTheSubjectsOwnWindow(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->expects($this->once())->method('resolveWindow')->with('guardian-1')->willReturn(['start' => '22:00', 'end' => '07:00']);

		$response = $this->controller(['subjectRef' => 'guardian-1'], $quietHours)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['start' => '22:00', 'end' => '07:00'], $response->getData());
	}//end testIndexReturnsTheSubjectsOwnWindow()

	public function testUpdateReturns400ForAnInvalidWindow(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->method('setWindow')->willReturn(false);

		$response = $this->controller(['subjectRef' => 'guardian-1'], $quietHours)->update('25:00', '07:00');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testUpdateReturns400ForAnInvalidWindow()

	public function testUpdateReturns204OnSuccess(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->method('setWindow')->willReturn(true);

		$response = $this->controller(['subjectRef' => 'guardian-1'], $quietHours)->update('22:00', '07:00');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testUpdateReturns204OnSuccess()
}//end class

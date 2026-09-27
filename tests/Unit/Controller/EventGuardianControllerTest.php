<?php

/**
 * EventGuardianControllerTest
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
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\EventGuardianController;
use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\EventRsvpService;
use OCA\Portaliq\Service\EventSignupService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */
class EventGuardianControllerTest extends TestCase {

	private function controller(
		?array $subject,
		?EventFeedReader $feedReader = null,
		?EventRsvpService $rsvpService = null,
		?EventSignupService $signupService = null,
	): EventGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new EventGuardianController(
			$request,
			$session,
			$feedReader ?? $this->createMock(EventFeedReader::class),
			$rsvpService ?? $this->createMock(EventRsvpService::class),
			$signupService ?? $this->createMock(EventSignupService::class)
		);
	}//end controller()

	public function testFeedFailsClosedWithoutAResolvedSubject(): void {
		$response = $this->controller(null)->feed();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testFeedFailsClosedWithoutAResolvedSubject()

	public function testRsvpReturns404WhenTheServiceRefuses(): void {
		$rsvpService = $this->createMock(EventRsvpService::class);
		$rsvpService->method('rsvp')->willReturn(false);

		$response = $this->controller(['subjectRef' => 's1'], rsvpService: $rsvpService)->rsvp('e1', 'child-1', 'yes');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testRsvpReturns404WhenTheServiceRefuses()

	public function testRsvpReturns204OnSuccess(): void {
		$rsvpService = $this->createMock(EventRsvpService::class);
		$rsvpService->method('rsvp')->willReturn(true);

		$response = $this->controller(['subjectRef' => 's1'], rsvpService: $rsvpService)->rsvp('e1', 'child-1', 'yes');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testRsvpReturns204OnSuccess()

	public function testSignupMapsRoleFullTo422(): void {
		$signupService = $this->createMock(EventSignupService::class);
		$signupService->method('attemptSignup')->willReturn(EventSignupService::REASON_ROLE_FULL);

		$response = $this->controller(['subjectRef' => 's1'], signupService: $signupService)->signup('e1', 'begeleiding');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testSignupMapsRoleFullTo422()

	public function testSignupMapsNotFoundTo404(): void {
		$signupService = $this->createMock(EventSignupService::class);
		$signupService->method('attemptSignup')->willReturn(EventSignupService::REASON_ROLE_NOT_FOUND);

		$response = $this->controller(['subjectRef' => 's1'], signupService: $signupService)->signup('e1', 'unknown');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSignupMapsNotFoundTo404()

	public function testSignupReturns204OnSuccess(): void {
		$signupService = $this->createMock(EventSignupService::class);
		$signupService->method('attemptSignup')->willReturn(null);

		$response = $this->controller(['subjectRef' => 's1'], signupService: $signupService)->signup('e1', 'begeleiding');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testSignupReturns204OnSuccess()
}//end class

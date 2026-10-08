<?php

/**
 * Tests for PortalRegisteredDetailsController (identity-registered-details T03).
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalRegisteredDetailsController;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsLinks;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PortalRegisteredDetailsControllerTest extends TestCase {

	public function testNoBearerIsUnauthorisedAndNothingIsLookedUp(): void {
		$details = $this->double(PortalRegisteredDetailsService::class, ['forSubject']);
		$details->expects($this->never())->method('forSubject');

		$response = $this->controller(null, $details)->show();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testNoBearerIsUnauthorisedAndNothingIsLookedUp()

	public function testASuppliedIdentifierIsIgnoredAndOnlyTheBearersOwnRecordIsRead(): void {
		$details = $this->double(PortalRegisteredDetailsService::class, ['forSubject']);
		$details->expects($this->once())->method('forSubject')->with('subject-1')->willReturn(
			['available' => true, 'kind' => 'person', 'person' => ['name' => 'Jan de Vries']]
		);

		$response = $this->controller(['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x'], $details, ['bsn' => '111222333'])->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Jan de Vries', $data['person']['name']);
		$this->assertSame(['correction' => '/c', 'addressInvestigation' => null], $data['links']);

	}//end testASuppliedIdentifierIsIgnoredAndOnlyTheBearersOwnRecordIsRead()

	public function testAnUnavailableAnswerCarriesNoLinks(): void {
		$details = $this->double(PortalRegisteredDetailsService::class, ['forSubject']);
		$details->method('forSubject')->willReturn(['available' => false, 'reason' => 'source_unavailable']);

		$data = $this->controller(['subjectRef' => 'subject-1'], $details)->show()->getData();

		$this->assertSame(['available' => false, 'reason' => 'source_unavailable'], $data);

	}//end testAnUnavailableAnswerCarriesNoLinks()

	/**
	 * The controller for one subject.
	 *
	 * @param array<string, mixed>|null $subject The bearer's subject.
	 * @param mixed                     $details The details service double.
	 * @param array<string, mixed>      $params  Request parameters.
	 *
	 * @return PortalRegisteredDetailsController
	 */
	private function controller(?array $subject, mixed $details, array $params = []): PortalRegisteredDetailsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$session = $this->double(PortalSessionService::class, ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn($subject);

		$portal = ['slug' => 'mijn-gemeente'];
		$visibility = $this->double(CaseTypeVisibility::class, ['servingPortal']);
		$visibility->method('servingPortal')->willReturn($portal);

		$links = $this->double(PortalRegisteredDetailsLinks::class, ['forPortal']);
		$links->method('forPortal')->with($portal, 'person')->willReturn(['correction' => '/c', 'addressInvestigation' => null]);

		return new PortalRegisteredDetailsController($request, $session, $details, $links, $visibility);
	}//end controller()

	/**
	 * A double of one class, limited to the methods it really has.
	 *
	 * @param string             $class   The class to double.
	 * @param array<int, string> $methods The methods to stub.
	 *
	 * @return mixed
	 */
	private function double(string $class, array $methods): mixed {
		return $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods($methods)
			->getMock();
	}//end double()
}//end class

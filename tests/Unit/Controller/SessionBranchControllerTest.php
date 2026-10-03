<?php

/**
 * Tests for SessionBranchController (signin-eherkenning-branch T05).
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

use OCA\Portaliq\Controller\SessionBranchController;
use OCA\Portaliq\Service\Branch\BranchChoice;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SessionBranchControllerTest extends TestCase {

	private const SUBJECT = ['subjectRef' => 's1', 'branch' => '', 'branchRestricted' => false];

	public function testNoBearerIsUnauthorised(): void {
		$controller = $this->controller(null, allowed: true, issued: null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->branches()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->choose('000087654321')->getStatus());

	}//end testNoBearerIsUnauthorised()

	public function testTheBranchListNamesTheBranchInEffect(): void {
		$data = $this->controller(self::SUBJECT, allowed: true, issued: null)->branches()->getData();

		$this->assertSame('', $data['branch']);
		$this->assertFalse($data['restricted']);
		$this->assertSame('000087654321', $data['branches'][0]['number']);

	}//end testTheBranchListNamesTheBranchInEffect()

	public function testAForeignBranchIsRefusedAndNoBearerIsMinted(): void {
		$controller = $this->controller(self::SUBJECT, allowed: false, issued: ['token' => 'never'], expectMint: false);

		$response = $controller->choose('000099999999');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'branch_refused'], $response->getData());

	}//end testAForeignBranchIsRefusedAndNoBearerIsMinted()

	public function testAnAllowedBranchReturnsTheNewBearer(): void {
		$controller = $this->controller(self::SUBJECT, allowed: true, issued: ['token' => 't2', 'jti' => 'j2', 'expiresAt' => 10, 'hardExpiresAt' => 20, 'idleTimeout' => 900]);

		$data = $controller->choose('000087654321')->getData();

		$this->assertSame('t2', $data['token']);
		$this->assertSame('Bearer', $data['tokenType']);
		$this->assertSame('000087654321', $data['branch']);

	}//end testAnAllowedBranchReturnsTheNewBearer()

	public function testARefusedRotationIsForbidden(): void {
		$response = $this->controller(self::SUBJECT, allowed: true, issued: null)->choose('000087654321');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testARefusedRotationIsForbidden()

	/**
	 * The controller over one subject and one decision.
	 *
	 * @param array<string, mixed>|null $subject    The bearer's subject.
	 * @param bool                      $allowed    Whether BranchChoice allows the branch.
	 * @param array<string, mixed>|null $issued     What the rotation answers.
	 * @param bool                      $expectMint Whether a rotation may happen.
	 *
	 * @return SessionBranchController
	 */
	private function controller(?array $subject, bool $allowed, ?array $issued, bool $expectMint = true): SessionBranchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer t1');

		$session = $this->getMockBuilder(PortalSessionService::class)->disableOriginalConstructor()->onlyMethods(['resolveFromBearer', 'rebranchSession'])->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);
		if ($expectMint === false) {
			$session->expects($this->never())->method('rebranchSession');
		} else {
			$session->method('rebranchSession')->willReturn($issued);
		}

		$choice = $this->getMockBuilder(BranchChoice::class)->disableOriginalConstructor()->onlyMethods(['branchesFor', 'allows'])->getMock();
		$choice->method('branchesFor')->willReturn([['number' => '000087654321', 'name' => 'Korenschoof Zuid', 'address' => 'Laan 40, 3521CD Utrecht', 'main' => false]]);
		$choice->method('allows')->willReturn($allowed);

		return new SessionBranchController($request, $session, $choice);
	}//end controller()
}//end class

<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\CosignController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Intake\CosignInvitation;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Controller\CosignController
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class CosignControllerTest extends TestCase {
	/** @var PortalActionForwarder&MockObject The forwarder double. */
	private PortalActionForwarder&MockObject $forwarder;

	/** @var CosignInvitation The real token reader, with a fake secret. */
	private CosignInvitation $invitation;

	/**
	 * A controller for one signed-in visitor.
	 *
	 * @param array<string, mixed>|null $subject The session's subject, or null.
	 *
	 * @return CosignController
	 */
	private function controller(?array $subject): CosignController {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash_hmac('sha256', $message, 'secret'));
		$this->invitation = new CosignInvitation(crypto: $crypto);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'zuiddrecht']);
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('bindingFor')->willReturn(['route' => 'woo-verzoek', 'cosign' => ['required' => true, 'endpoint' => '/apps/caseapp/api/portal/obligations/settle']]);
		$bindings->method('render')->willReturn([]);
		$bindings->method('requiredTrust')->willReturn('substantial');
		$this->forwarder = $this->createMock(PortalActionForwarder::class);

		return new CosignController(
			request: $this->createMock(IRequest::class),
			invitation: $this->invitation,
			session: $session,
			portals: $portals,
			bindings: $bindings,
			forwarder: $this->forwarder,
			auditor: $this->createMock(AuditTrailService::class),
		);
	}//end controller()

	/**
	 * A token for Sanne's request, due far ahead.
	 *
	 * @param string $dueAt The last day.
	 *
	 * @return string
	 */
	private function token(string $dueAt = '2099-01-01'): string {
		return $this->invitation->token(claims: ['portal' => 'zuiddrecht', 'route' => 'woo-verzoek', 'case' => 'case-1', 'reference' => 'ZK-1', 'kind' => 'cosign', 'holderName' => 'Henk de Vries', 'dueAt' => $dueAt, 'applicant' => 'subject-sanne']);
	}//end token()

	/**
	 * Henk sees the request and his signature is forwarded to the case app.
	 *
	 * @return void
	 */
	public function testHenkCosigns(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-henk', 'trust' => 'substantial']);
		$token      = $this->token();

		$shown = $controller->show(token: $token);
		self::assertSame(200, $shown->getStatus());
		self::assertSame('ZK-1', $shown->getData()['reference']);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$this->forwarder->expects(self::once())->method('forward')->with(
			['endpoint' => '/apps/caseapp/api/portal/obligations/settle', 'method' => 'POST'],
			self::anything(),
			['case' => 'case-1', 'kind' => 'cosign', 'answer' => 'sign']
		)->willReturn($response);
		$this->forwarder->method('decodeBody')->willReturn(['state' => 'signed']);

		$answered = $controller->answer(token: $token, answer: 'sign', confirmed: 'true');
		self::assertSame(200, $answered->getStatus());
		self::assertSame('signed', $answered->getData()['state']);
	}//end testHenkCosigns()

	/**
	 * The applicant cannot co-sign their own request.
	 *
	 * @return void
	 */
	public function testCosigningYourOwnRequestIsRefused(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-sanne', 'trust' => 'substantial']);
		$this->forwarder->expects(self::never())->method('forward');

		$answered = $controller->answer(token: $this->token(), answer: 'sign', confirmed: 'true');
		self::assertSame(403, $answered->getStatus());
		self::assertSame('same_subject', $answered->getData()['error']);
	}//end testCosigningYourOwnRequestIsRefused()

	/**
	 * No session, too low a level, a passed day, an unticked box and a forged link are refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(401, $this->controller(subject: null)->show(token: $this->token())->getStatus());
		self::assertSame(403, $this->controller(subject: ['subjectRef' => 'subject-henk', 'trust' => 'low'])->show(token: $this->token())->getStatus());
		self::assertSame(410, $this->controller(subject: ['subjectRef' => 'subject-henk', 'trust' => 'substantial'])->show(token: $this->token(dueAt: '2020-01-01'))->getStatus());

		$controller = $this->controller(subject: ['subjectRef' => 'subject-henk', 'trust' => 'substantial']);
		self::assertSame(400, $controller->answer(token: $this->token(), answer: 'sign', confirmed: '')->getStatus());
		self::assertSame(400, $controller->show(token: 'forged.link')->getStatus());
	}//end testRefusals()
}//end class

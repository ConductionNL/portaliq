<?php

/**
 * Portaliq Portal Cosign Controller Test
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

use OCA\Portaliq\Controller\PortalCosignController;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Service\Intake\PortalCosign;
use OCA\Portaliq\Service\Intake\PortalCosignFlow;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * resident-identity-in-forms T04: the co-signer needs their own DigiD session
 * at level substantial, sees only a request of this portal and without its
 * portal, co-signs only after confirming, and every refusal of the core has its
 * own status.
 *
 * @covers \OCA\Portaliq\Controller\PortalCosignController
 * @uses   \OCA\Portaliq\Kernel\Support\SessionTrust
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class PortalCosignControllerTest extends TestCase {

	private const SITE = ['slug' => 'gemeente-x', 'title' => 'Gemeente X'];

	private const HENK = ['subjectRef' => 'bsn-henk', 'trust' => 'substantial'];

	private const OPENED = [
		'ok' => true,
		'reason' => '',
		'submission' => ['reference' => 'AANVRAAG-1', 'portal' => 'gemeente-x', 'route' => 'woo', 'answers' => ['onderwerp' => 'Parkeren'], 'submittedAt' => '', 'deadline' => ''],
	];

	/**
	 * The co-sign core.
	 *
	 * @var PortalCosign&MockObject
	 */
	private PortalCosign $cosign;

	/**
	 * The co-sign flow.
	 *
	 * @var PortalCosignFlow&MockObject
	 */
	private PortalCosignFlow $flow;

	protected function setUp(): void {
		$this->cosign = $this->createMock(PortalCosign::class);
		$this->flow   = $this->createMock(PortalCosignFlow::class);
	}//end setUp()

	public function testAnUnknownPortalIsNotFound(): void {
		$this->cosign->expects($this->never())->method('open');

		$response = $this->controller(site: null, subject: self::HENK)->open(code: '7RQ-K2M');

		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'portal_not_found']], [$response->getStatus(), $response->getData()]);
	}//end testAnUnknownPortalIsNotFound()

	public function testWithoutADigidSessionAtSubstantialTheCoSignerMustSignIn(): void {
		$this->cosign->expects($this->never())->method('open');
		$expected = [Http::STATUS_UNAUTHORIZED, ['error' => 'sign_in_required', 'minTrust' => 'substantial']];

		$none = $this->controller(site: self::SITE, subject: null)->open(code: '7RQ-K2M');
		$low  = $this->controller(site: self::SITE, subject: ['subjectRef' => 'bsn-henk', 'trust' => 'low'])->open(code: '7RQ-K2M');

		$this->assertSame($expected, [$none->getStatus(), $none->getData()]);
		$this->assertSame($expected, [$low->getStatus(), $low->getData()]);
	}//end testWithoutADigidSessionAtSubstantialTheCoSignerMustSignIn()

	public function testTheRequestOpensReadOnlyWithoutItsPortal(): void {
		$this->cosign->expects($this->once())->method('open')->with('7RQ-K2M', 'bsn-henk')->willReturn(self::OPENED);

		$response = $this->controller(site: self::SITE, subject: self::HENK)->open(code: '7RQ-K2M', portal: 'gemeente-x');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['submission' => ['reference' => 'AANVRAAG-1', 'route' => 'woo', 'answers' => ['onderwerp' => 'Parkeren'], 'submittedAt' => '', 'deadline' => '']],
			$response->getData()
		);
	}//end testTheRequestOpensReadOnlyWithoutItsPortal()

	public function testARequestOfAnotherPortalIsNotFound(): void {
		$this->cosign->method('open')->willReturn(self::OPENED);

		$response = $this->controller(site: ['slug' => 'gemeente-y'], subject: self::HENK)->open(code: '7RQ-K2M');

		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'unknown']], [$response->getStatus(), $response->getData()]);
	}//end testARequestOfAnotherPortalIsNotFound()

	/**
	 * The core's reasons and the status each gets.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'own request' => ['same-subject', Http::STATUS_FORBIDDEN, 'same_subject'],
			'past the deadline' => ['expired', Http::STATUS_GONE, 'expired'],
			'wrong code' => ['unknown', Http::STATUS_NOT_FOUND, 'unknown'],
			'no subject' => ['no-subject', Http::STATUS_UNAUTHORIZED, 'no_subject'],
			'store down' => ['unavailable', Http::STATUS_SERVICE_UNAVAILABLE, 'unavailable'],
		];
	}//end refusals()

	/**
	 * Each refusal keeps its reason and gets its own status.
	 *
	 * @param string $reason The core's reason.
	 * @param int    $status The status.
	 * @param string $error  The error answered.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testEachRefusalHasItsStatus(string $reason, int $status, string $error): void {
		$this->cosign->method('open')->willReturn(['ok' => false, 'reason' => $reason, 'submission' => []]);

		$response = $this->controller(site: self::SITE, subject: self::HENK)->open(code: '7RQ-K2M');

		$this->assertSame([$status, ['error' => $error]], [$response->getStatus(), $response->getData()]);
	}//end testEachRefusalHasItsStatus()

	public function testCoSigningNeedsTheConfirmation(): void {
		$this->cosign->expects($this->never())->method('open');
		$this->cosign->expects($this->never())->method('sign');

		$response = $this->controller(site: self::SITE, subject: self::HENK)->sign(code: '7RQ-K2M');

		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'not_confirmed']], [$response->getStatus(), $response->getData()]);
	}//end testCoSigningNeedsTheConfirmation()

	public function testHenkCoSignsWithHisSubjectAndAssurance(): void {
		$this->cosign->method('open')->willReturn(self::OPENED);
		$this->cosign->expects($this->once())->method('sign')->with('7RQ-K2M', 'bsn-henk', 'substantial')->willReturn(
			['ok' => true, 'reason' => '', 'reference' => 'AANVRAAG-1']
		);

		$response = $this->controller(site: self::SITE, subject: ['subjectRef' => 'bsn-henk', 'trust' => 'substantial'])->sign(code: '7RQ-K2M', confirmed: true);

		$this->assertSame([Http::STATUS_OK, ['state' => 'signed', 'reference' => 'AANVRAAG-1']], [$response->getStatus(), $response->getData()]);
	}//end testHenkCoSignsWithHisSubjectAndAssurance()

	public function testASignTheStoreCannotRecordIsUnavailable(): void {
		$this->cosign->method('open')->willReturn(self::OPENED);
		$this->cosign->method('sign')->willReturn(['ok' => false, 'reason' => 'unavailable', 'reference' => '']);

		$response = $this->controller(site: self::SITE, subject: self::HENK)->sign(code: '7RQ-K2M', confirmed: true);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testASignTheStoreCannotRecordIsUnavailable()

	public function testRefusingGoesThroughTheFlowWithThePortal(): void {
		$this->cosign->method('open')->willReturn(self::OPENED);
		$this->cosign->expects($this->never())->method('refuse');
		$this->flow->expects($this->once())->method('refuse')->with(self::SITE, '7RQ-K2M', 'bsn-henk')->willReturn(
			['ok' => true, 'reason' => '', 'reference' => 'AANVRAAG-1']
		);

		$response = $this->controller(site: self::SITE, subject: self::HENK)->refuse(code: '7RQ-K2M');

		$this->assertSame([Http::STATUS_OK, ['state' => 'refused', 'reference' => 'AANVRAAG-1']], [$response->getStatus(), $response->getData()]);
	}//end testRefusingGoesThroughTheFlowWithThePortal()

	public function testRefusingYourOwnRequestIsRefusedBeforeTheFlow(): void {
		$this->cosign->method('open')->willReturn(['ok' => false, 'reason' => 'same-subject', 'submission' => []]);
		$this->flow->expects($this->never())->method('refuse');

		$response = $this->controller(site: self::SITE, subject: ['subjectRef' => 'bsn-sanne', 'trust' => 'high'])->refuse(code: '7RQ-K2M');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testRefusingYourOwnRequestIsRefusedBeforeTheFlow()

	/**
	 * Mijn zaken: the signed-in resident gets their own held requests of this portal.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function testHeldListsTheSignedInResidentsOwnRequestsOfThisPortal(): void {
		$requests = [['reference' => 'AANVRAAG-1', 'route' => 'woo', 'submittedAt' => '', 'state' => 'awaiting-cosign', 'cosign' => ['state' => 'awaiting', 'name' => 'Henk de Vries', 'deadline' => '2026-10-22']]];
		$this->cosign->expects($this->once())->method('heldBy')->with('bsn-sanne', 'gemeente-x')->willReturn($requests);

		$response = $this->controller(site: self::SITE, subject: ['subjectRef' => 'bsn-sanne', 'trust' => 'low'])->held(portal: 'gemeente-x');

		$this->assertSame([Http::STATUS_OK, ['requests' => $requests]], [$response->getStatus(), $response->getData()]);
	}//end testHeldListsTheSignedInResidentsOwnRequestsOfThisPortal()

	/**
	 * Without a session or a portal nothing is listed.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function testHeldNeedsASessionAndAPortal(): void {
		$this->cosign->expects($this->never())->method('heldBy');

		$none   = $this->controller(site: self::SITE, subject: null)->held();
		$noSite = $this->controller(site: null, subject: self::HENK)->held(portal: 'elders');

		$this->assertSame([Http::STATUS_UNAUTHORIZED, ['authenticated' => false]], [$none->getStatus(), $none->getData()]);
		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'portal_not_found']], [$noSite->getStatus(), $noSite->getData()]);
	}//end testHeldNeedsASessionAndAPortal()

	/**
	 * The controller over the given portal and session.
	 *
	 * @param array<string, mixed>|null $site    The portal the resolver finds.
	 * @param array<string, mixed>|null $subject The session's subject.
	 *
	 * @return PortalCosignController
	 */
	private function controller(?array $site, ?array $subject): PortalCosignController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$portals = $this->createMock(PortalResolverInterface::class);
		$portals->method('resolve')->willReturn($site);
		$session = $this->createMock(PortalSessionServiceInterface::class);
		$session->method('resolveFromBearer')->with('Bearer token')->willReturn($subject);

		return new PortalCosignController(request: $request, portals: $portals, session: $session, cosign: $this->cosign, flow: $this->flow);
	}//end controller()
}//end class

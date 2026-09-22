<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-001: a melding is
 * reachable on a case number and a verified address through a one-time link,
 * a vergunning that declares `account` only never offers the route, and a
 * link that has been followed admits nobody a second time.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalReferenceLinkServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testACaseTypeDeclaringReferenceAdmitsTheRoute(): void {
		$service = $this->service();

		$this->assertTrue($service->admitsReference(caseType: ['portalIdentityKind' => ['account', 'reference']]));
		$this->assertFalse($service->admitsReference(caseType: ['portalIdentityKind' => ['account']]));
		$this->assertFalse($service->admitsReference(caseType: []));
		$this->assertFalse($service->admitsReference(caseType: ['portalIdentityKind' => 'reference']));

	}//end testACaseTypeDeclaringReferenceAdmitsTheRoute()

	public function testAVergunningRefusesTheReferenceRouteAndIssuesNoLink(): void {
		$service = $this->service();

		$issued = $service->issue(
			caseType: ['portalIdentityKind' => ['account']],
			caseReference: 'ZAAK-1',
			email: 'ans@example.org',
			organisation: 'gemeente-x'
		);

		$this->assertNull($issued);
		$this->assertSame([], $this->storedRows('portalReferenceLink'));

	}//end testAVergunningRefusesTheReferenceRouteAndIssuesNoLink()

	public function testAMeldingIsReachedOnTheLinkWithNoAccount(): void {
		$service = $this->service();
		$issued = $service->issue(caseType: $this->melding(), caseReference: 'ZAAK-1', email: 'ans@example.org', organisation: 'gemeente-x');

		$redeemed = $service->redeem(token: $issued['token']);

		$this->assertSame('ZAAK-1', $redeemed['caseReference']);
		$this->assertSame([], $this->storedRows('portalAccount'));

	}//end testAMeldingIsReachedOnTheLinkWithNoAccount()

	public function testALinkWorksOnce(): void {
		$service = $this->service();
		$issued = $service->issue(caseType: $this->melding(), caseReference: 'ZAAK-1', email: 'ans@example.org', organisation: 'gemeente-x');
		$service->redeem(token: $issued['token']);

		$this->assertNull($service->redeem(token: $issued['token']));
		$this->assertSame('used', $this->storedRows('portalReferenceLink')[0]['state']);

	}//end testALinkWorksOnce()

	public function testAnExpiredLinkAdmitsNobody(): void {
		$service = $this->service();
		$issued = $service->issue(caseType: $this->melding(), caseReference: 'ZAAK-1', email: 'ans@example.org', organisation: 'gemeente-x');

		$this->assertNull($service->redeem(token: $issued['token'], now: new DateTimeImmutable('+2 days')));

	}//end testAnExpiredLinkAdmitsNobody()

	public function testAnUnknownSecretIsRefusedLikeAUsedOne(): void {
		$service = $this->service();
		$service->issue(caseType: $this->melding(), caseReference: 'ZAAK-1', email: 'ans@example.org', organisation: 'gemeente-x');

		$this->assertNull($service->redeem(token: 'not-the-secret'));
		$this->assertNull($service->redeem(token: ''));

	}//end testAnUnknownSecretIsRefusedLikeAUsedOne()

	/**
	 * A case type admitting the reference route.
	 *
	 * @return array<string, mixed>
	 */
	private function melding(): array {
		return ['portalIdentityKind' => ['account', 'reference']];
	}//end melding()

	/**
	 * The service over the fake store.
	 *
	 * @return PortalReferenceLinkService
	 */
	private function service(): PortalReferenceLinkService {
		return new PortalReferenceLinkService($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end service()

}//end class
